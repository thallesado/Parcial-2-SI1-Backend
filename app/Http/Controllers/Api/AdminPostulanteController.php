<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AdminShared;
use App\Http\Controllers\Controller;
use App\Http\Resources\PostulanteResource;
use App\Models\Postulante;
use App\Models\PostulanteCarreraOpcion;
use App\Services\GroupAssignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminPostulanteController extends Controller
{
    use AdminShared;

    public function __construct(private readonly GroupAssignmentService $groupAssignmentService)
    {
    }

    // MODULO POSTULANTES - lista postulantes paginados con carreras elegidas y grupo asignado.
    public function postulantes(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 10), 100);
        $search = trim((string) $request->query('search', ''));
        $estado = $request->query('estado');
        $gestionId = $request->query('gestion_id');
        $carreraId = $request->query('carrera_id');

        $query = DB::table('admision.postulante as p')
            ->join('admision.gestion_academica as ga', 'ga.gestion_id', '=', 'p.gestion_id')
            ->leftJoin('admision.postulante_carrera_opcion as pco1', function ($join) {
                $join->on('pco1.postulante_id', '=', 'p.postulante_id')
                    ->where('pco1.orden', 1);
            })
            ->leftJoin('admision.carrera as c1', 'c1.carrera_id', '=', 'pco1.carrera_id')
            ->leftJoin('admision.postulante_carrera_opcion as pco2', function ($join) {
                $join->on('pco2.postulante_id', '=', 'p.postulante_id')
                    ->where('pco2.orden', 2);
            })
            ->leftJoin('admision.carrera as c2', 'c2.carrera_id', '=', 'pco2.carrera_id')
            ->leftJoin('admision.grupo_postulante as gp', 'gp.postulante_id', '=', 'p.postulante_id')
            ->leftJoin('admision.grupo as g', 'g.grupo_id', '=', 'gp.grupo_id')
            ->when($search !== '', function ($query) use ($search) {
                $like = "%{$search}%";

                $query->where(function ($inner) use ($like) {
                    $inner->where('p.ci', 'ILIKE', $like)
                        ->orWhere('p.nombres', 'ILIKE', $like)
                        ->orWhere('p.apellidos', 'ILIKE', $like)
                        ->orWhere('p.correo', 'ILIKE', $like);
                });
            })
            ->when($estado, fn ($query) => $query->where('p.estado', $estado))
            ->when($gestionId, fn ($query) => $query->where('p.gestion_id', $gestionId))
            ->when($carreraId, function ($query) use ($carreraId) {
                $query->where(function ($inner) use ($carreraId) {
                    $inner->where('pco1.carrera_id', $carreraId)
                        ->orWhere('pco2.carrera_id', $carreraId);
                });
            })
            ->select(
                'p.postulante_id',
                'p.gestion_id',
                'ga.nombre as gestion',
                'p.ci',
                'p.nombres',
                'p.apellidos',
                'p.fecha_nacimiento',
                'p.sexo',
                'p.direccion',
                'p.telefono',
                'p.correo',
                'p.colegio_procedencia',
                'p.ciudad',
                'p.titulo_bachiller_codigo',
                'c1.nombre as carrera_opcion_1',
                'c2.nombre as carrera_opcion_2',
                'p.estado',
                'p.fecha_registro',
                'g.grupo_id',
                'g.codigo as grupo_asignado'
            )
            ->orderByDesc('p.fecha_registro')
            ->orderByDesc('p.postulante_id');

        return response()->json(
            $query->paginate($perPage)->through(fn ($row) => (new PostulanteResource($row))->resolve())
        );
    }

    // MODULO BITACORA - entrega ultimos movimientos importantes del administrador.
    public function bitacora(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 10), 100);
        $search = trim((string) $request->query('search', ''));

        return response()->json(
            DB::table('admision.bitacora_accion')
                ->when($search !== '', function ($query) use ($search) {
                    $like = '%'.$search.'%';

                    $query->where(function ($subQuery) use ($like) {
                        $subQuery
                            ->where('accion', 'ILIKE', $like)
                            ->orWhere('tabla_afectada', 'ILIKE', $like)
                            ->orWhere('registro_id', 'ILIKE', $like)
                            ->orWhere('descripcion', 'ILIKE', $like)
                            ->orWhere('ip', 'ILIKE', $like);
                    });
                })
                ->orderByDesc('fecha_hora')
                ->paginate($perPage)
        );
    }

    // MODULO POSTULANTES - crea postulante, guarda sus dos carreras y trata de asignar grupo.
    public function storePostulante(Request $request): JsonResponse
    {
        $request->merge($this->trimStrings($request->all()));

        $data = $request->validate([
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
            'carrera_opcion_2' => ['required', 'different:carrera_opcion_1', 'exists:carrera,carrera_id'],
        ], $this->postulanteMessages());

        $postulante = DB::transaction(function () use ($data) {
            $opcion1 = $data['carrera_opcion_1'];
            $opcion2 = $data['carrera_opcion_2'];
            unset($data['carrera_opcion_1'], $data['carrera_opcion_2']);

            $postulante = Postulante::query()->create($data);

            PostulanteCarreraOpcion::query()->insert([
                ['postulante_id' => $postulante->postulante_id, 'orden' => 1, 'carrera_id' => $opcion1],
                ['postulante_id' => $postulante->postulante_id, 'orden' => 2, 'carrera_id' => $opcion2],
            ]);

            $this->groupAssignmentService->assignPostulanteToAvailableGroup($postulante->postulante_id, $postulante->gestion_id);

            return $postulante;
        });
        $this->clearAdminCache();

        return response()->json($postulante, 201);
    }

    // MODULO POSTULANTES - modifica datos editables y permite reasignar grupo.
    public function updatePostulante(Request $request, Postulante $postulante): JsonResponse
    {
        $request->merge($this->trimStrings($request->all()));
        if ($request->input('grupo_id') === '') {
            $request->merge(['grupo_id' => null]);
        }

        $data = $request->validate([
            'ci' => [
                'required',
                'max:20',
                Rule::unique('postulante', 'ci')
                    ->where(fn ($query) => $query->where('gestion_id', $postulante->gestion_id))
                    ->ignore($postulante->postulante_id, 'postulante_id'),
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
                Rule::unique('postulante', 'correo')
                    ->where(fn ($query) => $query->where('gestion_id', $postulante->gestion_id))
                    ->ignore($postulante->postulante_id, 'postulante_id'),
            ],
            'colegio_procedencia' => ['required', 'max:150'],
            'ciudad' => ['required', 'max:80'],
            'titulo_bachiller_codigo' => ['required', 'max:60'],
            'estado' => ['required', Rule::in(['REGISTRADO', 'INSCRITO', 'RETIRADO', 'ANULADO'])],
            'grupo_id' => ['nullable', 'exists:grupo,grupo_id'],
        ], $this->postulanteMessages());

        $grupoId = $data['grupo_id'] ?? null;
        unset($data['grupo_id']);

        DB::transaction(function () use ($postulante, $data, $grupoId) {
            $postulante->update($data);

            if ($grupoId !== null && $grupoId !== '') {
                DB::table('admision.grupo_postulante')->updateOrInsert(
                    ['postulante_id' => $postulante->postulante_id],
                    ['grupo_id' => $grupoId, 'asignado_en' => now()]
                );
            } else {
                DB::table('admision.grupo_postulante')
                    ->where('postulante_id', $postulante->postulante_id)
                    ->delete();
            }
        });
        $this->clearAdminCache();

        return response()->json($postulante);
    }

    // MODULO POSTULANTES - elimina postulante; trigger impide borrar si ya tiene notas.
    public function deletePostulante(Postulante $postulante): JsonResponse
    {
        try {
            $postulante->delete();
            $this->clearAdminCache();
        } catch (QueryException $exception) {
            return response()->json([
                'message' => str_contains($exception->getMessage(), 'notas registradas')
                    ? 'No se puede eliminar el postulante porque ya tiene notas registradas.'
                    : 'No se puede eliminar el postulante porque tiene informacion relacionada que debe revisarse.',
            ], 409);
        }

        return response()->json(['ok' => true]);
    }
}
