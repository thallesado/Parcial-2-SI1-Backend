<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class GroupAssignmentService
{
    public function __construct(private readonly ScheduleService $scheduleService)
    {
    }

    // SERVICIO GRUPOS - asigna un postulante al primer grupo activo con cupo disponible.
    public function assignPostulanteToAvailableGroup(int $postulanteId, string $gestionId): bool
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

    // SERVICIO GRUPOS - recorre postulantes pendientes de una gestion y los ubica por orden de registro.
    public function assignPendingPostulantes(string $gestionId): int
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
            if ($this->assignPostulanteToAvailableGroup((int) $postulanteId, $gestionId)) {
                $asignados++;
            }
        }

        return $asignados;
    }

    // SERVICIO GRUPOS - rutina de postcreacion: llena cupos pendientes y prepara horarios base.
    public function prepareNewGroup(int $grupoId, string $gestionId): int
    {
        $this->assignPendingPostulantes($gestionId);

        return $this->scheduleService->createAutomaticSchedulesForGroup($grupoId);
    }
}
