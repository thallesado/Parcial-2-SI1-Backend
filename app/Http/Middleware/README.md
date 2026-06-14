# Middleware

Los middleware se ejecutan antes de los controladores. Sirven para validar, bloquear o preparar una peticion.

## Archivos

- `AdminSessionMiddleware.php`: valida que el usuario tenga sesion activa y comparte su perfil con la peticion.
- `RoleMiddleware.php`: permite o bloquea rutas segun ADMINISTRADOR, SECRETARIA o DOCENTE.
- `CorsMiddleware.php`: permite que el frontend pueda comunicarse con el backend desde otro dominio.

## Cuando modificar

- Si cambia la forma de autenticar usuarios o asignar permisos.
- Si necesitas cambiar el tiempo de inactividad.
- Si el frontend desplegado en Vercel no puede llamar al backend por CORS.

## Nota

No pongas reglas de negocio de postulantes, examenes o docentes aqui. Esa logica debe ir en controladores o servicios.
