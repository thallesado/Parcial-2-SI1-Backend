<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResultadoAdmisionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminReporteController extends Controller
{
    // MODULO REPORTES - entrega datos de reportes, priorizando resultado_admision ya procesado.
    public function reportes(Request $request, string $tipo): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 10), 100);
        $gestionId = $request->query('gestion_id');

        $resultadosBase = DB::table('admision.resultado_admision as ra')
            ->join('admision.postulante as p', 'p.postulante_id', '=', 'ra.postulante_id')
            ->leftJoin('admision.carrera as c', 'c.carrera_id', '=', 'ra.carrera_admitida_id')
            ->when($gestionId, fn ($query) => $query->where('p.gestion_id', $gestionId))
            ->select(
                'p.postulante_id',
                'p.gestion_id',
                'p.ci',
                'p.nombres',
                'p.apellidos',
                'p.sexo',
                'ra.promedio_final',
                'ra.promedio_desempate',
                'ra.estado_academico',
                'ra.estado_admision',
                DB::raw('c.nombre as carrera_admitida'),
                'ra.procesado_en'
            );

        $postulantesBase = DB::table('admision.postulante as p')
            ->join('admision.gestion_academica as ga', 'ga.gestion_id', '=', 'p.gestion_id')
            ->leftJoin('admision.postulante_carrera_opcion as pco1', fn ($join) => $join->on('pco1.postulante_id', '=', 'p.postulante_id')->where('pco1.orden', 1))
            ->leftJoin('admision.carrera as c1', 'c1.carrera_id', '=', 'pco1.carrera_id')
            ->leftJoin('admision.postulante_carrera_opcion as pco2', fn ($join) => $join->on('pco2.postulante_id', '=', 'p.postulante_id')->where('pco2.orden', 2))
            ->leftJoin('admision.carrera as c2', 'c2.carrera_id', '=', 'pco2.carrera_id')
            ->when($gestionId, fn ($query) => $query->where('p.gestion_id', $gestionId))
            ->select(
                'p.postulante_id',
                'p.gestion_id',
                'ga.nombre as gestion',
                'p.ci',
                'p.nombres',
                'p.apellidos',
                'p.sexo',
                'p.correo',
                'c1.nombre as carrera_opcion_1',
                'c2.nombre as carrera_opcion_2',
                'p.estado',
                'p.fecha_registro'
            )
            ->orderByDesc('p.fecha_registro');

        $gruposBase = DB::table('admision.grupo as g')
            ->join('admision.gestion_academica as ga', 'ga.gestion_id', '=', 'g.gestion_id')
            ->leftJoin('admision.grupo_postulante as gp', 'gp.grupo_id', '=', 'g.grupo_id')
            ->when($gestionId, fn ($query) => $query->where('g.gestion_id', $gestionId))
            ->select('g.gestion_id', 'ga.nombre as gestion', 'g.grupo_id', 'g.codigo as grupo', 'g.capacidad_maxima')
            ->selectRaw('COUNT(gp.postulante_id)::INTEGER AS total_estudiantes')
            ->groupBy('g.gestion_id', 'ga.nombre', 'g.grupo_id', 'g.codigo', 'g.capacidad_maxima')
            ->orderBy('g.codigo');

        $docentesGruposBase = DB::table('admision.horario_clase as hc')
            ->join('admision.grupo as g', 'g.grupo_id', '=', 'hc.grupo_id')
            ->join('admision.gestion_academica as ga', 'ga.gestion_id', '=', 'g.gestion_id')
            ->join('admision.materia as m', 'm.materia_id', '=', 'hc.materia_id')
            ->join('admision.docente as d', 'd.docente_id', '=', 'hc.docente_id')
            ->join('admision.aula as a', 'a.aula_id', '=', 'hc.aula_id')
            ->when($gestionId, fn ($query) => $query->where('g.gestion_id', $gestionId))
            ->select(
                'g.gestion_id',
                'ga.nombre as gestion',
                'g.codigo as grupo',
                'm.nombre as materia',
                'd.ci as docente_ci',
                'd.nombres as docente_nombres',
                'd.apellidos as docente_apellidos',
                'a.codigo as aula',
                'hc.dia',
                'hc.hora_inicio',
                'hc.hora_fin'
            )
            ->distinct()
            ->orderBy('g.codigo');

        $gruposAprobadosBase = DB::table('admision.grupo as g')
            ->join('admision.gestion_academica as ga', 'ga.gestion_id', '=', 'g.gestion_id')
            ->leftJoin('admision.grupo_postulante as gp', 'gp.grupo_id', '=', 'g.grupo_id')
            ->leftJoin('admision.resultado_admision as ra', 'ra.postulante_id', '=', 'gp.postulante_id')
            ->when($gestionId, fn ($query) => $query->where('g.gestion_id', $gestionId))
            ->select('g.gestion_id', 'ga.nombre as gestion', 'g.codigo as grupo')
            ->selectRaw("COUNT(ra.postulante_id) FILTER (WHERE ra.estado_academico = 'APROBADO')::INTEGER AS total_aprobados")
            ->selectRaw('COUNT(gp.postulante_id)::INTEGER AS total_estudiantes')
            ->groupBy('g.gestion_id', 'ga.nombre', 'g.codigo')
            ->orderByDesc('total_aprobados')
            ->orderByDesc('total_estudiantes');

        $estadisticasMateria = DB::table('admision.v_estadisticas_por_materia')
            ->when($gestionId, fn ($query) => $query->where('gestion_id', $gestionId));

        $map = [
            'postulantes' => $postulantesBase,
            'aprobados' => (clone $resultadosBase)->where('ra.estado_academico', 'APROBADO'),
            'reprobados' => (clone $resultadosBase)->where('ra.estado_academico', 'REPROBADO'),
            'promedios' => $resultadosBase,
            'grupos' => $gruposBase,
            'estadisticas-materia' => $estadisticasMateria,
            'docentes-grupos' => $docentesGruposBase,
            'grupos-aprobados' => $gruposAprobadosBase,
        ];

        abort_unless(isset($map[$tipo]), 404, 'Reporte no encontrado.');

        $paginator = $map[$tipo]->paginate($perPage);

        if (in_array($tipo, ['aprobados', 'reprobados', 'promedios'], true)) {
            $paginator = $paginator->through(fn ($row) => (new ResultadoAdmisionResource($row))->resolve());
        }

        return response()->json($paginator);
    }
}
