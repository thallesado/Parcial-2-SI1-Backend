<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AdminShared;
use App\Http\Controllers\Controller;
use App\Http\Resources\GrupoResource;
use App\Models\GestionAcademica;
use App\Services\GroupAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminGroupController extends Controller
{
    use AdminShared;

    public function __construct(private readonly GroupAssignmentService $groupAssignmentService)
    {
    }

    // MODULO GRUPOS - calcula inscritos, grupos necesarios, grupos habilitados y pendientes.
    public function gruposResumen(Request $request): JsonResponse
    {
        $gestion = GestionAcademica::query()
            ->when($request->query('gestion_id'), fn ($query, $gestionId) => $query->where('gestion_id', $gestionId))
            ->orderByDesc('gestion_id')
            ->first();
        $gestionId = $gestion?->gestion_id;
        $cacheKey = $this->adminCachePrefix().'grupos:'.($gestionId ?? 'actual');

        return response()->json(Cache::remember($cacheKey, now()->addMinutes(3), function () use ($gestion, $gestionId) {
            $totalInscritos = DB::table('admision.postulante')
                ->when($gestionId, fn ($query) => $query->where('gestion_id', $gestionId))
                ->whereIn('estado', ['REGISTRADO', 'INSCRITO'])
                ->count();

            $grupos = DB::table('admision.grupo as g')
                ->leftJoin('admision.grupo_postulante as gp', 'gp.grupo_id', '=', 'g.grupo_id')
                ->when($gestionId, fn ($query) => $query->where('g.gestion_id', $gestionId))
                ->select(
                    'g.grupo_id',
                    'g.gestion_id',
                    'g.codigo',
                    'g.capacidad_maxima',
                    'g.turno',
                    'g.estado',
                    DB::raw('COUNT(gp.postulante_id)::INTEGER as total_estudiantes')
                )
                ->groupBy('g.grupo_id', 'g.gestion_id', 'g.codigo', 'g.capacidad_maxima', 'g.turno', 'g.estado')
                ->orderBy('g.codigo')
                ->get();

            $pendientes = DB::table('admision.postulante as p')
                ->leftJoin('admision.grupo_postulante as gp', 'gp.postulante_id', '=', 'p.postulante_id')
                ->when($gestionId, fn ($query) => $query->where('p.gestion_id', $gestionId))
                ->whereNull('gp.postulante_id')
                ->whereIn('p.estado', ['REGISTRADO', 'INSCRITO'])
                ->count();

            return [
                'gestion' => $gestion,
                'total_inscritos' => $totalInscritos,
                'grupos_necesarios' => (int) ceil($totalInscritos / 70),
                'grupos_habilitados' => $grupos->count(),
                'pendientes_sin_grupo' => $pendientes,
                'grupos' => $grupos->map(fn ($grupo) => (new GrupoResource($grupo))->resolve()),
            ];
        }));
    }

    // MODULO GRUPOS - crea grupo, asigna postulantes pendientes y prepara horarios automaticos.
    public function storeGrupo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gestion_id' => ['required', 'exists:gestion_academica,gestion_id'],
            'codigo' => ['required', 'max:30'],
            'capacidad_maxima' => ['required', 'integer', 'min:1', 'max:70'],
            'turno' => ['nullable', Rule::in(['MANANA', 'TARDE', 'TARDE_NOCHE'])],
        ]);

        $grupoId = DB::table('admision.grupo')->insertGetId([
            'gestion_id' => $data['gestion_id'],
            'codigo' => $data['codigo'],
            'capacidad_maxima' => $data['capacidad_maxima'],
            'turno' => $data['turno'] ?? 'MANANA',
            'estado' => 'ACTIVO',
            'creado_en' => now(),
        ], 'grupo_id');

        $this->groupAssignmentService->prepareNewGroup($grupoId, $data['gestion_id']);
        $this->clearAdminCache();

        return response()->json(['grupo_id' => $grupoId], 201);
    }

    // MODULO GRUPOS - edita codigo, capacidad y estado del grupo.
    public function updateGrupo(Request $request, int $grupoId): JsonResponse
    {
        $grupo = DB::table('admision.grupo')->where('grupo_id', $grupoId)->first();
        abort_unless($grupo, 404, 'Grupo no encontrado.');

        $data = $request->validate([
            'codigo' => ['required', 'max:30'],
            'capacidad_maxima' => ['required', 'integer', 'min:1', 'max:70'],
            'turno' => ['nullable', Rule::in(['MANANA', 'TARDE', 'TARDE_NOCHE'])],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ]);

        DB::table('admision.grupo')->where('grupo_id', $grupoId)->update($data);
        $this->clearAdminCache();

        return response()->json(['ok' => true]);
    }

    // MODULO GRUPOS - elimina grupo y relaciones dependientes por ON DELETE CASCADE.
    public function deleteGrupo(int $grupoId): JsonResponse
    {
        DB::table('admision.grupo')->where('grupo_id', $grupoId)->delete();
        $this->clearAdminCache();

        return response()->json(['ok' => true]);
    }

    // MODULO GRUPOS - muestra estudiantes inscritos dentro de un grupo.
    public function estudiantesGrupo(int $grupoId): JsonResponse
    {
        return response()->json(
            DB::table('admision.grupo_postulante as gp')
                ->join('admision.postulante as p', 'p.postulante_id', '=', 'gp.postulante_id')
                ->where('gp.grupo_id', $grupoId)
                ->select('p.postulante_id', 'p.ci', 'p.nombres', 'p.apellidos', 'p.correo', 'p.estado')
                ->orderBy('p.apellidos')
                ->orderBy('p.nombres')
                ->get()
        );
    }

    // MODULO GRUPOS - asigna postulantes pendientes a grupos activos con cupo.
    public function asignarGrupos(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gestion_id' => ['required', 'exists:gestion_academica,gestion_id'],
        ]);

        $asignados = $this->groupAssignmentService->assignPendingPostulantes($data['gestion_id']);
        $this->clearAdminCache();

        return response()->json([
            'ok' => true,
            'asignados' => $asignados,
        ]);
    }
}
