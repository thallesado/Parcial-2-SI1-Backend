<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminUsuarioController extends Controller
{
    // USUARIOS - lista cuentas, roles y vinculacion docente para el administrador.
    public function index(): JsonResponse
    {
        $usuarios = DB::table('admision.usuario as u')
            ->leftJoin('admision.usuario_rol as ur', 'ur.usuario_id', '=', 'u.usuario_id')
            ->leftJoin('admision.rol as r', 'r.rol_id', '=', 'ur.rol_id')
            ->leftJoin('admision.usuario_docente as ud', 'ud.usuario_id', '=', 'u.usuario_id')
            ->leftJoin('admision.docente as d', 'd.docente_id', '=', 'ud.docente_id')
            ->select(
                'u.usuario_id',
                'u.nombre_usuario',
                'u.nombres',
                'u.apellidos',
                'u.correo',
                'u.estado',
                'ud.docente_id',
                DB::raw("COALESCE(d.nombres || ' ' || d.apellidos, '') as docente"),
                DB::raw("COALESCE(string_agg(r.nombre, ', ' ORDER BY r.prioridad DESC), '') as roles")
            )
            ->groupBy(
                'u.usuario_id',
                'u.nombre_usuario',
                'u.nombres',
                'u.apellidos',
                'u.correo',
                'u.estado',
                'ud.docente_id',
                'd.nombres',
                'd.apellidos'
            )
            ->orderBy('u.nombre_usuario')
            ->get();

        return response()->json([
            'usuarios' => $usuarios,
            'roles' => DB::table('admision.rol')
                ->whereIn('nombre', ['ADMINISTRADOR', 'SECRETARIA', 'DOCENTE'])
                ->where('estado', 'ACTIVO')
                ->orderByDesc('prioridad')
                ->get(['rol_id', 'nombre', 'descripcion', 'prioridad']),
            'docentes' => DB::table('admision.docente as d')
                ->leftJoin('admision.usuario_docente as ud', 'ud.docente_id', '=', 'd.docente_id')
                ->where('d.estado', 'ACTIVO')
                ->whereNull('ud.usuario_id')
                ->orderBy('d.apellidos')
                ->get([
                    'd.docente_id',
                    DB::raw("d.nombres || ' ' || d.apellidos as nombre"),
                ]),
        ]);
    }

    // USUARIOS - crea una cuenta y asigna su rol principal.
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $usuarioId = DB::transaction(function () use ($data) {
            $usuarioId = DB::table('admision.usuario')->insertGetId([
                'nombre_usuario' => $data['nombre_usuario'],
                'password_hash' => Hash::make($data['contrasena']),
                'nombres' => $data['nombres'],
                'apellidos' => $data['apellidos'],
                'correo' => $data['correo'],
                'estado' => $data['estado'],
                'creado_en' => now(),
                'actualizado_en' => now(),
            ], 'usuario_id');

            $rolId = DB::table('admision.rol')
                ->where('nombre', $data['rol'])
                ->value('rol_id');

            DB::table('admision.usuario_rol')->insert([
                'usuario_id' => $usuarioId,
                'rol_id' => $rolId,
                'asignado_en' => now(),
            ]);

            if ($data['rol'] === 'DOCENTE') {
                DB::table('admision.usuario_docente')->insert([
                    'usuario_id' => $usuarioId,
                    'docente_id' => $data['docente_id'],
                    'vinculado_en' => now(),
                ]);
            }

            return $usuarioId;
        });

        return response()->json(['usuario_id' => $usuarioId], 201);
    }

    // USUARIOS - actualiza datos, rol, docente vinculado y opcionalmente la clave.
    public function update(Request $request, int $usuarioId): JsonResponse
    {
        abort_unless(
            DB::table('admision.usuario')->where('usuario_id', $usuarioId)->exists(),
            404,
            'Usuario no encontrado.'
        );

        $rules = $this->rules($usuarioId);
        $rules['contrasena'] = ['nullable', 'string', 'min:8', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/'];
        $data = $request->validate($rules, $this->messages());

        DB::transaction(function () use ($usuarioId, $data) {
            $values = [
                'nombre_usuario' => $data['nombre_usuario'],
                'nombres' => $data['nombres'],
                'apellidos' => $data['apellidos'],
                'correo' => $data['correo'],
                'estado' => $data['estado'],
                'actualizado_en' => now(),
            ];

            if (!empty($data['contrasena'])) {
                $values['password_hash'] = Hash::make($data['contrasena']);
            }

            DB::table('admision.usuario')->where('usuario_id', $usuarioId)->update($values);
            DB::table('admision.usuario_rol')->where('usuario_id', $usuarioId)->delete();

            $rolId = DB::table('admision.rol')->where('nombre', $data['rol'])->value('rol_id');
            DB::table('admision.usuario_rol')->insert([
                'usuario_id' => $usuarioId,
                'rol_id' => $rolId,
                'asignado_en' => now(),
            ]);

            DB::table('admision.usuario_docente')->where('usuario_id', $usuarioId)->delete();
            if ($data['rol'] === 'DOCENTE') {
                DB::table('admision.usuario_docente')->insert([
                    'usuario_id' => $usuarioId,
                    'docente_id' => $data['docente_id'],
                    'vinculado_en' => now(),
                ]);
            }
        });

        return response()->json(['ok' => true]);
    }

    private function rules(?int $usuarioId = null): array
    {
        return [
            'nombre_usuario' => [
                'required',
                'string',
                'max:50',
                Rule::unique('usuario', 'nombre_usuario')->ignore($usuarioId, 'usuario_id'),
            ],
            'contrasena' => ['required', 'string', 'min:8', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
            'nombres' => ['required', 'string', 'max:80'],
            'apellidos' => ['required', 'string', 'max:80'],
            'correo' => [
                'required',
                'email',
                'max:120',
                Rule::unique('usuario', 'correo')->ignore($usuarioId, 'usuario_id'),
            ],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
            'rol' => ['required', Rule::in(['ADMINISTRADOR', 'SECRETARIA', 'DOCENTE'])],
            'docente_id' => [
                Rule::requiredIf(fn () => request()->input('rol') === 'DOCENTE'),
                'nullable',
                'integer',
                'exists:docente,docente_id',
                Rule::unique('usuario_docente', 'docente_id')
                    ->ignore($usuarioId, 'usuario_id'),
            ],
        ];
    }

    private function messages(): array
    {
        return [
            'contrasena.regex' => 'La contrasena debe incluir mayuscula, minuscula y numero.',
            'docente_id.required' => 'Debes vincular la cuenta con un docente.',
            'docente_id.unique' => 'Ese docente ya tiene una cuenta vinculada.',
        ];
    }
}
