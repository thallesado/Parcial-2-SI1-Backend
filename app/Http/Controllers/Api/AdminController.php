<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AdminShared;
use App\Http\Controllers\Controller;
use App\Models\Aula;
use App\Models\Carrera;
use App\Models\Docente;
use App\Models\GestionAcademica;
use App\Models\Materia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    use AdminShared;

    // MODULO DASHBOARD - resumen principal para tarjetas del panel administrativo.
    // Se cachea pocos minutos porque se consulta al entrar y no debe recalcular cada grafico.
    public function dashboard(): JsonResponse
    {
        return response()->json(Cache::remember($this->adminCachePrefix().'dashboard', now()->addMinutes(2), function () {
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

            return [
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
            ];
        }));
    }

    // MODULO PORTAL PUBLICO - entrega carreras activas para la pagina principal.
    // La imagen y logo se siguen configurando por codigo en frontend/src/app/page.tsx.
    public function portal(): JsonResponse
    {
        return response()->json(Cache::remember($this->adminCachePrefix().'portal', now()->addMinutes(10), function () {
            $config = DB::table('admision.portal_configuracion')
                ->pluck('valor', 'clave');

            return [
                'hero_background_url' => $config->get('hero_background_url', ''),
                'logo_url' => $config->get('logo_url', ''),
                'carreras' => Carrera::query()
                    ->where('estado', 'ACTIVO')
                    ->orderBy('nombre')
                    ->get(['carrera_id', 'codigo', 'nombre']),
            ];
        }));
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
        $this->clearAdminCache();

        return $this->portal();
    }
}
