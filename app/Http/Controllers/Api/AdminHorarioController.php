<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AdminShared;
use App\Http\Controllers\Controller;
use App\Models\Aula;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Services\ScheduleService;

class AdminHorarioController extends Controller
{
    use AdminShared;

    public function __construct(private readonly ScheduleService $scheduleService)
    {
    }

    // MODULO HORARIOS - lista bloques por gestion/grupo para la grilla tipo calendario.
    public function horarios(Request $request): JsonResponse
    {
        $gestionId = $request->query('gestion_id');
        $grupoId = $request->query('grupo_id');

        $horarios = DB::table('admision.horario_clase as hc')
            ->join('admision.grupo as g', 'g.grupo_id', '=', 'hc.grupo_id')
            ->join('admision.materia as m', 'm.materia_id', '=', 'hc.materia_id')
            ->join('admision.docente as d', 'd.docente_id', '=', 'hc.docente_id')
            ->join('admision.aula as a', 'a.aula_id', '=', 'hc.aula_id')
            ->when($gestionId, fn ($query) => $query->where('g.gestion_id', $gestionId))
            ->when($grupoId, fn ($query) => $query->where('g.grupo_id', $grupoId))
            ->select(
                'hc.horario_id',
                'hc.grupo_id',
                'hc.materia_id',
                'hc.docente_id',
                'hc.aula_id',
                'g.gestion_id',
                'g.codigo as grupo',
                'g.turno',
                'm.nombre as materia',
                DB::raw("d.nombres || ' ' || d.apellidos as docente"),
                'a.codigo as aula',
                'hc.dia',
                DB::raw("to_char(hc.hora_inicio, 'HH24:MI') as hora_inicio"),
                DB::raw("to_char(hc.hora_fin, 'HH24:MI') as hora_fin")
            )
            ->orderByRaw("array_position(ARRAY['LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO','DOMINGO'], hc.dia::TEXT)")
            ->orderBy('hc.hora_inicio')
            ->get();

        return response()->json([
            'horarios' => $horarios,
            'aulas' => Aula::query()->where('estado', 'ACTIVO')->orderBy('codigo')->get(),
        ]);
    }

    // MODULO HORARIOS - crea un bloque de 90 minutos sin choques de docente, aula ni grupo.
    public function storeHorario(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grupo_id' => ['required', 'exists:grupo,grupo_id'],
            'materia_id' => ['required', 'exists:materia,materia_id'],
            'aula_id' => ['required', 'exists:aula,aula_id'],
            'dia' => ['required', Rule::in(['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO'])],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i'],
        ]);

        $docenteId = DB::table('admision.grupo_materia_docente')
            ->where('grupo_id', $data['grupo_id'])
            ->where('materia_id', $data['materia_id'])
            ->value('docente_id');

        if (!$docenteId) {
            return response()->json(['message' => 'Primero asigna un docente a esta materia y grupo.'], 422);
        }

        $minutes = (strtotime($data['hora_fin']) - strtotime($data['hora_inicio'])) / 60;
        if ($minutes !== 90.0) {
            return response()->json(['message' => 'Cada bloque de clase debe durar exactamente 1 hora y 30 minutos.'], 422);
        }

        if ($data['hora_inicio'] < '07:00' || $data['hora_fin'] > '20:30') {
            return response()->json(['message' => 'Los horarios deben estar entre 07:00 y 20:30.'], 422);
        }

        $conflicto = $this->scheduleService->findConflict(
            (int) $data['grupo_id'],
            (int) $docenteId,
            (int) $data['aula_id'],
            $data['dia'],
            $data['hora_inicio'],
            $data['hora_fin']
        );

        if ($conflicto) {
            return response()->json(['message' => $conflicto], 422);
        }

        $horarioId = DB::table('admision.horario_clase')->insertGetId([
            'grupo_id' => $data['grupo_id'],
            'materia_id' => $data['materia_id'],
            'docente_id' => $docenteId,
            'aula_id' => $data['aula_id'],
            'dia' => $data['dia'],
            'hora_inicio' => $data['hora_inicio'],
            'hora_fin' => $data['hora_fin'],
        ], 'horario_id');
        $this->clearAdminCache();

        return response()->json(['horario_id' => $horarioId], 201);
    }

    // MODULO HORARIOS - elimina un bloque para corregir la programacion.
    public function deleteHorario(int $horarioId): JsonResponse
    {
        DB::table('admision.horario_clase')->where('horario_id', $horarioId)->delete();
        $this->clearAdminCache();

        return response()->json(['ok' => true]);
    }
}
