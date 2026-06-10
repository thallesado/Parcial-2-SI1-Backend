<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Aula;
use App\Models\Carrera;
use App\Models\CarreraCupo;
use App\Models\Docente;
use App\Models\GestionAcademica;
use App\Models\Materia;
use App\Models\NotaExamen;
use App\Models\Postulante;
use App\Models\PostulanteCarreraOpcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class AdminController extends Controller
{
    // MODULO DASHBOARD - resumen principal para tarjetas del panel administrativo.
    public function dashboard(): JsonResponse
    {
        $gestion = GestionAcademica::query()->orderByDesc('gestion_id')->first();

        $summary = DB::table('admision.v_resumen_dashboard')
            ->when($gestion, fn ($query) => $query->where('gestion_id', $gestion->gestion_id))
            ->first();

        $estadoPostulantes = DB::table('admision.resultado_admision as ra')
            ->join('admision.postulante as p', 'p.postulante_id', '=', 'ra.postulante_id')
            ->when($gestion, fn ($query) => $query->where('p.gestion_id', $gestion->gestion_id))
            ->selectRaw("COUNT(*) FILTER (WHERE ra.estado_admision = 'ADMITIDO')::INTEGER as admitidos")
            ->selectRaw("COUNT(*) FILTER (WHERE ra.estado_admision = 'SIN_CUPO')::INTEGER as aprobados_sin_cupo")
            ->selectRaw("COUNT(*) FILTER (WHERE ra.estado_academico = 'REPROBADO')::INTEGER as reprobados")
            ->first();

        $inscripcionesMes = DB::table('admision.postulante')
            ->when($gestion, fn ($query) => $query->where('gestion_id', $gestion->gestion_id))
            ->selectRaw('EXTRACT(MONTH FROM fecha_registro)::INTEGER as mes_numero, COUNT(*)::INTEGER as total')
            ->groupByRaw('EXTRACT(MONTH FROM fecha_registro)')
            ->orderBy('mes_numero')
            ->get()
            ->map(fn ($row) => [
                'mes' => ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'][$row->mes_numero - 1] ?? $row->mes_numero,
                'total' => $row->total,
            ]);

        $carrerasInscritos = DB::table('admision.postulante_carrera_opcion as pco')
            ->join('admision.postulante as p', 'p.postulante_id', '=', 'pco.postulante_id')
            ->join('admision.carrera as c', 'c.carrera_id', '=', 'pco.carrera_id')
            ->where('pco.orden', 1)
            ->when($gestion, fn ($query) => $query->where('p.gestion_id', $gestion->gestion_id))
            ->select('c.nombre')
            ->selectRaw('COUNT(*)::INTEGER as total')
            ->groupBy('c.nombre')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        $actividadReciente = DB::table('admision.bitacora_accion')
            ->orderByDesc('fecha_hora')
            ->limit(5)
            ->get(['accion', 'descripcion', 'fecha_hora']);

        return response()->json([
            'gestion_activa' => $gestion,
            'resumen' => $summary,
            'estado_postulantes' => $estadoPostulantes,
            'inscripciones_mes' => $inscripcionesMes,
            'carreras_inscritos' => $carrerasInscritos,
            'actividad_reciente' => $actividadReciente,
            'carreras' => Carrera::query()->where('estado', 'ACTIVO')->count(),
            'materias' => Materia::query()->orderBy('nombre')->count(),
            'docentes' => Docente::query()->where('estado', 'ACTIVO')->count(),
            'aulas' => Aula::query()->where('estado', 'ACTIVO')->count(),
        ]);
    }

    // MODULO PORTAL PUBLICO - entrega carreras activas para la pagina principal.
    // La imagen y logo se dejaron configurables desde frontend/src/app/page.tsx.
    public function portal(): JsonResponse
    {
        $config = DB::table('admision.portal_configuracion')
            ->pluck('valor', 'clave');

        return response()->json([
            'hero_background_url' => $config->get('hero_background_url', ''),
            'logo_url' => $config->get('logo_url', ''),
            'carreras' => Carrera::query()
                ->where('estado', 'ACTIVO')
                ->orderBy('nombre')
                ->get(['carrera_id', 'codigo', 'nombre']),
        ]);
    }

    // MODULO PORTAL CONFIG - ruta historica para guardar URLs del portal.
    // No se muestra en el panel porque el proyecto pidio que logo/fondo se cambien solo por codigo.
    public function updatePortal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hero_background_url' => ['nullable', 'max:500'],
            'logo_url' => ['nullable', 'max:500'],
        ]);

        foreach (['hero_background_url', 'logo_url'] as $clave) {
            DB::table('admision.portal_configuracion')->updateOrInsert(
                ['clave' => $clave],
                [
                    'valor' => $data[$clave] ?? '',
                    'descripcion' => $clave === 'hero_background_url'
                        ? 'URL o ruta publica de la imagen de fondo de la portada'
                        : 'URL o ruta publica del logo principal de la universidad',
                    'actualizado_en' => now(),
                ]
            );
        }

        return $this->portal();
    }

    // MODULO GESTIONES - lista gestiones academicas con formato YYYY-S.
    public function gestiones(): JsonResponse
    {
        return response()->json(GestionAcademica::query()->orderByDesc('gestion_id')->get());
    }

    // MODULO GESTIONES - crea una gestion academica validando rango de fechas.
    public function storeGestion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gestion_id' => ['required', 'regex:/^[0-9]{4}-[12]$/', 'unique:gestion_academica,gestion_id'],
            'nombre' => ['required', 'max:40'],
            'fecha_inicio_inscripcion' => ['required', 'date'],
            'fecha_fin_inscripcion' => ['required', 'date', 'after_or_equal:fecha_inicio_inscripcion'],
            'estado' => ['required', Rule::in(['PLANIFICADA', 'INSCRIPCION_ABIERTA', 'INSCRIPCION_CERRADA', 'FINALIZADA'])],
        ]);

        return response()->json(GestionAcademica::query()->create($data), 201);
    }

    // MODULO CARRERAS - lista carreras para CRUD, postulantes, cupos y portada publica.
    public function carreras(): JsonResponse
    {
        return response()->json(Carrera::query()->orderBy('nombre')->get());
    }

    // MODULO CARRERAS - registra una carrera nueva con codigo y nombre unicos.
    public function storeCarrera(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'max:20', 'unique:carrera,codigo'],
            'nombre' => ['required', 'max:120', 'unique:carrera,nombre'],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ]);

        return response()->json(Carrera::query()->create($data), 201);
    }

    // MODULO CARRERAS - modifica datos basicos de una carrera.
    public function updateCarrera(Request $request, Carrera $carrera): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'max:20', Rule::unique('carrera', 'codigo')->ignore($carrera->carrera_id, 'carrera_id')],
            'nombre' => ['required', 'max:120', Rule::unique('carrera', 'nombre')->ignore($carrera->carrera_id, 'carrera_id')],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ]);

        $carrera->update($data);

        return response()->json($carrera);
    }

    // MODULO CARRERAS - elimina solo si PostgreSQL no detecta relaciones restrictivas.
    public function deleteCarrera(Carrera $carrera): JsonResponse
    {
        try {
            $carrera->delete();
        } catch (Throwable) {
            return response()->json([
                'message' => 'No se puede eliminar la carrera porque ya esta relacionada con cupos, postulantes o resultados.',
            ], 409);
        }

        return response()->json(['ok' => true]);
    }

    // MODULO MATERIAS - lista materias evaluadas y asignables a docentes.
    public function materias(): JsonResponse
    {
        return response()->json(Materia::query()->orderBy('nombre')->get());
    }

    // MODULO MATERIAS - registra materia con codigo y nombre unicos.
    public function storeMateria(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'max:20', 'unique:materia,codigo'],
            'nombre' => ['required', 'max:80', 'unique:materia,nombre'],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ]);

        return response()->json(Materia::query()->create($data), 201);
    }

    // MODULO MATERIAS - modifica materia existente.
    public function updateMateria(Request $request, Materia $materia): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'max:20', Rule::unique('materia', 'codigo')->ignore($materia->materia_id, 'materia_id')],
            'nombre' => ['required', 'max:80', Rule::unique('materia', 'nombre')->ignore($materia->materia_id, 'materia_id')],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ]);

        $materia->update($data);

        return response()->json($materia);
    }

    // MODULO MATERIAS - elimina si no tiene notas, horarios o relaciones.
    public function deleteMateria(Materia $materia): JsonResponse
    {
        try {
            $materia->delete();
        } catch (Throwable) {
            return response()->json([
                'message' => 'No se puede eliminar la materia porque ya tiene notas, horarios o docentes relacionados.',
            ], 409);
        }

        return response()->json(['ok' => true]);
    }

    // MODULO AULAS - lista aulas para el modulo Grupos y Aulas.
    public function aulas(): JsonResponse
    {
        return response()->json(Aula::query()->orderBy('codigo')->get());
    }

    // MODULO AULAS - crea aula con capacidad y ubicacion.
    public function storeAula(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'max:30', 'unique:aula,codigo'],
            'nombre' => ['required', 'max:80'],
            'capacidad' => ['required', 'integer', 'min:1', 'max:200'],
            'ubicacion' => ['nullable', 'max:120'],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ]);

        return response()->json(Aula::query()->create($data), 201);
    }

    // MODULO AULAS - elimina aula desde acciones de tabla.
    public function deleteAula(Aula $aula): JsonResponse
    {
        $aula->delete();

        return response()->json(['ok' => true]);
    }

    // MODULO GRUPOS - calcula inscritos, grupos necesarios, grupos habilitados y pendientes.
    public function gruposResumen(Request $request): JsonResponse
    {
        $gestion = GestionAcademica::query()
            ->when($request->query('gestion_id'), fn ($query, $gestionId) => $query->where('gestion_id', $gestionId))
            ->orderByDesc('gestion_id')
            ->first();
        $gestionId = $gestion?->gestion_id;

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
                'g.estado',
                DB::raw('COUNT(gp.postulante_id)::INTEGER as total_estudiantes')
            )
            ->groupBy('g.grupo_id', 'g.gestion_id', 'g.codigo', 'g.capacidad_maxima', 'g.estado')
            ->orderBy('g.codigo')
            ->get();

        $pendientes = DB::table('admision.postulante as p')
            ->leftJoin('admision.grupo_postulante as gp', 'gp.postulante_id', '=', 'p.postulante_id')
            ->when($gestionId, fn ($query) => $query->where('p.gestion_id', $gestionId))
            ->whereNull('gp.postulante_id')
            ->whereIn('p.estado', ['REGISTRADO', 'INSCRITO'])
            ->count();

        return response()->json([
            'gestion' => $gestion,
            'total_inscritos' => $totalInscritos,
            'grupos_necesarios' => (int) ceil($totalInscritos / 70),
            'grupos_habilitados' => $grupos->count(),
            'pendientes_sin_grupo' => $pendientes,
            'grupos' => $grupos,
        ]);
    }

    // MODULO GRUPOS - crea grupo, asigna postulantes pendientes y prepara horarios automaticos.
    public function storeGrupo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gestion_id' => ['required', 'exists:gestion_academica,gestion_id'],
            'codigo' => ['required', 'max:30'],
            'capacidad_maxima' => ['required', 'integer', 'min:1', 'max:70'],
        ]);

        $grupoId = DB::table('admision.grupo')->insertGetId([
            'gestion_id' => $data['gestion_id'],
            'codigo' => $data['codigo'],
            'capacidad_maxima' => $data['capacidad_maxima'],
            'estado' => 'ACTIVO',
            'creado_en' => now(),
        ], 'grupo_id');

        $this->asignarPendientesAGrupos($data['gestion_id']);
        $this->asignarHorariosAutomaticos($grupoId);

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
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ]);

        DB::table('admision.grupo')
            ->where('grupo_id', $grupoId)
            ->update($data);

        return response()->json(['ok' => true]);
    }

    // MODULO GRUPOS - elimina grupo y relaciones dependientes por ON DELETE CASCADE.
    public function deleteGrupo(int $grupoId): JsonResponse
    {
        DB::table('admision.grupo')->where('grupo_id', $grupoId)->delete();

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

        $asignados = $this->asignarPendientesAGrupos($data['gestion_id']);

        return response()->json([
            'ok' => true,
            'asignados' => $asignados,
        ]);
    }

    // MODULO DOCENTES - lista docentes junto a las materias que pueden dictar.
    public function docentes(): JsonResponse
    {
        $docentes = Docente::query()->orderBy('apellidos')->orderBy('nombres')->get();
        $materias = DB::table('admision.docente_materia as dm')
            ->join('admision.materia as m', 'm.materia_id', '=', 'dm.materia_id')
            ->select('dm.docente_id', 'm.materia_id', 'm.nombre')
            ->orderBy('m.nombre')
            ->get()
            ->groupBy('docente_id');

        return response()->json($docentes->map(function (Docente $docente) use ($materias) {
            $asignadas = $materias->get($docente->docente_id, collect());
            $docente->materia_ids = $asignadas->pluck('materia_id')->values();
            $docente->materias = $asignadas->pluck('nombre')->values()->implode(', ');

            return $docente;
        }));
    }

    // MODULO ASIGNACION DOCENTES - arma la matriz grupo + materia + docente para la interfaz.
    // Si no existen filas grupo_materia_docente, las crea para cada grupo activo y materia activa.
    public function docenteAsignaciones(Request $request): JsonResponse
    {
        $gestion = $request->query('gestion_id')
            ? GestionAcademica::query()->where('gestion_id', $request->query('gestion_id'))->first()
            : GestionAcademica::query()->orderByDesc('gestion_id')->first();
        $gestionId = $gestion?->gestion_id;

        if ($gestionId) {
            $this->asegurarAsignacionesGrupoMateria($gestionId);
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
            'docentes' => $this->docentesDisponiblesPorMateria(),
            'asignaciones' => $asignaciones,
            'resumen' => [
                'materias_programadas' => $asignaciones->count(),
                'docentes_disponibles' => Docente::query()->where('estado', 'ACTIVO')->count(),
                'sin_asignar' => $asignaciones->where('docente_id', null)->count(),
            ],
        ]);
    }

    // MODULO ASIGNACION DOCENTES - asigna/reasigna docente a una materia de un grupo.
    // Valida que el docente dicte la materia y que no supere cuatro grupos.
    public function asignarDocenteGrupoMateria(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grupo_id' => ['required', 'exists:grupo,grupo_id'],
            'materia_id' => ['required', 'exists:materia,materia_id'],
            'docente_id' => ['required', 'exists:docente,docente_id'],
            'observacion' => ['nullable', 'max:200'],
        ]);

        $puedeDictar = DB::table('admision.docente_materia')
            ->where('docente_id', $data['docente_id'])
            ->where('materia_id', $data['materia_id'])
            ->exists();

        if (!$puedeDictar) {
            return response()->json(['message' => 'El docente seleccionado no esta habilitado para dictar esta materia.'], 422);
        }

        $gruposAsignados = DB::table('admision.grupo_materia_docente')
            ->where('docente_id', $data['docente_id'])
            ->where('grupo_id', '<>', $data['grupo_id'])
            ->distinct()
            ->count('grupo_id');

        if ($gruposAsignados >= 4) {
            return response()->json(['message' => 'El docente ya tiene 4 grupos asignados.'], 422);
        }

        DB::table('admision.grupo_materia_docente')->updateOrInsert(
            ['grupo_id' => $data['grupo_id'], 'materia_id' => $data['materia_id']],
            [
                'docente_id' => $data['docente_id'],
                'observacion' => $data['observacion'] ?? null,
                'asignado_en' => now(),
            ]
        );

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
            $this->syncDocenteMaterias($docente->docente_id, $materiaIds);

            return $docente;
        });

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
            $this->syncDocenteMaterias($docente->docente_id, $materiaIds);
        });

        return response()->json($docente);
    }

    // MODULO DOCENTES - elimina docente si no tiene restricciones en horarios u otras tablas.
    public function deleteDocente(Docente $docente): JsonResponse
    {
        try {
            $docente->delete();
        } catch (Throwable) {
            return response()->json([
                'message' => 'No se puede eliminar el docente porque ya esta asignado en horarios.',
            ], 409);
        }

        return response()->json(['ok' => true]);
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

        return response()->json($query->paginate($perPage));
    }

    // MODULO BITACORA - entrega ultimos movimientos del administrador.
    public function bitacora(): JsonResponse
    {
        return response()->json(
            DB::table('admision.bitacora_accion')
                ->orderByDesc('fecha_hora')
                ->limit(300)
                ->get()
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

            $this->asignarPostulanteAGrupoDisponible($postulante->postulante_id, $postulante->gestion_id);

            return $postulante;
        });

        return response()->json($postulante, 201);
    }

    // MODULO POSTULANTES - modifica solo datos editables; las carreras no se cambian.
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

        return response()->json($postulante);
    }

    // MODULO POSTULANTES - elimina postulante; trigger impide borrar si ya tiene notas.
    public function deletePostulante(Postulante $postulante): JsonResponse
    {
        try {
            $postulante->delete();
        } catch (QueryException $exception) {
            return response()->json([
                'message' => str_contains($exception->getMessage(), 'notas registradas')
                    ? 'No se puede eliminar el postulante porque ya tiene notas registradas.'
                    : 'No se puede eliminar el postulante porque tiene informacion relacionada que debe revisarse.',
            ], 409);
        }

        return response()->json(['ok' => true]);
    }

    // MODULO EXAMENES - lista postulantes paginados para selector lateral.
    // Usa resultado_admision ya procesado para evitar recalcular promedios en cada listado.
    public function postulantesExamenes(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 50), 10), 100);
        $search = trim((string) $request->query('search', ''));
        $gestionId = $request->query('gestion_id');
        $sexo = $request->query('sexo');
        $estado = $request->query('estado');

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

        return response()->json($query->paginate($perPage));
    }

    // MODULO EXAMENES - carga notas de un postulante por materia y examen.
    public function notasPostulante(Postulante $postulante): JsonResponse
    {
        $materias = Materia::query()->where('estado', 'ACTIVO')->orderBy('materia_id')->get();
        $notas = DB::table('admision.nota_examen')
            ->where('postulante_id', $postulante->postulante_id)
            ->get()
            ->groupBy('materia_id');

        $resultado = DB::table('admision.v_promedios_postulante as vp')
            ->leftJoin('admision.v_resultados_admision as vr', 'vr.postulante_id', '=', 'vp.postulante_id')
            ->where('vp.postulante_id', $postulante->postulante_id)
            ->select(
                'vp.*',
                'vr.estado_academico',
                'vr.estado_admision',
                'vr.carrera_admitida',
                'vr.procesado_en'
            )
            ->first();

        return response()->json([
            'postulante' => $postulante,
            'resultado' => $resultado,
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

        return $this->notasPostulante($postulante);
    }

    // MODULO REPORTES - entrega datos desde vistas SQL segun el tipo solicitado.
    public function reportes(string $tipo): JsonResponse
    {
        $map = [
            'postulantes' => DB::table('admision.v_postulantes_general'),
            'aprobados' => DB::table('admision.v_resultados_admision')->where('estado_academico', 'APROBADO'),
            'reprobados' => DB::table('admision.v_resultados_admision')->where('estado_academico', 'REPROBADO'),
            'promedios' => DB::table('admision.v_promedios_postulante'),
            'grupos' => DB::table('admision.v_grupos_estudiantes'),
            'estadisticas-materia' => DB::table('admision.v_estadisticas_por_materia'),
            'docentes-grupos' => DB::table('admision.v_docentes_por_grupo'),
            'grupos-aprobados' => DB::table('admision.v_grupos_mayor_cantidad_aprobados'),
        ];

        abort_unless(isset($map[$tipo]), 404, 'Reporte no encontrado.');

        return response()->json($map[$tipo]->limit(500)->get());
    }

    // VALIDACIONES POSTULANTES - mensajes humanos para errores de Laravel.
    private function postulanteMessages(): array
    {
        return [
            'ci.required' => 'El CI es obligatorio.',
            'ci.unique' => 'El CI ya esta registrado.',
            'correo.required' => 'El correo electronico es obligatorio.',
            'correo.email' => 'El correo electronico no tiene un formato valido.',
            'correo.unique' => 'El correo electronico ya esta registrado.',
            'nombres.required' => 'Los nombres son obligatorios.',
            'apellidos.required' => 'Los apellidos son obligatorios.',
            'fecha_nacimiento.required' => 'La fecha de nacimiento es obligatoria.',
            'sexo.required' => 'El sexo es obligatorio.',
            'direccion.required' => 'La direccion es obligatoria.',
            'telefono.required' => 'El telefono es obligatorio.',
            'colegio_procedencia.required' => 'El colegio de procedencia es obligatorio.',
            'ciudad.required' => 'La ciudad es obligatoria.',
            'titulo_bachiller_codigo.required' => 'El codigo de titulo de bachiller es obligatorio.',
            'carrera_opcion_1.required' => 'La primera carrera postulada es obligatoria.',
            'carrera_opcion_2.required' => 'La segunda carrera postulada es obligatoria.',
            'carrera_opcion_2.different' => 'La segunda carrera debe ser diferente de la primera.',
        ];
    }

    // VALIDACIONES HELPER - elimina espacios al inicio/fin antes de validar campos obligatorios.
    private function trimStrings(array $data): array
    {
        return collect($data)
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->all();
    }

    // ASIGNACION GRUPOS HELPER - busca primer grupo activo con cupo y asigna el postulante.
    private function asignarPostulanteAGrupoDisponible(int $postulanteId, string $gestionId): bool
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

        DB::table('admision.grupo_postulante')->updateOrInsert(
            ['postulante_id' => $postulanteId],
            ['grupo_id' => $grupo->grupo_id, 'asignado_en' => now()]
        );

        DB::table('admision.postulante')
            ->where('postulante_id', $postulanteId)
            ->where('estado', 'REGISTRADO')
            ->update(['estado' => 'INSCRITO']);

        return true;
    }

    // ASIGNACION GRUPOS HELPER - recorre postulantes sin grupo de una gestion.
    private function asignarPendientesAGrupos(string $gestionId): int
    {
        $pendientes = DB::table('admision.postulante as p')
            ->leftJoin('admision.grupo_postulante as gp', 'gp.postulante_id', '=', 'p.postulante_id')
            ->where('p.gestion_id', $gestionId)
            ->whereNull('gp.postulante_id')
            ->whereIn('p.estado', ['REGISTRADO', 'INSCRITO'])
            ->orderBy('p.fecha_registro')
            ->pluck('p.postulante_id');

        $asignados = 0;

        foreach ($pendientes as $postulanteId) {
            if ($this->asignarPostulanteAGrupoDisponible((int) $postulanteId, $gestionId)) {
                $asignados++;
            }
        }

        return $asignados;
    }

    // DOCENTES HELPER - reemplaza materias habilitadas de un docente.
    private function syncDocenteMaterias(int $docenteId, array $materiaIds): void
    {
        DB::table('admision.docente_materia')->where('docente_id', $docenteId)->delete();

        DB::table('admision.docente_materia')->insert(
            collect($materiaIds)->map(fn ($materiaId) => [
                'docente_id' => $docenteId,
                'materia_id' => $materiaId,
                'asignado_en' => now(),
            ])->all()
        );
    }

    // ASIGNACION DOCENTES HELPER - asegura filas grupo/materia para poder asignar docentes.
    private function asegurarAsignacionesGrupoMateria(string $gestionId): void
    {
        $grupos = DB::table('admision.grupo')
            ->where('gestion_id', $gestionId)
            ->where('estado', 'ACTIVO')
            ->pluck('grupo_id');
        $materias = Materia::query()->where('estado', 'ACTIVO')->pluck('materia_id');

        foreach ($grupos as $grupoId) {
            foreach ($materias as $materiaId) {
                DB::table('admision.grupo_materia_docente')->updateOrInsert(
                    ['grupo_id' => $grupoId, 'materia_id' => $materiaId],
                    ['asignado_en' => now()]
                );
            }
        }
    }

    // ASIGNACION DOCENTES HELPER - calcula disponibilidad restante por docente y materia.
    private function docentesDisponiblesPorMateria()
    {
        return DB::table('admision.docente as d')
            ->join('admision.docente_materia as dm', 'dm.docente_id', '=', 'd.docente_id')
            ->leftJoin('admision.grupo_materia_docente as gmd', 'gmd.docente_id', '=', 'd.docente_id')
            ->where('d.estado', 'ACTIVO')
            ->select(
                'd.docente_id',
                'dm.materia_id',
                DB::raw("d.nombres || ' ' || d.apellidos as docente"),
                DB::raw('GREATEST(0, 4 - COUNT(DISTINCT gmd.grupo_id))::INTEGER as grupos_disponibles')
            )
            ->groupBy('d.docente_id', 'dm.materia_id', 'd.nombres', 'd.apellidos')
            ->orderBy('d.apellidos')
            ->get();
    }

    // HORARIOS HELPER - intento basico de generar horario cuando hay aula y docente disponible.
    // Este bloque queda preparado para evolucionar el modulo formal de horarios.
    private function asignarHorariosAutomaticos(int $grupoId): int
    {
        $grupo = DB::table('admision.grupo')->where('grupo_id', $grupoId)->first();
        if (!$grupo) {
            return 0;
        }

        $aula = DB::table('admision.aula')
            ->where('estado', 'ACTIVO')
            ->where('capacidad', '>=', $grupo->capacidad_maxima)
            ->orderBy('capacidad')
            ->first()
            ?? DB::table('admision.aula')->where('estado', 'ACTIVO')->orderByDesc('capacidad')->first();

        if (!$aula) {
            return 0;
        }

        $materias = Materia::query()->where('estado', 'ACTIVO')->orderBy('materia_id')->get();
        $bloques = [
            ['LUNES', '08:00', '09:30'],
            ['MARTES', '08:00', '09:30'],
            ['MIERCOLES', '08:00', '09:30'],
            ['JUEVES', '08:00', '09:30'],
            ['VIERNES', '08:00', '09:30'],
        ];
        $creados = 0;

        foreach ($materias as $index => $materia) {
            $bloque = $bloques[$index % count($bloques)];
            $docente = DB::table('admision.docente as d')
                ->join('admision.docente_materia as dm', 'dm.docente_id', '=', 'd.docente_id')
                ->where('d.estado', 'ACTIVO')
                ->where('dm.materia_id', $materia->materia_id)
                ->whereNotExists(function ($query) use ($bloque) {
                    $query->selectRaw('1')
                        ->from('admision.horario_clase as hc')
                        ->whereColumn('hc.docente_id', 'd.docente_id')
                        ->where('hc.dia', $bloque[0])
                        ->where('hc.hora_inicio', $bloque[1]);
                })
                ->orderBy('d.apellidos')
                ->first();

            if (!$docente) {
                continue;
            }

            DB::table('admision.horario_clase')->updateOrInsert(
                [
                    'grupo_id' => $grupoId,
                    'materia_id' => $materia->materia_id,
                    'dia' => $bloque[0],
                    'hora_inicio' => $bloque[1],
                ],
                [
                    'docente_id' => $docente->docente_id,
                    'aula_id' => $aula->aula_id,
                    'hora_fin' => $bloque[2],
                ]
            );
            $creados++;
        }

        return $creados;
    }

    // MODULO CUPOS - lista cupos por carrera desde vista SQL.
    public function cupos(): JsonResponse
    {
        return response()->json(DB::table('admision.v_cupos_por_carrera')->orderBy('gestion')->orderBy('carrera')->get());
    }

    // MODULO CUPOS - crea o actualiza cupo por carrera y gestion.
    public function storeCupo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gestion_id' => ['required', 'exists:gestion_academica,gestion_id'],
            'carrera_id' => ['required', 'exists:carrera,carrera_id'],
            'cupo' => ['required', 'integer', 'min:0', 'max:200'],
        ]);

        DB::table('admision.carrera_cupo')->updateOrInsert(
            ['gestion_id' => $data['gestion_id'], 'carrera_id' => $data['carrera_id']],
            ['cupo' => $data['cupo']]
        );

        return response()->json(['ok' => true], 201);
    }
}


