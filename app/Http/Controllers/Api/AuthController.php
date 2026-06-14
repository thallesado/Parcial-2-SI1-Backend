<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // AUTENTICACION - valida usuarios persistidos y construye una sesion con roles y secciones.
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'usuario' => ['required', 'string'],
            'contrasena' => ['required', 'string'],
        ], [
            'usuario.required' => 'El usuario es obligatorio.',
            'contrasena.required' => 'La contrasena es obligatoria.',
        ]);

        $usuario = DB::table('admision.usuario')
            ->where('nombre_usuario', $data['usuario'])
            ->where('estado', 'ACTIVO')
            ->first();

        if (!$usuario || !Hash::check($data['contrasena'], $usuario->password_hash)) {
            $this->auditLogin($request, null, $data['usuario'], 'Intento fallido de inicio de sesion');

            return response()->json(['message' => 'Usuario o contrasena incorrectos.'], 422);
        }

        $roles = DB::table('admision.usuario_rol as ur')
            ->join('admision.rol as r', 'r.rol_id', '=', 'ur.rol_id')
            ->where('ur.usuario_id', $usuario->usuario_id)
            ->where('r.estado', 'ACTIVO')
            ->orderByDesc('r.prioridad')
            ->pluck('r.nombre')
            ->all();

        if ($roles === []) {
            return response()->json(['message' => 'El usuario no tiene un rol activo asignado.'], 403);
        }

        $rolPrincipal = $roles[0];
        $docenteId = DB::table('admision.usuario_docente')
            ->where('usuario_id', $usuario->usuario_id)
            ->value('docente_id');

        if ($rolPrincipal === 'DOCENTE' && !$docenteId) {
            return response()->json(['message' => 'La cuenta docente no esta vinculada a un docente.'], 403);
        }

        $token = Str::random(64);
        $session = [
            'usuario_id' => $usuario->usuario_id,
            'usuario' => $usuario->nombre_usuario,
            'nombre_completo' => trim($usuario->nombres.' '.$usuario->apellidos),
            'roles' => $roles,
            'rol' => $rolPrincipal,
            'docente_id' => $docenteId,
            'secciones' => config("roles.secciones.{$rolPrincipal}", []),
            'inicio' => now()->toDateTimeString(),
        ];

        Cache::put(
            'admin_session_'.$token,
            $session,
            now()->addMinutes(config('admin_credentials.session_minutes'))
        );

        $this->auditLogin($request, $usuario->usuario_id, $usuario->nombre_usuario, 'Inicio de sesion correcto');

        return response()->json(['token' => $token, ...$session]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $this->bearerToken($request);
        $session = Cache::get('admin_session_'.$token, []);

        if ($token !== '') {
            Cache::forget('admin_session_'.$token);
        }

        DB::table('admision.bitacora_accion')->insert([
            'usuario_id' => $session['usuario_id'] ?? null,
            'accion' => 'LOGOUT',
            'tabla_afectada' => 'auth',
            'registro_id' => $session['usuario'] ?? null,
            'descripcion' => 'Cierre de sesion',
            'fecha_hora' => now(),
            'ip' => $request->ip(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(
            $request->attributes->get('auth_session', [])
        );
    }

    private function bearerToken(Request $request): string
    {
        $header = $request->header('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
    }

    private function auditLogin(Request $request, ?int $usuarioId, string $registro, string $descripcion): void
    {
        DB::table('admision.bitacora_accion')->insert([
            'usuario_id' => $usuarioId,
            'accion' => 'LOGIN',
            'tabla_afectada' => 'auth',
            'registro_id' => $registro,
            'descripcion' => $descripcion,
            'fecha_hora' => now(),
            'ip' => $request->ip(),
        ]);
    }
}
