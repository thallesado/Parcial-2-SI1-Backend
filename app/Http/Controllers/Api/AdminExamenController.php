<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AdminShared;
use App\Http\Controllers\Controller;
use App\Http\Resources\ResultadoAdmisionResource;
use App\Models\Materia;
use App\Models\Postulante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminExamenController extends Controller
{
    use AdminShared;

    // MODULO EXAMENES - lista postulantes paginados para selector lateral.
    // Usa resultado_admision ya procesado para evitar recalcular promedios en cada listado.
    public function postulantesExamenes(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 50), 10), 100);
        $search = trim((string) $request->query('search', ''));
        $gestionId = $request->query('gestion_id');
        $sexo = $request->query('sexo');
        $estado = $request->query('estado');
        $docenteId = $this->docenteIdAutenticado($request);

        $query = DB::table('admision.postulante as p')
            ->leftJoin('admision.resultado_admision as ra', 'ra.postulante_id', '=', 'p.postulante_id')
            ->leftJoin('admision.carrera as c', 'c.carrera_id', '=', 'ra.carrera_admitida_id')
            ->when($search !== '', function ($query) use ($search) {
                $like = "%{$search}%";

                $query->where(function ($inner) use ($like) {
                    $inner->where('p.ci', 'ILIKE', $like)
                        ->orWhere('p.nombres', 'ILIKE', $like)
                        ->orWhere('p.apellidos', 'ILIKE', $like);
                });
            })
            ->when($gestionId, fn ($query) => $query->where('p.gestion_id', $gestionId))
            ->when($sexo, fn ($query) => $query->where('p.sexo', $sexo))
            ->when($estado === 'ADMITIDO', fn ($query) => $query->where('ra.estado_admision', 'ADMITIDO'))
            ->when($estado === 'SIN_CUPO', fn ($query) => $query->where('ra.estado_admision', 'SIN_CUPO'))
            ->when($estado === 'REPROBADO', fn ($query) => $query->where('ra.estado_academico', 'REPROBADO'))
            ->when($docenteId, function ($query) use ($docenteId) {
                $query->whereExists(function ($subquery) use ($docenteId) {
                    $subquery->selectRaw('1')
                        ->from('admision.grupo_postulante as gp_docente')
                        ->join('admision.grupo_materia_docente as gmd_docente', 'gmd_docente.grupo_id', '=', 'gp_docente.grupo_id')
                        ->whereColumn('gp_docente.postulante_id', 'p.postulante_id')
                        ->where('gmd_docente.docente_id', $docenteId);
                });
            })
            ->select(
                'p.postulante_id',
                'p.gestion_id',
                'p.ci',
                'p.nombres',
                'p.apellidos',
                'p.sexo',
                DB::raw("COALESCE(ra.promedio_final, 0)::NUMERIC(5,2) as promedio_final"),
                DB::raw("COALESCE(ra.promedio_desempate, 0)::NUMERIC(5,2) as promedio_desempate"),
                DB::raw("COALESCE(ra.estado_academico::TEXT, 'PENDIENTE') as estado_academico_calculado"),
                'ra.estado_academico',
                'ra.estado_admision',
                DB::raw('c.nombre as carrera_admitida')
            )
            ->orderByDesc('p.gestion_id')
            ->orderBy('p.apellidos')
            ->orderBy('p.nombres');

        return response()->json(
            $query->paginate($perPage)->through(fn ($row) => (new ResultadoAdmisionResource($row))->resolve())
        );
    }

    // MODULO EXAMENES - carga notas de un postulante por materia y examen.
    public function notasPostulante(Request $request, Postulante $postulante): JsonResponse
    {
        $materiaIds = $this->materiasAutorizadas($request, $postulante);
        $materias = Materia::query()
            ->where('estado', 'ACTIVO')
            ->when($materiaIds !== null, fn ($query) => $query->whereIn('materia_id', $materiaIds))
            ->orderBy('materia_id')
            ->get();
        $notas = DB::table('admision.nota_examen')
            ->where('postulante_id', $postulante->postulante_id)
            ->when($materiaIds !== null, fn ($query) => $query->whereIn('materia_id', $materiaIds))
            ->get()
            ->groupBy('materia_id');

        $resultado = DB::table('admision.resultado_admision as ra')
            ->leftJoin('admision.carrera as c', 'c.carrera_id', '=', 'ra.carrera_admitida_id')
            ->where('ra.postulante_id', $postulante->postulante_id)
            ->select(
                'ra.postulante_id',
                'ra.promedio_final',
                'ra.promedio_desempate',
                DB::raw('ra.estado_academico as estado_academico_calculado'),
                'ra.estado_academico',
                'ra.estado_admision',
                DB::raw('c.nombre as carrera_admitida'),
                'ra.procesado_en'
            )
            ->first();

        return response()->json([
            'postulante' => $postulante,
            'resultado' => $resultado ? (new ResultadoAdmisionResource($resultado))->resolve() : null,
            'materias' => $materias->map(function (Materia $materia) use ($notas) {
                $rows = $notas->get($materia->materia_id, collect())->keyBy('numero_examen');

                return [
                    'materia_id' => $materia->materia_id,
                    'codigo' => $materia->codigo,
                    'nombre' => $materia->nombre,
                    'examen_1' => $rows->get(1)?->nota,
                    'examen_2' => $rows->get(2)?->nota,
                    'examen_3' => $rows->get(3)?->nota,
                ];
            })->values(),
        ]);
    }

    // MODULO EXAMENES - guarda tres examenes por materia y reprocesa admision.
    public function storeNotasPostulante(Request $request, Postulante $postulante): JsonResponse
    {
        $data = $request->validate([
            'notas' => ['required', 'array'],
            'notas.*.materia_id' => ['required', 'exists:materia,materia_id'],
            'notas.*.examen_1' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notas.*.examen_2' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notas.*.examen_3' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $materiasAutorizadas = $this->materiasAutorizadas($request, $postulante);
        if ($materiasAutorizadas !== null) {
            $solicitadas = collect($data['notas'])->pluck('materia_id')->map(fn ($id) => (int) $id);
            abort_if($solicitadas->diff($materiasAutorizadas)->isNotEmpty(), 403, 'No tiene permiso para registrar notas en una de las materias seleccionadas.');
        }

        DB::transaction(function () use ($data, $postulante) {
            foreach ($data['notas'] as $notaMateria) {
                foreach ([1, 2, 3] as $numeroExamen) {
                    $campo = 'examen_'.$numeroExamen;

                    DB::table('admision.nota_examen')->updateOrInsert(
                        [
                            'postulante_id' => $postulante->postulante_id,
                            'materia_id' => $notaMateria['materia_id'],
                            'numero_examen' => $numeroExamen,
                        ],
                        [
                            'nota' => $notaMateria[$campo] === null || $notaMateria[$campo] === '' ? null : $notaMateria[$campo],
                            'actualizado_en' => now(),
                        ]
                    );
                }
            }

            DB::select('SELECT admision.procesar_admisiones(?)', [$postulante->gestion_id]);
        });
        $this->clearAdminCache();

        return $this->notasPostulante($request, $postulante);
    }

    private function docenteIdAutenticado(Request $request): ?int
    {
        $session = $request->attributes->get('auth_session', []);
        $roles = $session['roles'] ?? [];

        if (in_array('ADMINISTRADOR', $roles, true)) {
            return null;
        }

        $docenteId = $session['docente_id'] ?? null;
        abort_unless($docenteId, 403, 'El usuario docente no esta vinculado a un registro de docente.');

        return (int) $docenteId;
    }

    /**
     * El administrador recibe null y conserva acceso a todas las materias.
     * Para un docente devuelve solo las materias que dicta al grupo del postulante.
     */
    private function materiasAutorizadas(Request $request, Postulante $postulante): ?array
    {
        $docenteId = $this->docenteIdAutenticado($request);
        if ($docenteId === null) {
            return null;
        }

        $materiaIds = DB::table('admision.grupo_postulante as gp')
            ->join('admision.grupo_materia_docente as gmd', 'gmd.grupo_id', '=', 'gp.grupo_id')
            ->where('gp.postulante_id', $postulante->postulante_id)
            ->where('gmd.docente_id', $docenteId)
            ->pluck('gmd.materia_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        abort_if($materiaIds === [], 403, 'El postulante no pertenece a un grupo asignado a este docente.');

        return $materiaIds;
    }
}
