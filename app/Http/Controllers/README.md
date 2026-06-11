# Carpeta Controllers

Contiene controladores Laravel. En este proyecto, la mayor parte esta dentro de `Api/` porque el frontend se comunica con el backend por JSON.

## Archivos y carpetas

- `Controller.php`: controlador base de Laravel.
- `Api/`: controladores reales del sistema.

## Regla practica

Un controlador no deberia tener demasiada logica pesada. Si una accion necesita reglas complejas, debe apoyarse en `app/Services/`.
