<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Credenciales del administrador
    |--------------------------------------------------------------------------
    |
    | Usuario inicial:
    |   usuario: admin
    |   clave:   Admin1234
    |
    | Cambia estos valores antes de una entrega final real.
    */
    'username' => env('ADMIN_USERNAME', 'admin'),
    'password_hash' => env('ADMIN_PASSWORD_HASH', '$2y$12$IOS7Aiu6W8N/bxLzuf1jl.pmtIbrFRTadcAGpoFcQDWcUQeaMNjT.'),
    'session_minutes' => (int) env('ADMIN_SESSION_MINUTES', 5),
];
