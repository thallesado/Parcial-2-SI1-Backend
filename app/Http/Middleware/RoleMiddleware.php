<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    // AUTORIZACION POR ROL - complementa al middleware de sesion y bloquea endpoints no permitidos.
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $session = $request->attributes->get('auth_session', []);
        $userRoles = $session['roles'] ?? [];

        if (in_array('ADMINISTRADOR', $userRoles, true)) {
            return $next($request);
        }

        if (array_intersect($roles, $userRoles) === []) {
            return response()->json([
                'message' => 'No tienes permisos para realizar esta accion.',
            ], 403);
        }

        return $next($request);
    }
}
