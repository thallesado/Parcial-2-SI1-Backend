<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AdminShared;
use App\Http\Controllers\Controller;
use App\Models\Aula;
use App\Models\Carrera;
use App\Models\GestionAcademica;
use App\Models\Materia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class AdminCatalogController extends Controller
{
    use AdminShared;

    // MODULO GESTIONES - lista gestiones academicas con formato YYYY-S.
    public function gestiones(): JsonResponse
    {
        return response()->json(Cache::remember(
            $this->adminCachePrefix().'gestiones',
            now()->addMinutes(10),
            fn () => GestionAcademica::query()->orderByDesc('gestion_id')->get()
        ));
    }

    // MODULO GESTIONES - crea una gestion academica validando rango de fechas.
    public function storeGestion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gestion_id' => ['required', 'regex:/^[0-9]{4}-[12]$/', 'unique:gestion_academica,gestion_id'],
            'nombre' => ['required', 'max:40'],
            'fecha_inicio_inscripcion' => ['required', 'date'],
            'fecha_fin_inscripcion' => ['required', 'date', 'after_or_equal:fecha_inicio_inscripcion'],
            'estado' => ['required', Rule::in(['PLANIFICADA', 'INSCRIPCION_ABIERTA', 'INSCRIPCION_CERRADA', 'FINALIZADA'])],
        ]);

        $gestion = GestionAcademica::query()->create($data);
        $this->clearAdminCache();

        return response()->json($gestion, 201);
    }

    // MODULO CARRERAS - lista carreras para CRUD, postulantes, cupos y portada publica.
    public function carreras(): JsonResponse
    {
        return response()->json(Cache::remember(
            $this->adminCachePrefix().'carreras',
            now()->addMinutes(10),
            fn () => Carrera::query()->orderBy('nombre')->get()
        ));
    }

    // MODULO CARRERAS - registra una carrera nueva con codigo y nombre unicos.
    public function storeCarrera(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'max:20', 'unique:carrera,codigo'],
            'nombre' => ['required', 'max:120', 'unique:carrera,nombre'],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ]);

        $carrera = Carrera::query()->create($data);
        $this->clearAdminCache();

        return response()->json($carrera, 201);
    }

    // MODULO CARRERAS - modifica datos basicos de una carrera.
    public function updateCarrera(Request $request, Carrera $carrera): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'max:20', Rule::unique('carrera', 'codigo')->ignore($carrera->carrera_id, 'carrera_id')],
            'nombre' => ['required', 'max:120', Rule::unique('carrera', 'nombre')->ignore($carrera->carrera_id, 'carrera_id')],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ]);

        $carrera->update($data);
        $this->clearAdminCache();

        return response()->json($carrera);
    }

    // MODULO CARRERAS - elimina solo si PostgreSQL no detecta relaciones restrictivas.
    public function deleteCarrera(Carrera $carrera): JsonResponse
    {
        try {
            $carrera->delete();
            $this->clearAdminCache();
        } catch (Throwable) {
            return response()->json([
                'message' => 'No se puede eliminar la carrera porque ya esta relacionada con cupos, postulantes o resultados.',
            ], 409);
        }

        return response()->json(['ok' => true]);
    }

    // MODULO MATERIAS - lista materias evaluadas y asignables a docentes.
    public function materias(): JsonResponse
    {
        return response()->json(Cache::remember(
            $this->adminCachePrefix().'materias',
            now()->addMinutes(10),
            fn () => Materia::query()->orderBy('nombre')->get()
        ));
    }

    // MODULO MATERIAS - registra materia con codigo y nombre unicos.
    public function storeMateria(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'max:20', 'unique:materia,codigo'],
            'nombre' => ['required', 'max:80', 'unique:materia,nombre'],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ]);

        $materia = Materia::query()->create($data);
        $this->clearAdminCache();

        return response()->json($materia, 201);
    }

    // MODULO MATERIAS - modifica materia existente.
    public function updateMateria(Request $request, Materia $materia): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'max:20', Rule::unique('materia', 'codigo')->ignore($materia->materia_id, 'materia_id')],
            'nombre' => ['required', 'max:80', Rule::unique('materia', 'nombre')->ignore($materia->materia_id, 'materia_id')],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ]);

        $materia->update($data);
        $this->clearAdminCache();

        return response()->json($materia);
    }

    // MODULO MATERIAS - elimina si no tiene notas, horarios o relaciones.
    public function deleteMateria(Materia $materia): JsonResponse
    {
        try {
            $materia->delete();
            $this->clearAdminCache();
        } catch (Throwable) {
            return response()->json([
                'message' => 'No se puede eliminar la materia porque ya tiene notas, horarios o docentes relacionados.',
            ], 409);
        }

        return response()->json(['ok' => true]);
    }

    // MODULO AULAS - lista aulas para el modulo Grupos y Aulas.
    public function aulas(): JsonResponse
    {
        return response()->json(Cache::remember(
            $this->adminCachePrefix().'aulas',
            now()->addMinutes(10),
            fn () => Aula::query()->orderBy('codigo')->get()
        ));
    }

    // MODULO AULAS - crea aula con capacidad y ubicacion.
    public function storeAula(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'max:30', 'unique:aula,codigo'],
            'nombre' => ['required', 'max:80'],
            'capacidad' => ['required', 'integer', 'min:1', 'max:200'],
            'ubicacion' => ['nullable', 'max:120'],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ]);

        $aula = Aula::query()->create($data);
        $this->clearAdminCache();

        return response()->json($aula, 201);
    }

    // MODULO AULAS - elimina aula desde acciones de tabla.
    public function deleteAula(Aula $aula): JsonResponse
    {
        $aula->delete();
        $this->clearAdminCache();

        return response()->json(['ok' => true]);
    }

    // MODULO CUPOS - lista cupos por carrera desde vista SQL.
    public function cupos(): JsonResponse
    {
        return response()->json(Cache::remember(
            $this->adminCachePrefix().'cupos',
            now()->addMinutes(5),
            fn () => DB::table('admision.v_cupos_por_carrera')->orderBy('gestion')->orderBy('carrera')->get()
        ));
    }

    // MODULO CUPOS - crea o actualiza cupo por carrera y gestion.
    public function storeCupo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gestion_id' => ['required', 'exists:gestion_academica,gestion_id'],
            'carrera_id' => ['required', 'exists:carrera,carrera_id'],
            'cupo' => ['required', 'integer', 'min:0', 'max:200'],
        ]);

        DB::table('admision.carrera_cupo')->updateOrInsert(
            ['gestion_id' => $data['gestion_id'], 'carrera_id' => $data['carrera_id']],
            ['cupo' => $data['cupo']]
        );
        $this->clearAdminCache();

        return response()->json(['ok' => true], 201);
    }
}
