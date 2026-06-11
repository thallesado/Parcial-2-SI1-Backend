<?php

namespace App\Services;

use App\Models\Materia;
use Illuminate\Support\Facades\DB;

class TeacherAssignmentService
{
    // SERVICIO DOCENTES - reemplaza las materias habilitadas de un docente.
    public function syncDocenteMaterias(int $docenteId, array $materiaIds): void
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

    // SERVICIO DOCENTES - asegura una fila grupo/materia para cada materia activa de cada grupo activo.
    public function ensureGroupSubjectRows(string $gestionId): void
    {
        $grupos = DB::table('admision.grupo')
            ->where('gestion_id', $gestionId)
            ->where('estado', 'ACTIVO')
            ->pluck('grupo_id');
        $materias = Materia::query()->where('estado', 'ACTIVO')->pluck('materia_id');
        $now = now();
        $rows = [];

        foreach ($grupos as $grupoId) {
            foreach ($materias as $materiaId) {
                $rows[] = [
                    'grupo_id' => $grupoId,
                    'materia_id' => $materiaId,
                    'asignado_en' => $now,
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        // Insercion masiva: evita un roundtrip por cada grupo/materia en Render.
        DB::table('admision.grupo_materia_docente')->insertOrIgnore($rows);
    }

    // SERVICIO DOCENTES - calcula cuantos grupos mas puede tomar cada docente por materia.
    public function availableTeachersBySubject()
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

    // SERVICIO DOCENTES - valida que el docente pueda dictar la materia y no exceda cuatro grupos.
    public function validateTeacherCanBeAssigned(int $grupoId, int $materiaId, int $docenteId): ?string
    {
        $puedeDictar = DB::table('admision.docente_materia')
            ->where('docente_id', $docenteId)
            ->where('materia_id', $materiaId)
            ->exists();

        if (!$puedeDictar) {
            return 'El docente seleccionado no esta habilitado para dictar esta materia.';
        }

        $gruposAsignados = DB::table('admision.grupo_materia_docente')
            ->where('docente_id', $docenteId)
            ->where('grupo_id', '<>', $grupoId)
            ->distinct()
            ->count('grupo_id');

        if ($gruposAsignados >= 4) {
            return 'El docente ya tiene 4 grupos asignados.';
        }

        return null;
    }

    // SERVICIO DOCENTES - persiste la asignacion docente/materia/grupo.
    public function assignTeacherToGroupSubject(int $grupoId, int $materiaId, int $docenteId, ?string $observacion): void
    {
        DB::table('admision.grupo_materia_docente')->updateOrInsert(
            ['grupo_id' => $grupoId, 'materia_id' => $materiaId],
            [
                'docente_id' => $docenteId,
                'observacion' => $observacion,
                'asignado_en' => now(),
            ]
        );
    }
}
