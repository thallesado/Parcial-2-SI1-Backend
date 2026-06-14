<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocentePortalController extends Controller
{
    // PORTAL DOCENTE - devuelve solo los bloques asignados al docente autenticado.
    public function horarios(Request $request): JsonResponse
    {
        $docenteId = $request->attributes->get('auth_session')['docente_id'] ?? null;
        abort_unless($docenteId, 403, 'Cuenta docente no vinculada.');

        $horarios = DB::table('admision.horario_clase as hc')
            ->join('admision.grupo as g', 'g.grupo_id', '=', 'hc.grupo_id')
            ->join('admision.materia as m', 'm.materia_id', '=', 'hc.materia_id')
            ->join('admision.aula as a', 'a.aula_id', '=', 'hc.aula_id')
            ->where('hc.docente_id', $docenteId)
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
                'a.codigo as aula',
                'hc.dia',
                DB::raw("to_char(hc.hora_inicio, 'HH24:MI') as hora_inicio"),
                DB::raw("to_char(hc.hora_fin, 'HH24:MI') as hora_fin")
            )
            ->orderByDesc('g.gestion_id')
            ->orderByRaw("array_position(ARRAY['LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO','DOMINGO'], hc.dia::TEXT)")
            ->orderBy('hc.hora_inicio')
            ->get();

        return response()->json(['horarios' => $horarios]);
    }
}
