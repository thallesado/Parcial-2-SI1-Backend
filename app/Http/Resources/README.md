# API Resources

Los API Resources definen como se convierten los datos del backend a JSON para el frontend.

## Por que existen

Sin resources, cada controlador podria devolver columnas con nombres diferentes. Con resources, el frontend recibe una estructura estable aunque cambie la consulta SQL interna.

## Resources actuales

- `PostulanteResource.php`: datos de postulantes, grupo asignado y carreras elegidas.
- `GrupoResource.php`: datos resumidos de grupos.
- `DocenteResource.php`: datos de docentes y materias que puede dictar.
- `ResultadoAdmisionResource.php`: promedio, estado academico, estado de admision y carrera admitida.

## Cuando crear uno nuevo

Crea un resource si:

- una respuesta se usa en varias pantallas;
- quieres ocultar columnas internas;
- quieres renombrar datos para que el frontend sea mas claro;
- quieres evitar repetir `select` y transformaciones en varios controladores.
