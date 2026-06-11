<?php

namespace App\Services;

use App\Models\Materia;
use Illuminate\Support\Facades\DB;
use Throwable;

class ScheduleService
{
    // SERVICIO HORARIOS - genera bloques iniciales para un grupo nuevo si existen aula y docente disponibles.
    public function createAutomaticSchedulesForGroup(int $grupoId): int
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

            if (!$docente || $this->findConflict($grupoId, (int) $docente->docente_id, (int) $aula->aula_id, $bloque[0], $bloque[1], $bloque[2])) {
                continue;
            }

            try {
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
            } catch (Throwable) {
                continue;
            }
        }

        return $creados;
    }

    // SERVICIO HORARIOS - valida choques de grupo, docente y aula para un bloque horario.
    public function findConflict(int $grupoId, int $docenteId, int $aulaId, string $dia, string $inicio, string $fin): ?string
    {
        $conflicto = DB::table('admision.horario_clase as hc')
            ->join('admision.grupo as g', 'g.grupo_id', '=', 'hc.grupo_id')
            ->join('admision.materia as m', 'm.materia_id', '=', 'hc.materia_id')
            ->join('admision.aula as a', 'a.aula_id', '=', 'hc.aula_id')
            ->where('hc.dia', $dia)
            ->whereRaw('?::time < hc.hora_fin AND ?::time > hc.hora_inicio', [$inicio, $fin])
            ->where(function ($query) use ($grupoId, $docenteId, $aulaId) {
                $query->where('hc.grupo_id', $grupoId)
                    ->orWhere('hc.docente_id', $docenteId)
                    ->orWhere('hc.aula_id', $aulaId);
            })
            ->select('g.codigo as grupo', 'm.nombre as materia', 'a.codigo as aula', 'hc.grupo_id', 'hc.docente_id', 'hc.aula_id')
            ->first();

        if (!$conflicto) {
            return null;
        }

        if ((int) $conflicto->grupo_id === $grupoId) {
            return "El grupo {$conflicto->grupo} ya tiene {$conflicto->materia} en ese horario.";
        }

        if ((int) $conflicto->docente_id === $docenteId) {
            return "El docente ya esta asignado a {$conflicto->materia} en el grupo {$conflicto->grupo}.";
        }

        return "El aula {$conflicto->aula} ya esta ocupada por {$conflicto->materia} del grupo {$conflicto->grupo}.";
    }
}
