# Controladores API

Aqui estan los endpoints JSON que consume el frontend.

## Controladores principales

- `AuthController.php`: login, logout y sesion con roles/prioridades.
- `AdminController.php`: dashboard, portal y datos administrativos generales.
- `AdminCatalogController.php`: carreras, materias, aulas, cupos y gestiones.
- `AdminPostulanteController.php`: postulantes, busqueda, filtros, edicion, eliminacion y bitacora.
- `AdminExamenController.php`: notas, promedios y resultado de admision.
- `AdminDocenteController.php`: docentes, documentos profesionales y asignacion a materias por grupo.
- `AdminGroupController.php`: grupos, estudiantes por grupo y asignacion automatica.
- `AdminHorarioController.php`: horarios, validacion de choques y eliminacion.
- `AdminReporteController.php`: reportes paginados y exportaciones PDF/Excel.
- `AdminUsuarioController.php`: cuentas, roles y vinculacion de cuentas docentes.
- `DocentePortalController.php`: horarios exclusivos del docente autenticado.
- `PublicInscripcionController.php`: formulario publico, pago con Stripe en modo test, asignacion de grupo y boleta PDF.

## Como leer un controlador

1. Busca el metodo que corresponde a la ruta en `routes/api.php`.
2. Revisa las validaciones.
3. Mira si llama a un `Service`.
4. Revisa la respuesta JSON o el `Resource` usado.

## Ejemplos de busqueda en VS Code

```text
storePostulante
postulantesExamenes
asignarDocenteGrupoMateria
reportes
webhook
```

## Carpeta Concerns

`Concerns/AdminShared.php` contiene helpers compartidos por controladores administrativos, como limpiar cache o normalizar textos.
