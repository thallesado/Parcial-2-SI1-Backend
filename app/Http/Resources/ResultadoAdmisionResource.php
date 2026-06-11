<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResultadoAdmisionResource extends JsonResource
{
    // RESOURCE RESULTADO - salida estable para examenes y reportes basados en resultado_admision.
    public function toArray(Request $request): array
    {
        return [
            'postulante_id' => $this->postulante_id,
            'gestion_id' => $this->gestion_id ?? null,
            'ci' => $this->ci ?? null,
            'nombres' => $this->nombres ?? null,
            'apellidos' => $this->apellidos ?? null,
            'sexo' => $this->sexo ?? null,
            'promedio_final' => $this->promedio_final ?? '0.00',
            'promedio_desempate' => $this->promedio_desempate ?? '0.00',
            'estado_academico_calculado' => $this->estado_academico_calculado ?? $this->estado_academico ?? 'PENDIENTE',
            'estado_academico' => $this->estado_academico ?? null,
            'estado_admision' => $this->estado_admision ?? null,
            'carrera_admitida' => $this->carrera_admitida ?? null,
            'procesado_en' => $this->procesado_en ?? null,
        ];
    }
}
