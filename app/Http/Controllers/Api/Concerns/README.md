# Concerns de controladores

Esta carpeta contiene traits reutilizables para no repetir codigo entre controladores.

## Archivo principal

- `AdminShared.php`: helpers administrativos compartidos.

## Que hace AdminShared

- Genera prefijos de cache administrativa.
- Limpia cache cuando se modifica informacion.
- Define mensajes de validacion de postulantes.
- Normaliza cadenas con `trim`.

## Cuando modificarlo

Modifica esta carpeta solo si varios controladores necesitan el mismo helper. Si la logica pertenece a una regla de negocio, usa mejor `app/Services/`.
