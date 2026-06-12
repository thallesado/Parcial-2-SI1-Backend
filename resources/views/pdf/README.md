# Vistas PDF

Aqui estan las plantillas Blade usadas para generar documentos PDF.

## Archivo actual

- `boleta-inscripcion.blade.php`: boleta de inscripcion publica con datos del postulante, grupo asignado, materias, carreras y pago confirmado.

## Flujo

1. El postulante completa el formulario publico.
2. Confirma el pago en Stripe.
3. Laravel consolida la inscripcion.
4. El usuario puede descargar la boleta PDF.

## Cuando modificar

Modifica esta plantilla si quieres cambiar el diseno o los datos que aparecen en la boleta.
