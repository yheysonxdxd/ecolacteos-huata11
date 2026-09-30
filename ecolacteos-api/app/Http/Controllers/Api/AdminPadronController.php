<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Altas del Padrón desde la app del administrador: proveedores nuevos
 * (con acceso opcional a la app como productor), trabajadores nuevos y
 * activar/desactivar trabajadores. Solo ADMIN (rutas bajo admin/).
 */
class AdminPadronController extends Controller
{
    /** Roles que se crean desde "Trabajadores" (el productor se crea con su proveedor). */
    private const ROLES = ['ACOPIADOR', 'CALIDAD', 'OPERARIO', 'COMPRAS', 'ADMIN'];

    private const MENSAJES = [
        'dni.digits'          => 'El DNI debe tener 8 dígitos.',
        'dni.unique'          => 'Ya hay alguien registrado con ese DNI.',
        'nombre.required'     => 'Falta el nombre.',
        'nombre.min'          => 'Escribe nombre y apellidos.',
        'dni.required'        => 'Falta el DNI.',
        'comunidad_id.required' => 'Elige la comunidad.',
        'rol.required'        => 'Elige el rol.',
        'clave.min'           => 'La clave debe tener al menos 6 caracteres.',
        'clave.required'      => 'Falta la clave.',
        'clave.required_if'   => 'Falta la clave para que el productor entre a la app.',
        'comunidad_id.exists' => 'Esa comunidad no existe.',
        'rol.in'              => 'Rol no válido.',
    ];

    /** POST /api/v1/admin/proveedores */
    public function nuevoProveedor(Request $r)
    {
        $d = $r->validate([
            'nombre'          => ['required', 'string', 'min:5', 'max:120'],
            'dni'             => ['required', 'digits:8', 'unique:proveedores,dni'],
            'comunidad_id'    => ['required', 'integer', 'exists:comunidades,id'],
            'vehiculo_id'     => ['nullable', 'integer', 'exists:vehiculos,id'],
            'promedio_litros' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'vacas_ordeno'    => ['nullable', 'integer', 'min:0', 'max:999'],
            'con_acceso'      => ['boolean'],
            'clave'           => ['required_if:con_acceso,true', 'nullable', 'string', 'min:6'],
            'telefono'        => ['nullable', 'string', 'max:20'],
        ], self::MENSAJES);

        // sin vehículo elegido: el que pasa por esa comunidad (mismo criterio que el cambio de zona)
        $vehiculoId = $d['vehiculo_id'] ?? DB::table('vehiculos')->get()
            ->first(fn ($v) => in_array($d['comunidad_id'], json_decode($v->orden_ruta ?? '[]', true)))?->id;
        abort_if(! $vehiculoId, 422, 'Ningún vehículo pasa por esa comunidad: elige uno.');

        if ($r->boolean('con_acceso')) {
            abort_if(User::where('dni', $d['dni'])->exists(), 422, 'Ya hay un usuario de la app con ese DNI.');
        }

        return DB::transaction(function () use ($r, $d, $vehiculoId) {
            $userId = null;
            if ($r->boolean('con_acceso')) {
                $userId = $this->crearUsuario($d['nombre'], $d['dni'], 'PRODUCTOR', $d['clave'], $d['telefono'] ?? null)->id;
            }

            $id = DB::table('proveedores')->insertGetId([
                'uuid'            => (string) Str::uuid(),
                'nombre'          => trim(preg_replace('/\s+/', ' ', $d['nombre'])),
                'dni'             => $d['dni'],
                'comunidad_id'    => $d['comunidad_id'],
                'vehiculo_id'     => $vehiculoId,
                'user_id'         => $userId,
                'promedio_litros' => ($d['promedio_litros'] ?? 0) > 0 ? $d['promedio_litros'] : null,
                'vacas_ordeno'    => ($d['vacas_ordeno'] ?? 0) > 0 ? $d['vacas_ordeno'] : null,
                'estado'          => 'ACTIVO',
                'registrado_por'  => $r->user()->id,
                'created_at'      => now(), 'updated_at' => now(),
            ]);

            return response()->json(['id' => $id, 'con_acceso' => (bool) $userId], 201);
        });
    }

    /** POST /api/v1/admin/trabajadores */
    public function nuevoTrabajador(Request $r)
    {
        $d = $r->validate([
            'nombre'   => ['required', 'string', 'min:5', 'max:120'],
            'dni'      => ['required', 'digits:8', 'unique:users,dni'],
            'rol'      => ['required', Rule::in(self::ROLES)],
            'clave'    => ['required', 'string', 'min:6'],
            'telefono' => ['nullable', 'string', 'max:20'],
        ], self::MENSAJES);

        $u = $this->crearUsuario($d['nombre'], $d['dni'], $d['rol'], $d['clave'], $d['telefono'] ?? null);

        return response()->json(['id' => $u->id], 201);
    }

    /** POST /api/v1/admin/trabajadores/{id}/activo { activo } */
    public function cambiarActivo(Request $r, int $id)
    {
        $r->validate(['activo' => ['required', 'boolean']]);
        $u = User::findOrFail($id);
        abort_if($u->id === $r->user()->id && ! $r->boolean('activo'), 422, 'No puedes desactivarte a ti mismo.');

        $u->update(['activo' => $r->boolean('activo')]);
        if (! $u->activo) {
            $u->tokens()->delete(); // queda fuera de todos sus celulares
        }

        return response()->json(['id' => $u->id, 'activo' => $u->activo]);
    }

    private function crearUsuario(string $nombre, string $dni, string $rol, string $clave, ?string $telefono): User
    {
        return User::create([
            'name'     => trim(preg_replace('/\s+/', ' ', $nombre)),
            'dni'      => $dni,
            'rol'      => $rol,
            'email'    => "$dni@huata.local", // la app entra con DNI; el email solo cumple la tabla
            'password' => $clave,             // el modelo la cifra (cast 'hashed')
            'telefono' => $telefono,
            'activo'   => true,
        ]);
    }
}
