<?php

use App\Http\Controllers\Api\AdminCatalogController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminDocenteController;
use App\Http\Controllers\Api\AdminExamenController;
use App\Http\Controllers\Api\AdminGroupController;
use App\Http\Controllers\Api\AdminHorarioController;
use App\Http\Controllers\Api\AdminPostulanteController;
use App\Http\Controllers\Api\AdminReporteController;
use App\Http\Controllers\Api\AdminUsuarioController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DocentePortalController;
use App\Http\Controllers\Api\PublicInscripcionController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login']);
Route::get('portal', [AdminController::class, 'portal']);
Route::get('inscripciones/opciones', [PublicInscripcionController::class, 'opciones']);
Route::post('inscripciones/preparar', [PublicInscripcionController::class, 'preparar']);
Route::post('stripe/webhook', [PublicInscripcionController::class, 'webhook']);
Route::get('inscripciones/{token}/estado', [PublicInscripcionController::class, 'estado']);
Route::post('inscripciones/{token}/cancelar', [PublicInscripcionController::class, 'cancelar']);
Route::get('inscripciones/{token}', [PublicInscripcionController::class, 'detalle']);
Route::get('inscripciones/{token}/boleta.pdf', [PublicInscripcionController::class, 'boletaPdf']);

Route::middleware(\App\Http\Middleware\AdminSessionMiddleware::class)->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    Route::prefix('admin')->group(function () {
        // Catalogos de lectura necesarios para filtros y formularios segun el perfil.
        Route::middleware('role:ADMINISTRADOR,SECRETARIA,DOCENTE')->group(function () {
            Route::get('gestiones', [AdminCatalogController::class, 'gestiones']);
        });

        Route::middleware('role:ADMINISTRADOR,SECRETARIA')->group(function () {
            Route::get('carreras', [AdminCatalogController::class, 'carreras']);
            Route::get('aulas', [AdminCatalogController::class, 'aulas']);
            Route::get('grupos/resumen', [AdminGroupController::class, 'gruposResumen']);
            Route::get('grupos/{grupoId}/estudiantes', [AdminGroupController::class, 'estudiantesGrupo']);
            Route::get('postulantes', [AdminPostulanteController::class, 'postulantes']);
            Route::get('reportes/{tipo}', [AdminReporteController::class, 'reportes']);
            Route::get('reportes/{tipo}/exportar/{formato}', [AdminReporteController::class, 'exportar']);
        });

        Route::middleware('role:ADMINISTRADOR,DOCENTE')->group(function () {
            Route::get('examenes/postulantes', [AdminExamenController::class, 'postulantesExamenes']);
            Route::get('examenes/notas/{postulante}', [AdminExamenController::class, 'notasPostulante']);
            Route::post('examenes/notas/{postulante}', [AdminExamenController::class, 'storeNotasPostulante']);
        });

        Route::middleware('role:DOCENTE')->group(function () {
            Route::get('docente/horarios', [DocentePortalController::class, 'horarios']);
        });

        // Solo el administrador puede cambiar configuracion o datos maestros.
        Route::middleware('role:ADMINISTRADOR')->group(function () {
            Route::get('dashboard', [AdminController::class, 'dashboard']);
            Route::get('bitacora', [AdminPostulanteController::class, 'bitacora']);
            Route::get('portal', [AdminController::class, 'portal']);
            Route::put('portal', [AdminController::class, 'updatePortal']);

            Route::get('usuarios', [AdminUsuarioController::class, 'index']);
            Route::post('usuarios', [AdminUsuarioController::class, 'store']);
            Route::put('usuarios/{usuarioId}', [AdminUsuarioController::class, 'update']);

            Route::post('gestiones', [AdminCatalogController::class, 'storeGestion']);

            Route::post('carreras', [AdminCatalogController::class, 'storeCarrera']);
            Route::put('carreras/{carrera}', [AdminCatalogController::class, 'updateCarrera']);
            Route::delete('carreras/{carrera}', [AdminCatalogController::class, 'deleteCarrera']);

            Route::get('materias', [AdminCatalogController::class, 'materias']);
            Route::post('materias', [AdminCatalogController::class, 'storeMateria']);
            Route::put('materias/{materia}', [AdminCatalogController::class, 'updateMateria']);
            Route::delete('materias/{materia}', [AdminCatalogController::class, 'deleteMateria']);

            Route::post('aulas', [AdminCatalogController::class, 'storeAula']);
            Route::delete('aulas/{aula}', [AdminCatalogController::class, 'deleteAula']);

            Route::post('grupos', [AdminGroupController::class, 'storeGrupo']);
            Route::post('grupos/asignar', [AdminGroupController::class, 'asignarGrupos']);
            Route::put('grupos/{grupoId}', [AdminGroupController::class, 'updateGrupo']);
            Route::delete('grupos/{grupoId}', [AdminGroupController::class, 'deleteGrupo']);

            Route::get('docentes', [AdminDocenteController::class, 'docentes']);
            Route::post('docentes', [AdminDocenteController::class, 'storeDocente']);
            Route::post('docentes/{docente}/actualizar', [AdminDocenteController::class, 'updateDocente']);
            Route::delete('docentes/{docente}', [AdminDocenteController::class, 'deleteDocente']);
            Route::get('docentes/{docente}/documentos/{tipoRequisitoId}', [AdminDocenteController::class, 'descargarDocumento']);
            Route::put('docentes/{docente}/documentos/{tipoRequisitoId}/estado', [AdminDocenteController::class, 'actualizarEstadoDocumento']);
            Route::get('docentes/asignaciones/grupos', [AdminDocenteController::class, 'docenteAsignaciones']);
            Route::post('docentes/asignaciones/grupos', [AdminDocenteController::class, 'asignarDocenteGrupoMateria']);

            Route::get('horarios', [AdminHorarioController::class, 'horarios']);
            Route::post('horarios', [AdminHorarioController::class, 'storeHorario']);
            Route::delete('horarios/{horarioId}', [AdminHorarioController::class, 'deleteHorario']);

            Route::post('postulantes', [AdminPostulanteController::class, 'storePostulante']);
            Route::put('postulantes/{postulante}', [AdminPostulanteController::class, 'updatePostulante']);
            Route::delete('postulantes/{postulante}', [AdminPostulanteController::class, 'deletePostulante']);

            Route::get('cupos', [AdminCatalogController::class, 'cupos']);
            Route::post('cupos', [AdminCatalogController::class, 'storeCupo']);
        });
    });
});
