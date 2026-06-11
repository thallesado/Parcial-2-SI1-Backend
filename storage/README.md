# Storage

Laravel usa esta carpeta para archivos temporales, logs, cache y sesiones locales.

## Contenido comun

- `logs/`: errores y actividad local.
- `framework/`: cache, sesiones y vistas compiladas.
- `app/`: archivos internos generados por Laravel.

## Importante

No subas logs ni cache al repositorio. Si Laravel falla por permisos, verifica que `storage/` y `bootstrap/cache/` sean escribibles.

En Docker/Render, el `Dockerfile` ajusta permisos durante el build.
