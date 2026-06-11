# Rutas Laravel

Define que URL llama a cada controlador.

## Archivos

- `api.php`: rutas JSON usadas por frontend.
- `console.php`: comandos de consola Laravel.

## Como revisar rutas

```powershell
php artisan route:list
php artisan route:list --path=api/admin
```

## Rutas principales

- `/api/auth/*`: login, logout y sesion.
- `/api/admin/*`: panel administrativo.
- `/api/inscripcion/*`: formulario publico de inscripcion.

## Recomendacion

Cuando agregues un nuevo modulo, registra primero la ruta aqui y luego crea el metodo en el controlador correspondiente.
