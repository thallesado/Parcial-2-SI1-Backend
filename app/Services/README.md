# Services

Los services guardan reglas de negocio reutilizables. Esto ayuda a que los controladores no se vuelvan enormes.

## Services actuales

- `GroupAssignmentService.php`: asigna postulantes a grupos disponibles y prepara nuevos grupos.
- `ScheduleService.php`: valida choques de horarios entre grupo, docente y aula.
- `TeacherAssignmentService.php`: sincroniza materias de docentes y asigna docentes a materias por grupo.

## Por que son importantes

Antes, muchas reglas vivian dentro de controladores. Separarlas permite:

- entender mejor el codigo;
- reutilizar reglas;
- probar partes del sistema con mas facilidad;
- evitar duplicacion;
- mantener controladores mas limpios.

## Cuando crear otro service

Crea un service cuando una accion:

- tenga reglas complejas;
- se repita en varios controladores;
- toque varias tablas;
- necesite transacciones;
- pueda crecer en el futuro.
