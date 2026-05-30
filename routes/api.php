<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login']);
Route::get('portal', [AdminController::class, 'portal']);

Route::middleware(\App\Http\Middleware\AdminSessionMiddleware::class)->group(function () {
Route::post('auth/logout', [AuthController::class, 'logout']);
Route::get('auth/me', [AuthController::class, 'me']);

Route::prefix('admin')->group(function () {
    Route::get('dashboard', [AdminController::class, 'dashboard']);
    Route::get('bitacora', [AdminController::class, 'bitacora']);
    Route::get('portal', [AdminController::class, 'portal']);
    Route::put('portal', [AdminController::class, 'updatePortal']);

    Route::get('gestiones', [AdminController::class, 'gestiones']);
    Route::post('gestiones', [AdminController::class, 'storeGestion']);

    Route::get('carreras', [AdminController::class, 'carreras']);
    Route::post('carreras', [AdminController::class, 'storeCarrera']);
    Route::put('carreras/{carrera}', [AdminController::class, 'updateCarrera']);
    Route::delete('carreras/{carrera}', [AdminController::class, 'deleteCarrera']);

    Route::get('materias', [AdminController::class, 'materias']);
    Route::post('materias', [AdminController::class, 'storeMateria']);
    Route::put('materias/{materia}', [AdminController::class, 'updateMateria']);
    Route::delete('materias/{materia}', [AdminController::class, 'deleteMateria']);

    Route::get('aulas', [AdminController::class, 'aulas']);
    Route::post('aulas', [AdminController::class, 'storeAula']);
    Route::delete('aulas/{aula}', [AdminController::class, 'deleteAula']);

    Route::get('grupos/resumen', [AdminController::class, 'gruposResumen']);
    Route::post('grupos', [AdminController::class, 'storeGrupo']);
    Route::post('grupos/asignar', [AdminController::class, 'asignarGrupos']);
    Route::get('grupos/{grupoId}/estudiantes', [AdminController::class, 'estudiantesGrupo']);
    Route::put('grupos/{grupoId}', [AdminController::class, 'updateGrupo']);
    Route::delete('grupos/{grupoId}', [AdminController::class, 'deleteGrupo']);

    Route::get('docentes', [AdminController::class, 'docentes']);
    Route::post('docentes', [AdminController::class, 'storeDocente']);
    Route::put('docentes/{docente}', [AdminController::class, 'updateDocente']);
    Route::delete('docentes/{docente}', [AdminController::class, 'deleteDocente']);
    Route::get('docentes/asignaciones/grupos', [AdminController::class, 'docenteAsignaciones']);
    Route::post('docentes/asignaciones/grupos', [AdminController::class, 'asignarDocenteGrupoMateria']);

    Route::get('postulantes', [AdminController::class, 'postulantes']);
    Route::post('postulantes', [AdminController::class, 'storePostulante']);
    Route::put('postulantes/{postulante}', [AdminController::class, 'updatePostulante']);
    Route::delete('postulantes/{postulante}', [AdminController::class, 'deletePostulante']);

    Route::get('examenes/postulantes', [AdminController::class, 'postulantesExamenes']);
    Route::get('examenes/notas/{postulante}', [AdminController::class, 'notasPostulante']);
    Route::post('examenes/notas/{postulante}', [AdminController::class, 'storeNotasPostulante']);

    Route::get('cupos', [AdminController::class, 'cupos']);
    Route::post('cupos', [AdminController::class, 'storeCupo']);

    Route::get('reportes/{tipo}', [AdminController::class, 'reportes']);
});
});
