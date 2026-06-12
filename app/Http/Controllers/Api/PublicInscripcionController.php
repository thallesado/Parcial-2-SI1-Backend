<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Carrera;
use App\Models\GestionAcademica;
use App\Models\Materia;
use App\Models\Postulante;
use App\Services\StripeInscripcionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Stripe\Exception\ApiErrorException;

class PublicInscripcionController extends Controller
{
    public function __construct(private readonly StripeInscripcionService $stripe)
    {
    }

    // PORTAL INSCRIPCION - catalogos publicos para el formulario externo.
    public function opciones(): JsonResponse
    {
        $gestiones = GestionAcademica::query()
            ->where('estado', 'INSCRIPCION_ABIERTA')
            ->orderByDesc('gestion_id')
            ->get();

        if ($gestiones->isEmpty()) {
            $gestiones = GestionAcademica::query()->orderByDesc('gestion_id')->limit(1)->get();
        }

        return response()->json([
            'gestiones' => $gestiones,
            'carreras' => Carrera::query()
                ->where('estado', 'ACTIVO')
                ->orderBy('nombre')
                ->get(['carrera_id', 'codigo', 'nombre']),
            'monto_inscripcion' => $this->stripe->amount(),
            'moneda_inscripcion' => strtoupper($this->stripe->currency()),
            'tiempo_pago_segundos' => $this->stripe->timeoutMinutes() * 60,
            'concepto_pago' => $this->stripe->productName(),
        ]);
    }

    // PORTAL INSCRIPCION - prepara el pago real en Stripe y guarda la solicitud temporal.
    public function preparar(Request $request): JsonResponse
    {
        $request->merge($this->trimStrings($request->all()));
        $data = $request->validate($this->rules($request), $this->messages());
        $token = Str::random(64);
        $expiresAt = now()->addMinutes($this->stripe->timeoutMinutes());
        $paymentIntent = $this->stripe->createPaymentIntent($data, $token, $expiresAt);

        try {
            DB::table('admision.inscripcion_temporal')->insert([
                'token' => $token,
                'datos' => json_encode($data),
                'estado' => 'PENDIENTE',
                'monto' => $this->stripe->amount(),
                'moneda' => strtoupper($this->stripe->currency()),
                'stripe_payment_intent_id' => $paymentIntent->id,
                'stripe_client_secret' => $paymentIntent->client_secret,
                'stripe_estado' => $paymentIntent->status,
                'expira_en' => $expiresAt,
                'creado_en' => now(),
                'actualizado_en' => now(),
            ]);
        } catch (\Throwable $exception) {
            $this->cancelStripeIntentSilently($paymentIntent->id);
            throw $exception;
        }

        return response()->json([
            'token' => $token,
            'expira_en' => $expiresAt->toISOString(),
            'monto' => $this->stripe->amount(),
            'moneda' => strtoupper($this->stripe->currency()),
            'client_secret' => $paymentIntent->client_secret,
            'payment_intent_id' => $paymentIntent->id,
        ], 201);
    }

    // PORTAL INSCRIPCION - webhook oficial de Stripe para consolidar el pago confirmado.
    public function webhook(Request $request): JsonResponse
    {
        $signature = (string) $request->header('Stripe-Signature');
        abort_unless(config('services.stripe.webhook_secret'), 500, 'Webhook de Stripe no configurado.');

        $event = $this->stripe->constructWebhookEvent(
            (string) $request->getContent(),
            $signature
        );

        $object = $event->data->object;

        if ($event->type === 'payment_intent.succeeded' && isset($object->id)) {
            $this->sincronizarPagoPorIntent($object->id);
        }

        if ($event->type === 'payment_intent.canceled' && isset($object->id)) {
            DB::table('admision.inscripcion_temporal')
                ->where('stripe_payment_intent_id', $object->id)
                ->where('estado', 'PENDIENTE')
                ->update([
                    'estado' => 'CANCELADA',
                    'stripe_estado' => $object->status ?? 'canceled',
                    'actualizado_en' => now(),
                ]);
        }

        return response()->json(['received' => true]);
    }

    // PORTAL INSCRIPCION - devuelve el estado actual y sincroniza con Stripe cuando hace falta.
    public function estado(string $token): JsonResponse
    {
        $solicitud = DB::table('admision.inscripcion_temporal')
            ->where('token', $token)
            ->first();

        abort_unless($solicitud, 404, 'Solicitud de inscripcion no encontrada.');

        $solicitud = $this->resolverEstadoTemporal($solicitud);

        $response = [
            'estado' => $solicitud->estado,
            'expira_en' => Carbon::parse($solicitud->expira_en)->toISOString(),
            'monto' => (float) $solicitud->monto,
            'moneda' => $solicitud->moneda,
            'stripe_estado' => $solicitud->stripe_estado,
        ];

        if ($solicitud->estado === 'PAGADA') {
            $response['boleta'] = $this->detalleArray($token);
        }

        return response()->json($response);
    }

    // PORTAL INSCRIPCION - permite cancelar por timeout o por cambio de datos antes del pago.
    public function cancelar(string $token): JsonResponse
    {
        DB::transaction(function () use ($token) {
            $solicitud = DB::table('admision.inscripcion_temporal')
                ->where('token', $token)
                ->lockForUpdate()
                ->first();

            abort_unless($solicitud, 404, 'Solicitud de inscripcion no encontrada.');

            if ($solicitud->estado === 'PAGADA') {
                return;
            }

            if ($solicitud->stripe_payment_intent_id) {
                try {
                    $paymentIntent = $this->stripe->retrievePaymentIntent($solicitud->stripe_payment_intent_id);
                    if ($this->stripe->canCancelStatus($paymentIntent->status)) {
                        $this->stripe->cancelPaymentIntent($paymentIntent->id);
                    }
                } catch (ApiErrorException $exception) {
                    Log::warning('No se pudo cancelar el PaymentIntent de Stripe.', [
                        'token' => $token,
                        'payment_intent_id' => $solicitud->stripe_payment_intent_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            DB::table('admision.inscripcion_temporal')
                ->where('token', $token)
                ->update([
                    'estado' => now()->greaterThan(Carbon::parse($solicitud->expira_en)) ? 'EXPIRADA' : 'CANCELADA',
                    'stripe_estado' => 'canceled',
                    'actualizado_en' => now(),
                ]);
        });

        return response()->json(['ok' => true]);
    }

    // PORTAL INSCRIPCION - boleta oficial consolidada luego del pago.
    public function detalle(string $token): JsonResponse
    {
        return response()->json($this->detalleArray($token));
    }

    // PORTAL INSCRIPCION - descarga PDF oficial de boleta.
    public function boletaPdf(string $token)
    {
        $detalle = $this->detalleArray($token);
        $pdf = Pdf::loadView('pdf.boleta-inscripcion', ['detalle' => $detalle])
            ->setPaper('letter');

        $nombre = 'boleta-inscripcion-'.$detalle['postulante']->postulante_id.'.pdf';

        return $pdf->download($nombre);
    }

    private function resolverEstadoTemporal(object $solicitud): object
    {
        if ($solicitud->estado === 'PAGADA') {
            return $solicitud;
        }

        if (now()->greaterThan(Carbon::parse($solicitud->expira_en)) && $solicitud->estado === 'PENDIENTE') {
            $this->cancelarInternamente($solicitud);
            return (object) array_merge((array) $solicitud, [
                'estado' => 'EXPIRADA',
                'stripe_estado' => 'canceled',
            ]);
        }

        if (!$solicitud->stripe_payment_intent_id) {
            return $solicitud;
        }

        try {
            $paymentIntent = $this->stripe->retrievePaymentIntent($solicitud->stripe_payment_intent_id);
        } catch (ApiErrorException $exception) {
            Log::warning('No se pudo consultar Stripe para una inscripcion temporal.', [
                'token' => $solicitud->token,
                'payment_intent_id' => $solicitud->stripe_payment_intent_id,
                'error' => $exception->getMessage(),
            ]);

            return $solicitud;
        }

        DB::table('admision.inscripcion_temporal')
            ->where('token', $solicitud->token)
            ->update([
                'stripe_estado' => $paymentIntent->status,
                'actualizado_en' => now(),
            ]);

        if ($paymentIntent->status === 'succeeded') {
            $this->sincronizarPagoPorIntent($paymentIntent->id);

            return DB::table('admision.inscripcion_temporal')
                ->where('token', $solicitud->token)
                ->first();
        }

        if ($paymentIntent->status === 'canceled' && $solicitud->estado === 'PENDIENTE') {
            DB::table('admision.inscripcion_temporal')
                ->where('token', $solicitud->token)
                ->update([
                    'estado' => now()->greaterThan(Carbon::parse($solicitud->expira_en)) ? 'EXPIRADA' : 'CANCELADA',
                    'stripe_estado' => $paymentIntent->status,
                    'actualizado_en' => now(),
                ]);

            return DB::table('admision.inscripcion_temporal')
                ->where('token', $solicitud->token)
                ->first();
        }

        return (object) array_merge((array) $solicitud, [
            'stripe_estado' => $paymentIntent->status,
        ]);
    }

    private function sincronizarPagoPorIntent(string $paymentIntentId): void
    {
        DB::transaction(function () use ($paymentIntentId) {
            $solicitud = DB::table('admision.inscripcion_temporal')
                ->where('stripe_payment_intent_id', $paymentIntentId)
                ->lockForUpdate()
                ->first();

            if (!$solicitud || $solicitud->estado === 'PAGADA') {
                return;
            }

            $payload = json_decode($solicitud->datos, true);
            $this->validateDuplicateAtPayment($payload);

            $postulante = Postulante::query()->create([
                'gestion_id' => $payload['gestion_id'],
                'ci' => $payload['ci'],
                'nombres' => $payload['nombres'],
                'apellidos' => $payload['apellidos'],
                'fecha_nacimiento' => $payload['fecha_nacimiento'],
                'sexo' => $payload['sexo'],
                'direccion' => $payload['direccion'],
                'telefono' => $payload['telefono'],
                'correo' => $payload['correo'],
                'colegio_procedencia' => $payload['colegio_procedencia'],
                'ciudad' => $payload['ciudad'],
                'titulo_bachiller_codigo' => $payload['titulo_bachiller_codigo'],
                'estado' => 'INSCRITO',
                'fecha_registro' => now(),
            ]);

            DB::table('admision.postulante_carrera_opcion')->insert([
                [
                    'postulante_id' => $postulante->postulante_id,
                    'orden' => 1,
                    'carrera_id' => $payload['carrera_opcion_1'],
                ],
                [
                    'postulante_id' => $postulante->postulante_id,
                    'orden' => 2,
                    'carrera_id' => $payload['carrera_opcion_2'],
                ],
            ]);

            $this->asignarGrupoDisponible($postulante->postulante_id, $postulante->gestion_id);

            DB::table('admision.pago_inscripcion')->insert([
                'postulante_id' => $postulante->postulante_id,
                'token_inscripcion' => $solicitud->token,
                'monto' => $solicitud->monto,
                'moneda' => $solicitud->moneda,
                'proveedor' => 'STRIPE',
                'metodo' => 'STRIPE_CARD',
                'numero_comprobante' => $paymentIntentId,
                'referencia_externa' => $paymentIntentId,
                'estado' => 'PAGADO',
                'pagado_en' => now(),
                'creado_en' => now(),
            ]);

            DB::table('admision.inscripcion_temporal')
                ->where('token', $solicitud->token)
                ->update([
                    'estado' => 'PAGADA',
                    'stripe_estado' => 'succeeded',
                    'pagado_en' => now(),
                    'actualizado_en' => now(),
                ]);
        });
    }

    private function cancelarInternamente(object $solicitud): void
    {
        if (!$solicitud->stripe_payment_intent_id) {
            DB::table('admision.inscripcion_temporal')
                ->where('token', $solicitud->token)
                ->update([
                    'estado' => 'EXPIRADA',
                    'actualizado_en' => now(),
                ]);

            return;
        }

        try {
            $paymentIntent = $this->stripe->retrievePaymentIntent($solicitud->stripe_payment_intent_id);
            if ($this->stripe->canCancelStatus($paymentIntent->status)) {
                $this->stripe->cancelPaymentIntent($paymentIntent->id);
            }
        } catch (ApiErrorException $exception) {
            Log::warning('No se pudo cancelar una solicitud temporal expirada.', [
                'token' => $solicitud->token,
                'payment_intent_id' => $solicitud->stripe_payment_intent_id,
                'error' => $exception->getMessage(),
            ]);
        }

        DB::table('admision.inscripcion_temporal')
            ->where('token', $solicitud->token)
            ->update([
                'estado' => 'EXPIRADA',
                'stripe_estado' => 'canceled',
                'actualizado_en' => now(),
            ]);
    }

    private function cancelStripeIntentSilently(string $paymentIntentId): void
    {
        try {
            $paymentIntent = $this->stripe->retrievePaymentIntent($paymentIntentId);
            if ($this->stripe->canCancelStatus($paymentIntent->status)) {
                $this->stripe->cancelPaymentIntent($paymentIntentId);
            }
        } catch (ApiErrorException $exception) {
            Log::warning('No se pudo cancelar un PaymentIntent huerfano.', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function detalleArray(string $token): array
    {
        $pago = DB::table('admision.pago_inscripcion as pi')
            ->join('admision.postulante as p', 'p.postulante_id', '=', 'pi.postulante_id')
            ->where('pi.token_inscripcion', $token)
            ->select('pi.*', 'p.postulante_id')
            ->first();

        abort_unless($pago, 404, 'Boleta no encontrada. Confirma el pago para consolidar la inscripcion.');

        $postulante = DB::table('admision.postulante as p')
            ->join('admision.gestion_academica as ga', 'ga.gestion_id', '=', 'p.gestion_id')
            ->leftJoin('admision.grupo_postulante as gp', 'gp.postulante_id', '=', 'p.postulante_id')
            ->leftJoin('admision.grupo as g', 'g.grupo_id', '=', 'gp.grupo_id')
            ->where('p.postulante_id', $pago->postulante_id)
            ->select('p.*', 'ga.nombre as gestion', 'g.grupo_id', 'g.codigo as grupo_asignado', 'g.turno')
            ->first();

        $carreras = DB::table('admision.postulante_carrera_opcion as pco')
            ->join('admision.carrera as c', 'c.carrera_id', '=', 'pco.carrera_id')
            ->where('pco.postulante_id', $pago->postulante_id)
            ->orderBy('pco.orden')
            ->get(['pco.orden', 'c.codigo', 'c.nombre']);

        $materias = $postulante->grupo_id
            ? $this->materiasDelGrupo((int) $postulante->grupo_id)
            : Materia::query()->where('estado', 'ACTIVO')->orderBy('nombre')->get(['materia_id', 'codigo', 'nombre']);

        return [
            'postulante' => $postulante,
            'carreras' => $carreras,
            'pago' => $pago,
            'materias' => $materias,
            'estado_grupo' => $postulante->grupo_id ? 'ASIGNADO' : 'PENDIENTE_DE_GRUPO',
        ];
    }

    private function materiasDelGrupo(int $grupoId)
    {
        $horarios = DB::table('admision.horario_clase as hc')
            ->join('admision.materia as m', 'm.materia_id', '=', 'hc.materia_id')
            ->join('admision.docente as d', 'd.docente_id', '=', 'hc.docente_id')
            ->join('admision.aula as a', 'a.aula_id', '=', 'hc.aula_id')
            ->where('hc.grupo_id', $grupoId)
            ->select(
                'hc.horario_id',
                'm.materia_id',
                'm.codigo',
                'm.nombre',
                'hc.dia',
                'hc.hora_inicio',
                'hc.hora_fin',
                DB::raw("d.nombres || ' ' || d.apellidos as docente"),
                'a.codigo as aula'
            )
            ->orderByRaw("array_position(ARRAY['LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO','DOMINGO'], hc.dia::TEXT)")
            ->orderBy('hc.hora_inicio')
            ->get();

        if ($horarios->isNotEmpty()) {
            return $horarios;
        }

        return DB::table('admision.grupo_materia_docente as gmd')
            ->join('admision.materia as m', 'm.materia_id', '=', 'gmd.materia_id')
            ->leftJoin('admision.docente as d', 'd.docente_id', '=', 'gmd.docente_id')
            ->where('gmd.grupo_id', $grupoId)
            ->select(
                'm.materia_id',
                'm.codigo',
                'm.nombre',
                DB::raw("COALESCE(d.nombres || ' ' || d.apellidos, 'Pendiente') as docente")
            )
            ->orderBy('m.nombre')
            ->get();
    }

    private function rules(Request $request): array
    {
        return [
            'gestion_id' => ['required', 'exists:gestion_academica,gestion_id'],
            'ci' => [
                'required',
                'max:20',
                Rule::unique('postulante', 'ci')->where(fn ($query) => $query->where('gestion_id', $request->input('gestion_id'))),
            ],
            'nombres' => ['required', 'max:80'],
            'apellidos' => ['required', 'max:80'],
            'fecha_nacimiento' => ['required', 'date'],
            'sexo' => ['required', Rule::in(['M', 'F', 'OTRO'])],
            'direccion' => ['required', 'max:200'],
            'telefono' => ['required', 'max:30'],
            'correo' => [
                'required',
                'email',
                'max:120',
                Rule::unique('postulante', 'correo')->where(fn ($query) => $query->where('gestion_id', $request->input('gestion_id'))),
            ],
            'colegio_procedencia' => ['required', 'max:150'],
            'ciudad' => ['required', 'max:80'],
            'titulo_bachiller_codigo' => ['required', 'max:60'],
            'carrera_opcion_1' => ['required', 'exists:carrera,carrera_id'],
            'carrera_opcion_2' => ['required', 'exists:carrera,carrera_id', 'different:carrera_opcion_1'],
        ];
    }

    private function messages(): array
    {
        return [
            'ci.required' => 'El CI es obligatorio.',
            'ci.unique' => 'El CI ya esta registrado en esta gestion.',
            'correo.required' => 'El correo electronico es obligatorio.',
            'correo.email' => 'El correo electronico no tiene formato valido.',
            'correo.unique' => 'El correo electronico ya esta registrado en esta gestion.',
            'carrera_opcion_2.different' => 'La segunda carrera debe ser diferente de la primera.',
            '*.required' => 'Todos los campos obligatorios deben estar completos.',
        ];
    }

    private function validateDuplicateAtPayment(array $payload): void
    {
        $ciExists = DB::table('admision.postulante')
            ->where('gestion_id', $payload['gestion_id'])
            ->where('ci', $payload['ci'])
            ->exists();

        abort_if($ciExists, 422, 'El CI ya fue registrado antes de confirmar el pago.');

        $correoExists = DB::table('admision.postulante')
            ->where('gestion_id', $payload['gestion_id'])
            ->where('correo', $payload['correo'])
            ->exists();

        abort_if($correoExists, 422, 'El correo ya fue registrado antes de confirmar el pago.');
    }

    private function asignarGrupoDisponible(int $postulanteId, string $gestionId): bool
    {
        $grupo = DB::table('admision.grupo as g')
            ->leftJoin('admision.grupo_postulante as gp', 'gp.grupo_id', '=', 'g.grupo_id')
            ->where('g.gestion_id', $gestionId)
            ->where('g.estado', 'ACTIVO')
            ->select('g.grupo_id', 'g.capacidad_maxima', DB::raw('COUNT(gp.postulante_id)::INTEGER as total_estudiantes'))
            ->groupBy('g.grupo_id', 'g.capacidad_maxima')
            ->havingRaw('COUNT(gp.postulante_id) < g.capacidad_maxima')
            ->orderBy('g.codigo')
            ->first();

        if (!$grupo) {
            return false;
        }

        DB::table('admision.grupo_postulante')->insert([
            'grupo_id' => $grupo->grupo_id,
            'postulante_id' => $postulanteId,
            'asignado_en' => now(),
        ]);

        return true;
    }

    private function trimStrings(array $data): array
    {
        return collect($data)
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->all();
    }
}
