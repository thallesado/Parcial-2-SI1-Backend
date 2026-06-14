# Configuracion Laravel

Contiene archivos de configuracion del backend.

## Archivos importantes

- `app.php`: nombre de app, zona horaria y configuracion general.
- `database.php`: conexion a PostgreSQL.
- `cache.php`: configuracion de cache.
- `logging.php`: logs.
- `session.php`: sesiones.
- `queue.php`: colas.
- `admin_credentials.php`: duracion y parametros generales de sesion.
- `roles.php`: prioridad y modulos visibles de cada rol.

## Credenciales y roles

Las cuentas y contrasenas ya no se leen desde archivos de configuracion. Se
almacenan en PostgreSQL mediante `usuario`, `rol`, `usuario_rol` y
`usuario_docente`. `config/roles.php` solamente define que secciones corresponden
a cada perfil.

## Nota de seguridad

No coloques contrasenas reales directamente en archivos versionados si el proyecto sera publico.
