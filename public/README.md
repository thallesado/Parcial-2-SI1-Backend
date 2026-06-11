# Public

Esta carpeta es el punto de entrada publico de Laravel.

## Archivo principal

- `index.php`: recibe las peticiones HTTP y arranca Laravel.

## Despliegue

En servidores tradicionales, el document root apunta a esta carpeta. En Render con Docker, Laravel se levanta usando el contenedor configurado en `Dockerfile`.

## Regla

No guardes archivos privados aqui. Todo lo que este en `public/` puede quedar expuesto.
