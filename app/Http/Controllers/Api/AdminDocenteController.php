<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AdminShared;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocenteResource;
use App\Models\Docente;
use App\Models\GestionAcademica;
use App\Models\Materia;
use App\Services\TeacherAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AdminDocenteController extends Controller
{
    use AdminShared;

    public function __construct(private readonly TeacherAssignmentService $teacherAssignmentService)
    {
    }

    // MODULO DOCENTES - lista docentes junto a las materias que pueden dictar.
    public function docentes(): JsonResponse
    {
        return response()->json(Cache::remember($this->adminCachePrefix().'docentes', now()->addMinutes(5), function () {
            $docentes = Docente::query()->orderBy('apellidos')->orderBy('nombres')->get();
            $materias = DB::table('admision.docente_materia as dm')
                ->join('admision.materia as m', 'm.materia_id', '=', 'dm.materia_id')
                ->select('dm.docente_id', 'm.materia_id', 'm.nombre')
                ->orderBy('m.nombre')
                ->get()
                ->groupBy('docente_id');
            $requisitos = DB::table('admision.docente_requisito as dr')
                ->join('admision.tipo_requisito_docente as tr', 'tr.tipo_requisito_id', '=', 'dr.tipo_requisito_id')
                ->select(
                    'dr.docente_id',
                    'dr.tipo_requisito_id',
                    'tr.codigo',
                    'tr.nombre',
                    'dr.descripcion',
                    'dr.institucion',
                    'dr.fecha_obtencion',
                    'dr.codigo_documento',
                    'dr.archivo_nombre_original',
                    'dr.archivo_mime',
                    'dr.archivo_tamano',
                    'dr.estado_validacion',
                    'dr.validado_en'
                )
                ->orderBy('tr.tipo_requisito_id')
                ->get()
                ->groupBy('docente_id');

            return $docentes->map(function (Docente $docente) use ($materias, $requisitos) {
                $asignadas = $materias->get($docente->docente_id, collect());
                $docente->materia_ids = $asignadas->pluck('materia_id')->values();
                $docente->materias = $asignadas->pluck('nombre')->values()->implode(', ');
                $docente->requisitos = $requisitos->get($docente->docente_id, collect())->values();
                $docente->documentacion_estado = $this->estadoDocumentacion($docente->requisitos);

                return (new DocenteResource($docente))->resolve();
            });
        }));
    }

    // MODULO ASIGNACION DOCENTES - arma la matriz grupo + materia + docente para la interfaz.
    public function docenteAsignaciones(Request $request): JsonResponse
    {
        $gestion = $request->query('gestion_id')
            ? GestionAcademica::query()->where('gestion_id', $request->query('gestion_id'))->first()
            : GestionAcademica::query()->orderByDesc('gestion_id')->first();
        $gestionId = $gestion?->gestion_id;

        if ($gestionId) {
            $this->teacherAssignmentService->ensureGroupSubjectRows($gestionId);
        }

        $asignaciones = DB::table('admision.grupo_materia_docente as gmd')
            ->join('admision.grupo as g', 'g.grupo_id', '=', 'gmd.grupo_id')
            ->join('admision.materia as m', 'm.materia_id', '=', 'gmd.materia_id')
            ->leftJoin('admision.docente as d', 'd.docente_id', '=', 'gmd.docente_id')
            ->when($gestionId, fn ($query) => $query->where('g.gestion_id', $gestionId))
            ->select(
                'gmd.grupo_id',
                'gmd.materia_id',
                'gmd.docente_id',
                'gmd.observacion',
                'g.gestion_id',
                'g.codigo as grupo',
                'm.nombre as materia',
                DB::raw("COALESCE(d.nombres || ' ' || d.apellidos, '') as docente"),
                DB::raw("CASE WHEN gmd.docente_id IS NULL THEN 'SIN_ASIGNAR' ELSE 'ASIGNADO' END as estado")
            )
            ->orderBy('g.codigo')
            ->orderBy('m.nombre')
            ->get();

        return response()->json([
            'gestion' => $gestion,
            'gestiones' => GestionAcademica::query()->orderByDesc('gestion_id')->get(),
            'grupos' => DB::table('admision.grupo')->when($gestionId, fn ($query) => $query->where('gestion_id', $gestionId))->orderBy('codigo')->get(),
            'materias' => Materia::query()->where('estado', 'ACTIVO')->orderBy('nombre')->get(),
            'docentes' => $this->teacherAssignmentService->availableTeachersBySubject(),
            'asignaciones' => $asignaciones,
            'resumen' => [
                'materias_programadas' => $asignaciones->count(),
                'docentes_disponibles' => Docente::query()->where('estado', 'ACTIVO')->count(),
                'sin_asignar' => $asignaciones->where('docente_id', null)->count(),
            ],
        ]);
    }

    // MODULO ASIGNACION DOCENTES - asigna/reasigna docente a una materia de un grupo.
    public function asignarDocenteGrupoMateria(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grupo_id' => ['required', 'exists:grupo,grupo_id'],
            'materia_id' => ['required', 'exists:materia,materia_id'],
            'docente_id' => ['required', 'exists:docente,docente_id'],
            'observacion' => ['nullable', 'max:200'],
        ]);

        $error = $this->teacherAssignmentService->validateTeacherCanBeAssigned(
            (int) $data['grupo_id'],
            (int) $data['materia_id'],
            (int) $data['docente_id']
        );

        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $this->teacherAssignmentService->assignTeacherToGroupSubject(
            (int) $data['grupo_id'],
            (int) $data['materia_id'],
            (int) $data['docente_id'],
            $data['observacion'] ?? null
        );
        $this->clearAdminCache();

        return response()->json(['ok' => true]);
    }

    // MODULO DOCENTES - crea docente y sincroniza materias habilitadas en docente_materia.
    public function storeDocente(Request $request): JsonResponse
    {
        $data = $request->validate($this->docenteRules(true), $this->docenteMessages());

        $materiaIds = array_values(array_unique($data['materia_ids']));
        $requisitos = $data['requisitos'];
        unset($data['materia_ids'], $data['requisitos'], $data['documentos']);

        $docente = DB::transaction(function () use ($request, $data, $materiaIds, $requisitos) {
            $docente = Docente::query()->create($data);
            $this->teacherAssignmentService->syncDocenteMaterias($docente->docente_id, $materiaIds);
            $this->guardarRequisitos($request, $docente->docente_id, $requisitos);

            return $docente;
        });
        $this->clearAdminCache();

        return response()->json($docente, 201);
    }

    // MODULO DOCENTES - actualiza datos, materias y documentos profesionales.
    public function updateDocente(Request $request, Docente $docente): JsonResponse
    {
        $data = $request->validate($this->docenteRules(false, $docente), $this->docenteMessages());

        $materiaIds = array_values(array_unique($data['materia_ids']));
        $requisitos = $data['requisitos'];
        unset($data['materia_ids'], $data['requisitos'], $data['documentos']);

        DB::transaction(function () use ($request, $docente, $data, $materiaIds, $requisitos) {
            $docente->update($data);
            $this->teacherAssignmentService->syncDocenteMaterias($docente->docente_id, $materiaIds);
            $this->guardarRequisitos($request, $docente->docente_id, $requisitos);
        });
        $this->clearAdminCache();

        return response()->json($docente);
    }

    // DOCUMENTOS DOCENTE - descarga el archivo persistido en PostgreSQL.
    public function descargarDocumento(Docente $docente, int $tipoRequisitoId): Response
    {
        $documento = DB::table('admision.docente_requisito')
            ->where('docente_id', $docente->docente_id)
            ->where('tipo_requisito_id', $tipoRequisitoId)
            ->first();

        abort_unless($documento && $documento->archivo_contenido, 404, 'Documento no encontrado.');
        $contenido = is_resource($documento->archivo_contenido)
            ? stream_get_contents($documento->archivo_contenido)
            : $documento->archivo_contenido;
        $nombreArchivo = str_replace(
            ['"', "\r", "\n"],
            '',
            $documento->archivo_nombre_original ?: 'documento'
        );

        return response($contenido, 200, [
            'Content-Type' => $documento->archivo_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$nombreArchivo.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    // DOCUMENTOS DOCENTE - permite al administrador aprobar u observar cada respaldo.
    public function actualizarEstadoDocumento(Request $request, Docente $docente, int $tipoRequisitoId): JsonResponse
    {
        $data = $request->validate([
            'estado_validacion' => ['required', Rule::in(['PENDIENTE', 'VALIDADO', 'OBSERVADO', 'RECHAZADO'])],
        ]);
        $session = $request->attributes->get('auth_session', []);

        $updated = DB::table('admision.docente_requisito')
            ->where('docente_id', $docente->docente_id)
            ->where('tipo_requisito_id', $tipoRequisitoId)
            ->update([
                'estado_validacion' => $data['estado_validacion'],
                'validado_por' => $data['estado_validacion'] === 'PENDIENTE' ? null : ($session['usuario_id'] ?? null),
                'validado_en' => $data['estado_validacion'] === 'PENDIENTE' ? null : now(),
            ]);

        abort_unless($updated, 404, 'Documento no encontrado.');
        $this->clearAdminCache();

        return response()->json(['ok' => true]);
    }

    private function docenteRules(bool $creating, ?Docente $docente = null): array
    {
        $documentRule = $creating ? ['required', 'file'] : ['nullable', 'file'];

        return [
            'ci' => [
                'required',
                'max:20',
                $creating
                    ? Rule::unique('docente', 'ci')
                    : Rule::unique('docente', 'ci')->ignore($docente?->docente_id, 'docente_id'),
            ],
            'nombres' => ['required', 'max:80'],
            'apellidos' => ['required', 'max:80'],
            'telefono' => ['required', 'max:30'],
            'correo' => [
                'required',
                'email',
                'max:120',
                $creating
                    ? Rule::unique('docente', 'correo')
                    : Rule::unique('docente', 'correo')->ignore($docente?->docente_id, 'docente_id'),
            ],
            'especialidad' => ['required', 'max:120'],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
            'materia_ids' => ['required', 'array', 'min:1'],
            'materia_ids.*' => ['required', 'exists:materia,materia_id'],
            'requisitos' => ['required', 'array'],
            'requisitos.PROF_AREA.descripcion' => ['required', 'string', 'max:200'],
            'requisitos.PROF_AREA.institucion' => ['required', 'string', 'max:150'],
            'requisitos.PROF_AREA.fecha_obtencion' => ['required', 'date'],
            'requisitos.PROF_AREA.codigo_documento' => ['required', 'string', 'max:80'],
            'requisitos.MAESTRIA.descripcion' => ['required', 'string', 'max:200'],
            'requisitos.MAESTRIA.institucion' => ['required', 'string', 'max:150'],
            'requisitos.MAESTRIA.fecha_obtencion' => ['required', 'date'],
            'requisitos.MAESTRIA.codigo_documento' => ['required', 'string', 'max:80'],
            'requisitos.DIP_EDU_SUP.descripcion' => ['required', 'string', 'max:200'],
            'requisitos.DIP_EDU_SUP.institucion' => ['required', 'string', 'max:150'],
            'requisitos.DIP_EDU_SUP.fecha_obtencion' => ['required', 'date'],
            'requisitos.DIP_EDU_SUP.codigo_documento' => ['required', 'string', 'max:80'],
            'documentos' => ['nullable', 'array'],
            'documentos.PROF_AREA' => [...$documentRule, 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'documentos.MAESTRIA' => [...$documentRule, 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'documentos.DIP_EDU_SUP' => [...$documentRule, 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }

    private function docenteMessages(): array
    {
        return [
            'documentos.*.required' => 'Debes adjuntar los tres documentos profesionales.',
            'documentos.*.mimes' => 'Los documentos deben ser imagenes JPG, PNG, WEBP o archivos PDF.',
            'documentos.*.max' => 'Cada documento puede pesar como maximo 5 MB.',
            'requisitos.*.*.required' => 'Completa todos los datos academicos del docente.',
        ];
    }

    private function guardarRequisitos(Request $request, int $docenteId, array $requisitos): void
    {
        $tipos = DB::table('admision.tipo_requisito_docente')
            ->whereIn('codigo', ['PROF_AREA', 'MAESTRIA', 'DIP_EDU_SUP'])
            ->pluck('tipo_requisito_id', 'codigo');

        foreach ($requisitos as $codigo => $requisito) {
            $tipoId = $tipos[$codigo] ?? null;
            abort_unless($tipoId, 422, "No existe el requisito docente {$codigo}.");

            $archivo = $request->file("documentos.{$codigo}");
            $values = [
                'descripcion' => $requisito['descripcion'],
                'institucion' => $requisito['institucion'],
                'fecha_obtencion' => $requisito['fecha_obtencion'],
                'codigo_documento' => $requisito['codigo_documento'],
            ];

            if ($archivo) {
                $values += [
                    'archivo_ruta' => null,
                    'archivo_nombre_original' => $archivo->getClientOriginalName(),
                    'archivo_mime' => $archivo->getMimeType(),
                    'archivo_tamano' => $archivo->getSize(),
                    // PostgreSQL BYTEA requiere codificar el binario para que PDO no lo interprete como UTF-8.
                    'archivo_contenido' => DB::raw(
                        "decode('".base64_encode(file_get_contents($archivo->getRealPath()))."', 'base64')"
                    ),
                    'estado_validacion' => 'PENDIENTE',
                    'validado_por' => null,
                    'validado_en' => null,
                ];
            }

            DB::table('admision.docente_requisito')->updateOrInsert(
                ['docente_id' => $docenteId, 'tipo_requisito_id' => $tipoId],
                $values
            );
        }
    }

    private function estadoDocumentacion($requisitos): string
    {
        if ($requisitos->count() < 3) {
            return 'INCOMPLETA';
        }
        if ($requisitos->contains(fn ($item) => $item->estado_validacion === 'RECHAZADO')) {
            return 'RECHAZADA';
        }
        if ($requisitos->every(fn ($item) => $item->estado_validacion === 'VALIDADO')) {
            return 'VALIDADA';
        }

        return 'PENDIENTE';
    }

    // MODULO DOCENTES - elimina docente si no tiene restricciones en horarios u otras tablas.
    public function deleteDocente(Docente $docente): JsonResponse
    {
        try {
            $docente->delete();
            $this->clearAdminCache();
        } catch (Throwable) {
            return response()->json([
                'message' => 'No se puede eliminar el docente porque ya esta asignado en horarios.',
            ], 409);
        }

        return response()->json(['ok' => true]);
    }
}
