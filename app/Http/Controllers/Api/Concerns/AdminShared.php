<?php

namespace App\Http\Controllers\Api\Concerns;

trait AdminShared
{
    // CACHE ADMIN - prefijo unico para poder limpiar lecturas administrativas tras guardar cambios.
    protected function adminCachePrefix(): string
    {
        return 'admin:v'.cache()->get('admin_cache_version', 1).':';
    }

    // CACHE ADMIN - invalida lecturas administrativas sin borrar tokens de sesion guardados en cache.
    protected function clearAdminCache(): void
    {
        cache()->forever('admin_cache_version', (int) cache()->get('admin_cache_version', 1) + 1);
    }

    // VALIDACIONES POSTULANTES - mensajes humanos para errores de Laravel.
    protected function postulanteMessages(): array
    {
        return [
            'ci.required' => 'El CI es obligatorio.',
            'ci.unique' => 'El CI ya esta registrado.',
            'correo.required' => 'El correo electronico es obligatorio.',
            'correo.email' => 'El correo electronico no tiene un formato valido.',
            'correo.unique' => 'El correo electronico ya esta registrado.',
            'nombres.required' => 'Los nombres son obligatorios.',
            'apellidos.required' => 'Los apellidos son obligatorios.',
            'fecha_nacimiento.required' => 'La fecha de nacimiento es obligatoria.',
            'sexo.required' => 'El sexo es obligatorio.',
            'direccion.required' => 'La direccion es obligatoria.',
            'telefono.required' => 'El telefono es obligatorio.',
            'colegio_procedencia.required' => 'El colegio de procedencia es obligatorio.',
            'ciudad.required' => 'La ciudad es obligatoria.',
            'titulo_bachiller_codigo.required' => 'El codigo de titulo de bachiller es obligatorio.',
            'carrera_opcion_1.required' => 'La primera carrera postulada es obligatoria.',
            'carrera_opcion_2.required' => 'La segunda carrera postulada es obligatoria.',
            'carrera_opcion_2.different' => 'La segunda carrera debe ser diferente de la primera.',
        ];
    }

    // VALIDACIONES HELPER - elimina espacios al inicio/fin antes de validar campos obligatorios.
    protected function trimStrings(array $data): array
    {
        return collect($data)
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->all();
    }
}
