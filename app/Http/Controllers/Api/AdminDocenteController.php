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

            return $docentes->map(function (Docente $docente) use ($materias) {
                $asignadas = $materias->get($docente->docente_id, collect());
                $docente->materia_ids = $asignadas->pluck('materia_id')->values();
                $docente->materias = $asignadas->pluck('nombre')->values()->implode(', ');

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
        $data = $request->validate([
            'ci' => ['required', 'max:20', 'unique:docente,ci'],
            'nombres' => ['required', 'max:80'],
            'apellidos' => ['required', 'max:80'],
            'telefono' => ['required', 'max:30'],
            'correo' => ['required', 'email', 'max:120', 'unique:docente,correo'],
            'especialidad' => ['required', 'max:120'],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
            'materia_ids' => ['required', 'array', 'min:1'],
            'materia_ids.*' => ['required', 'exists:materia,materia_id'],
        ]);

        $materiaIds = array_values(array_unique($data['materia_ids']));
        unset($data['materia_ids']);

        $docente = DB::transaction(function () use ($data, $materiaIds) {
            $docente = Docente::query()->create($data);
            $this->teacherAssignmentService->syncDocenteMaterias($docente->docente_id, $materiaIds);

            return $docente;
        });
        $this->clearAdminCache();

        return response()->json($docente, 201);
    }

    // MODULO DOCENTES - actualiza datos del docente y reemplaza sus materias habilitadas.
    public function updateDocente(Request $request, Docente $docente): JsonResponse
    {
        $data = $request->validate([
            'ci' => ['required', 'max:20', Rule::unique('docente', 'ci')->ignore($docente->docente_id, 'docente_id')],
            'nombres' => ['required', 'max:80'],
            'apellidos' => ['required', 'max:80'],
            'telefono' => ['required', 'max:30'],
            'correo' => ['required', 'email', 'max:120', Rule::unique('docente', 'correo')->ignore($docente->docente_id, 'docente_id')],
            'especialidad' => ['required', 'max:120'],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
            'materia_ids' => ['required', 'array', 'min:1'],
            'materia_ids.*' => ['required', 'exists:materia,materia_id'],
        ]);

        $materiaIds = array_values(array_unique($data['materia_ids']));
        unset($data['materia_ids']);

        DB::transaction(function () use ($docente, $data, $materiaIds) {
            $docente->update($data);
            $this->teacherAssignmentService->syncDocenteMaterias($docente->docente_id, $materiaIds);
        });
        $this->clearAdminCache();

        return response()->json($docente);
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
