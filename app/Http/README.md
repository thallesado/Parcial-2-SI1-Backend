# Carpeta app/Http

Contiene la capa HTTP del backend: controladores, middleware y resources.

## Subcarpetas

- `Controllers/`: endpoints del sistema.
- `Middleware/`: validaciones antes de llegar al controlador.
- `Resources/`: transforma datos a JSON estable.

## Flujo de una peticion

```text
Frontend -> routes/api.php -> Middleware -> Controller -> Service/Model -> Resource -> JSON
```

## Cuando modificar esta carpeta

- Si necesitas crear un nuevo endpoint.
- Si quieres cambiar una respuesta JSON.
- Si quieres proteger una ruta.
- Si quieres cambiar mensajes de error HTTP.
