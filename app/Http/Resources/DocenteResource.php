<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocenteResource extends JsonResource
{
    // RESOURCE DOCENTE - mantiene una salida clara con materias habilitadas para el formulario React.
    public function toArray(Request $request): array
    {
        return [
            'docente_id' => $this->docente_id,
            'ci' => $this->ci,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'telefono' => $this->telefono,
            'correo' => $this->correo,
            'especialidad' => $this->especialidad,
            'estado' => $this->estado,
            'materia_ids' => $this->materia_ids ?? [],
            'materias' => $this->materias ?? '',
        ];
    }
}
