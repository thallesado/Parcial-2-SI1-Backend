<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // MODULO AUTENTICACION LOGIN - valida credenciales iniciales desde config/admin_credentials.php.
    // Si falla o acierta, registra el intento en bitacora_accion.
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'usuario' => ['required', 'string'],
            'contrasena' => ['required', 'string'],
        ], [
            'usuario.required' => 'El usuario es obligatorio.',
            'contrasena.required' => 'La contrasena es obligatoria.',
        ]);

        if (
            $data['usuario'] !== config('admin_credentials.username')
            || !password_verify($data['contrasena'], config('admin_credentials.password_hash'))
        ) {
            DB::table('admision.bitacora_accion')->insert([
                'usuario_id' => null,
                'accion' => 'LOGIN',
                'tabla_afectada' => 'auth',
                'registro_id' => $data['usuario'],
                'descripcion' => 'Intento fallido de inicio de sesion',
                'fecha_hora' => now(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Usuario o contrasena incorrectos.'], 422);
        }

        $token = Str::random(64);

        Cache::put('admin_session_'.$token, [
            'usuario' => $data['usuario'],
            'inicio' => now()->toDateTimeString(),
        ], now()->addMinutes(config('admin_credentials.session_minutes')));

        DB::table('admision.bitacora_accion')->insert([
            'usuario_id' => null,
            'accion' => 'LOGIN',
            'tabla_afectada' => 'auth',
            'registro_id' => $data['usuario'],
            'descripcion' => 'Inicio de sesion correcto',
            'fecha_hora' => now(),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'token' => $token,
            'usuario' => $data['usuario'],
        ]);
    }

    // MODULO AUTENTICACION LOGOUT - elimina token de cache y deja registro en bitacora.
    public function logout(Request $request): JsonResponse
    {
        $header = $request->header('Authorization', '');
        $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';

        if ($token !== '') {
            Cache::forget('admin_session_'.$token);
        }

        DB::table('admision.bitacora_accion')->insert([
            'usuario_id' => null,
            'accion' => 'LOGOUT',
            'tabla_afectada' => 'auth',
            'registro_id' => null,
            'descripcion' => 'Cierre de sesion',
            'fecha_hora' => now(),
            'ip' => $request->ip(),
        ]);

        return response()->json(['ok' => true]);
    }

    // MODULO AUTENTICACION ME - devuelve datos de sesion asociada al token Bearer.
    public function me(Request $request): JsonResponse
    {
        $header = $request->header('Authorization', '');
        $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';

        return response()->json(Cache::get('admin_session_'.$token));
    }
}
