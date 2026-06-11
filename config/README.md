# Configuracion Laravel

Contiene archivos de configuracion del backend.

## Archivos importantes

- `app.php`: nombre de app, zona horaria y configuracion general.
- `database.php`: conexion a PostgreSQL.
- `cache.php`: configuracion de cache.
- `logging.php`: logs.
- `session.php`: sesiones.
- `queue.php`: colas.
- `admin_credentials.php`: usuario y contrasena inicial del administrador.

## Credenciales administrativas

Archivo:

```text
config/admin_credentials.php
```

Credenciales iniciales:

```text
usuario: admin
clave:   Admin1234
```

Para produccion, cambia estas credenciales usando variables de entorno.

## Nota de seguridad

No coloques contrasenas reales directamente en archivos versionados si el proyecto sera publico.
