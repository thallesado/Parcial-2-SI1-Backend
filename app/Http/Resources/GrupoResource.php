<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GrupoResource extends JsonResource
{
    // RESOURCE GRUPO - respuesta uniforme para tablas de grupos y asignacion de aulas.
    public function toArray(Request $request): array
    {
        return [
            'grupo_id' => $this->grupo_id,
            'gestion_id' => $this->gestion_id,
            'codigo' => $this->codigo,
            'capacidad_maxima' => $this->capacidad_maxima,
            'turno' => $this->turno ?? null,
            'estado' => $this->estado,
            'total_estudiantes' => $this->total_estudiantes ?? 0,
        ];
    }
}
