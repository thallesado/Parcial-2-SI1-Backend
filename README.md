# Backend Laravel

Esta carpeta contiene la API del sistema. El frontend consume esta API para login, postulantes, examenes, cupos, grupos, docentes, horarios, reportes, bitacora e inscripcion publica.

## Que tecnologia usa

- PHP 8.3 o superior
- Laravel 12
- Composer
- PostgreSQL
- API REST JSON
- Blade y DomPDF para generar boletas y reportes PDF

## Instalacion

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Configura `.env` con los datos de PostgreSQL:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=admision_universitaria
DB_USERNAME=postgres
DB_PASSWORD=
DB_SCHEMA=admision
```

## Ejecutar localmente

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

URL base:

```text
http://127.0.0.1:8000/api
```

## Carpetas importantes

- `app/`: codigo principal del backend.
- `app/Http/Controllers/Api/`: endpoints que consume el frontend.
- `app/Services/`: reglas de negocio reutilizables.
- `app/Models/`: modelos Eloquent.
- `app/Http/Resources/`: formato de las respuestas JSON.
- `config/`: configuracion Laravel y credenciales admin.
- `routes/`: definicion de rutas.
- `resources/views/pdf/`: plantilla de boleta PDF.
- `resources/views/exports/`: plantillas de reportes compatibles con Excel.
- `public/`: punto de entrada HTTP.
- `storage/`: cache, logs y archivos generados localmente.

## Comandos utiles

```powershell
php artisan route:list
php artisan route:list --path=api/admin
php artisan optimize:clear
php artisan config:clear
php artisan cache:clear
```

## Despliegue

El backend esta preparado para Render usando `Dockerfile`. En Render se deben configurar las variables de entorno, especialmente:

- `APP_KEY`
- `APP_ENV`
- `APP_DEBUG`
- `APP_URL`
- `DB_CONNECTION`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `DB_SCHEMA`

No subas `.env` real al repositorio.

## Seguridad por roles

- `ADMINISTRADOR`: acceso completo.
- `SECRETARIA`: postulantes en modo consulta, grupos y reportes.
- `DOCENTE`: notas de los estudiantes de sus grupos y horarios propios.

`AdminSessionMiddleware` valida la sesion y `RoleMiddleware` controla cada grupo
de rutas. La configuracion visual equivalente esta en `config/roles.php`.

Los documentos docentes se guardan en la columna `BYTEA` de
`docente_requisito`; asi permanecen disponibles aunque el contenedor de Render
se reinicie.
