# Carpeta app

Aqui vive el codigo principal de Laravel. Si quieres cambiar reglas del sistema, endpoints o modelos, casi siempre trabajaras dentro de esta carpeta.

## Subcarpetas

- `Http/`: todo lo relacionado con peticiones HTTP.
- `Models/`: modelos Eloquent que representan tablas de PostgreSQL.
- `Services/`: reglas de negocio que no deben vivir dentro de los controladores.

## Como se conecta todo

1. Una ruta en `routes/api.php` recibe una peticion.
2. La ruta llama a un controlador en `app/Http/Controllers/Api/`.
3. El controlador valida datos y llama a modelos o servicios.
4. Los servicios aplican reglas de negocio.
5. Los resources convierten datos internos en JSON claro para el frontend.

## Buena practica del proyecto

- Controladores: reciben la peticion y devuelven respuesta.
- Services: contienen reglas como asignar grupos, validar horarios o asignar docentes.
- Models: representan tablas y relaciones.
- Resources: definen como se ve el JSON final.
