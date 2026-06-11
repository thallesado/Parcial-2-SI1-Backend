# Modelos Eloquent

Los modelos representan tablas de PostgreSQL y permiten trabajar con datos desde Laravel.

## Modelos actuales

- `BaseModel.php`: configuracion comun para modelos del esquema `admision`.
- `Postulante.php`: datos del postulante.
- `PostulanteCarreraOpcion.php`: carreras opcion 1 y opcion 2.
- `NotaExamen.php`: notas por materia y numero de examen.
- `Materia.php`: materias del proceso.
- `GestionAcademica.php`: gestiones como `2026-1`.
- `Docente.php`: docentes contratados.
- `Carrera.php`: carreras universitarias.
- `CarreraCupo.php`: cupos de admision por carrera y gestion.
- `Aula.php`: aulas fisicas.

## Recomendacion

Usa modelos cuando la operacion sea simple. Para reportes pesados o consultas con muchos joins, este proyecto usa `DB::table()` en controladores o servicios.
