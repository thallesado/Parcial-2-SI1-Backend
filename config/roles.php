<?php

return [
    'prioridad' => [
        'ADMINISTRADOR' => 100,
        'SECRETARIA' => 50,
        'ADMINISTRATIVO' => 40,
        'REPORTES' => 30,
        'DOCENTE' => 20,
    ],

    'secciones' => [
        'ADMINISTRADOR' => [
            'dashboard',
            'usuarios',
            'postulantes',
            'carreras',
            'materias',
            'gestiones',
            'cupos',
            'docentes',
            'aulas',
            'examenes',
            'reportes',
            'bitacora',
        ],
        'SECRETARIA' => ['postulantes', 'aulas', 'reportes'],
        'ADMINISTRATIVO' => ['postulantes', 'aulas', 'reportes'],
        'REPORTES' => ['postulantes', 'aulas', 'reportes'],
        'DOCENTE' => ['examenes', 'mis-horarios'],
    ],
];
