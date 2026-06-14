<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AdminSessionMiddleware
{
    // MIDDLEWARE SESION ADMIN - protege todas las rutas /api/admin y auth/logout.
    // Responsabilidades: validar token, renovar expiracion y registrar acciones en bitacora.
    public function handle(Request $request, Closure $next): Response
    {
        // TOKEN BEARER - el frontend lo envia desde localStorage mediante frontend/src/lib/api.ts.
        $header = $request->header('Authorization', '');
        $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';

        // SESION INVALIDA - Laravel responde 401 y el frontend vuelve al login.
        if ($token === '' || !Cache::has('admin_session_'.$token)) {
            return response()->json(['message' => 'Sesion no valida o expirada.'], 401);
        }

        // RENOVACION SESION - cada peticion valida extiende la vida del token en cache.
        $session = Cache::get('admin_session_'.$token);
        Cache::put(
            'admin_session_'.$token,
            $session,
            now()->addMinutes(config('admin_credentials.session_minutes'))
        );
        $request->attributes->set('auth_session', $session);

        $response = $next($request);

        // BITACORA AUTOMATICA - registra solo acciones que cambian datos.
        // Las consultas GET se omiten para que la bitacora muestre movimientos importantes.
        $shouldAudit = !$request->isMethod('get')
            && !$request->is('api/auth/me')
            && !$request->is('api/admin/bitacora')
            && $response->getStatusCode() < 400;

        if ($shouldAudit) {
            DB::table('admision.bitacora_accion')->insert([
                'usuario_id' => $session['usuario_id'] ?? null,
                'accion' => $this->auditAction($request),
                'tabla_afectada' => $request->path(),
                'registro_id' => $request->route()?->parameter('postulante')?->postulante_id
                    ?? $request->route()?->parameter('carrera')?->carrera_id
                    ?? null,
                'descripcion' => sprintf(
                    '%s ejecuto %s %s',
                    $session['usuario'] ?? 'admin',
                    $request->method(),
                    $request->path()
                ),
                'fecha_hora' => now(),
                'ip' => $request->ip(),
            ]);
        }

        return $response;
    }

    // BITACORA HELPER - traduce metodo HTTP a una accion mas legible para reportes.
    private function auditAction(Request $request): string
    {
        return match ($request->method()) {
            'POST' => 'CREAR',
            'PUT', 'PATCH' => 'MODIFICAR',
            'DELETE' => 'ELIMINAR',
            default => 'PROCESO',
        };
    }
}
