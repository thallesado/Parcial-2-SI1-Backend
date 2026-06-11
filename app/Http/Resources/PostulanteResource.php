<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostulanteResource extends JsonResource
{
    // RESOURCE POSTULANTE - estabiliza el JSON que consume React aunque cambien consultas internas.
    public function toArray(Request $request): array
    {
        return [
            'postulante_id' => $this->postulante_id,
            'gestion_id' => $this->gestion_id,
            'gestion' => $this->gestion ?? null,
            'ci' => $this->ci,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'fecha_nacimiento' => $this->fecha_nacimiento ?? null,
            'sexo' => $this->sexo ?? null,
            'direccion' => $this->direccion ?? null,
            'telefono' => $this->telefono ?? null,
            'correo' => $this->correo,
            'colegio_procedencia' => $this->colegio_procedencia ?? null,
            'ciudad' => $this->ciudad ?? null,
            'titulo_bachiller_codigo' => $this->titulo_bachiller_codigo ?? null,
            'carrera_opcion_1' => $this->carrera_opcion_1 ?? null,
            'carrera_opcion_2' => $this->carrera_opcion_2 ?? null,
            'estado' => $this->estado,
            'fecha_registro' => $this->fecha_registro ?? null,
            'grupo_id' => $this->grupo_id ?? null,
            'grupo_asignado' => $this->grupo_asignado ?? null,
        ];
    }
}
