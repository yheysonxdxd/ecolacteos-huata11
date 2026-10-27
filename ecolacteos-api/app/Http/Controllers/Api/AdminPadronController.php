<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\CambiosComunidad;
use App\Services\PagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    /**
     * POST /api/v1/admin/proveedores/{id} { nombre, dni, promedio_litros?, vacas_ordeno? }
     * Corregir errores del alta. La comunidad se cambia con "Cambiar comunidad"
     * (así queda en el historial). Si usa la app, su usuario se corrige igual.
     */
    public function editarProveedor(Request $r, int $id)
    {
        $p = DB::table('proveedores')->find($id);
        abort_if(! $p, 404, 'Proveedor no encontrado');
        $d = $r->validate([
            'nombre'          => ['required', 'string', 'min:5', 'max:120'],
            'dni'             => ['required', 'digits:8', Rule::unique('proveedores', 'dni')->ignore($id)],
            'promedio_litros' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'vacas_ordeno'    => ['nullable', 'integer', 'min:0', 'max:999'],
            // corregir la comunidad (o el destino del traslado programado) si se equivocaron
            'comunidad_id'    => ['nullable', 'integer', 'exists:comunidades,id'],
        ], self::MENSAJES);
        if ($p->user_id) {
            abort_if(User::where('dni', $d['dni'])->where('id', '!=', $p->user_id)->exists(), 422, 'Ya hay un usuario de la app con ese DNI.');
        }
        $nombre = trim(preg_replace('/\s+/', ' ', $d['nombre']));
        $prog = CambiosComunidad::programados()->get($p->id);
        $comunidadActual = (int) ($prog->comunidad_id ?? $p->comunidad_id);
        $corregir = isset($d['comunidad_id']) && (int) $d['comunidad_id'] !== $comunidadActual;
        $corregido = null;

        DB::transaction(function () use ($p, $d, $nombre, $corregir, &$corregido) {
            if ($corregir) $corregido = CambiosComunidad::corregir($p->id, (int) $d['comunidad_id']);
            DB::table('proveedores')->where('id', $p->id)->update([
                'nombre' => $nombre, 'dni' => $d['dni'],
                'promedio_litros' => ($d['promedio_litros'] ?? 0) > 0 ? $d['promedio_litros'] : null,
                'vacas_ordeno' => ($d['vacas_ordeno'] ?? 0) > 0 ? $d['vacas_ordeno'] : null,
                'updated_at' => now(),
            ]);
            if ($p->user_id) {
                DB::table('users')->where('id', $p->user_id)->update(['name' => $nombre, 'dni' => $d['dni'], 'updated_at' => now()]);
            }
        });

        return response()->json(['id' => $p->id, 'nombre' => $nombre, 'comunidad_corregida' => $corregido]);
    }

    /**
     * GET /api/v1/admin/proveedores/{id}/ficha — todo del proveedor en un solo lugar:
     * datos, cuánta leche entrega, entregas y pagos por semana (?semanas=8) y su historial de comunidades.
     */
    public function ficha(Request $r, int $id, PagoService $pagos)
    {
        CambiosComunidad::aplicarProgramados();
        $p = DB::table('proveedores as p')->join('comunidades as c', 'c.id', '=', 'p.comunidad_id')
            ->leftJoin('vehiculos as v', 'v.id', '=', 'p.vehiculo_id')->leftJoin('users as a', 'a.id', '=', 'v.acopiador_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')->where('p.id', $id)
            ->first(['p.*', 'c.nombre as comunidad', 'v.codigo as vehiculo', 'a.name as acopiador', 'u.activo as app_activa']);
        abort_if(! $p, 404, 'Proveedor no encontrado');
        $hoy = Carbon::today('America/Lima');

        // boletas de las semanas ya cerradas que aún no se pagan (igual que Dinero → Pagos);
        // la semana en curso no se paga hasta el domingo: se muestra aparte, calculada al vuelo
        $lunes = $hoy->copy()->startOfWeek(Carbon::MONDAY);
        for ($k = 1; $k <= 6; $k++) {
            $ini = $lunes->copy()->subWeeks($k);
            $tiene = DB::table('entregas')->where('proveedor_id', $id)
                ->whereBetween('fecha', [$ini->toDateString(), $ini->copy()->endOfWeek(Carbon::SUNDAY)->toDateString()])->exists();
            $pagada = DB::table('pagos_semanales')->where('proveedor_id', $id)->whereDate('inicio', $ini->toDateString())
                ->where('estado', 'PAGADO')->exists();
            if ($tiene && ! $pagada) $pagos->generar(Proveedor::find($id), $ini);
        }
        $estaSemana = DB::table('entregas')->where('proveedor_id', $id)->where('fecha', '>=', $lunes->toDateString())
            ->where('ausente', false)->where('estado_calidad', 'ACEPTADO');

        $hace30 = $hoy->copy()->subDays(29)->toDateString();
        $mes = DB::table('entregas')->where('proveedor_id', $id)->where('fecha', '>=', $hace30)->where('ausente', false)->where('litros', '>', 0);
        $litros30 = (float) $mes->sum('litros');
        $dias30 = (clone $mes)->count();
        $prog = CambiosComunidad::programados()->get($id);

        // entregas y pagos juntos, una fila por semana (con meses de historia, día por día sería muy largo)
        $n = min(max((int) $r->query('semanas', 8), 1), 104);
        $primerLunes = $lunes->copy()->subWeeks($n - 1);
        $entregas = DB::table('entregas')->where('proveedor_id', $id)->where('fecha', '>=', $primerLunes->toDateString())
            ->orderBy('fecha')->get(['fecha', 'litros', 'ausente', 'estado_calidad', 'monto'])
            ->groupBy(fn ($e) => Carbon::parse($e->fecha)->startOfWeek(Carbon::MONDAY)->toDateString());
        $boletas = DB::table('pagos_semanales')->where('proveedor_id', $id)->where('inicio', '>=', $primerLunes->toDateString())
            ->get(['id', 'inicio', 'monto', 'estado', 'pagado_el', 'metodo'])->keyBy(fn ($b) => substr((string) $b->inicio, 0, 10));
        $semanas = [];
        for ($k = 0; $k < $n; $k++) {
            $ini = $lunes->copy()->subWeeks($k)->toDateString();
            $es = $entregas->get($ini, collect());
            $b = $boletas->get($ini);
            if ($es->isEmpty() && ! $b) continue; // semanas sin nada no se muestran
            $aceptadas = $es->where('ausente', 0)->where('estado_calidad', 'ACEPTADO');
            $semanas[] = [
                'inicio' => $ini, 'fin' => Carbon::parse($ini)->endOfWeek(Carbon::SUNDAY)->toDateString(),
                'dias' => $es->where('ausente', 0)->where('litros', '>', 0)->count(),
                'litros' => round((float) $es->where('ausente', 0)->sum('litros'), 1),
                'litros_aceptados' => round((float) $aceptadas->sum('litros'), 1),
                'monto' => round((float) ($b->monto ?? $aceptadas->sum('monto')), 2),
                // EN_CURSO: aún no cierra · PAGADO · PENDIENTE (ya cerró, falta pagar)
                'estado' => $k === 0 ? 'EN_CURSO' : ($b->estado ?? 'PENDIENTE'),
                'pagado_el' => $b->pagado_el ?? null, 'metodo' => $b->metodo ?? null,
                'boleta_id' => $k > 0 ? ($b->id ?? null) : null, // para pagarla desde la ficha
                'detalle' => $es->map(fn ($e) => ['fecha' => $e->fecha, 'litros' => (float) $e->litros,
                    'ausente' => (bool) $e->ausente, 'calidad' => $e->estado_calidad])->values(),
            ];
        }
        $hayMas = DB::table('entregas')->where('proveedor_id', $id)->where('fecha', '<', $primerLunes->toDateString())->exists();

        return response()->json([
            'proveedor' => [
                'id' => $p->id, 'nombre' => $p->nombre, 'dni' => $p->dni, 'estado' => $p->estado, 'motivo_baja' => $p->motivo_baja,
                'comunidad_id' => $p->comunidad_id, 'comunidad' => $p->comunidad, 'vehiculo' => $p->vehiculo, 'acopiador' => $p->acopiador,
                'promedio' => $p->promedio_litros ? (float) $p->promedio_litros : null, 'vacas' => $p->vacas_ordeno,
                'usa_app' => $p->user_id ? (bool) $p->app_activa : false, 'inscrito' => substr((string) $p->created_at, 0, 10),
                'programado' => $prog ? ['desde' => $prog->desde, 'comunidad_id' => $prog->comunidad_id, 'comunidad' => $prog->comunidad, 'vehiculo' => $prog->vehiculo] : null,
            ],
            'leche' => [
                'por_dia' => $dias30 ? round($litros30 / $dias30, 1) : null, 'litros_30' => round($litros30, 1), 'dias_30' => $dias30,
                'litros_7' => round((float) DB::table('entregas')->where('proveedor_id', $id)->where('ausente', false)
                    ->where('fecha', '>=', $hoy->copy()->subDays(6)->toDateString())->sum('litros'), 1),
            ],
            'semanas' => $semanas, 'semanas_pedidas' => $n, 'hay_mas' => $hayMas,
            'pagos' => [
                'por_pagar' => round((float) DB::table('pagos_semanales')->where('proveedor_id', $id)->where('estado', 'PENDIENTE')->sum('monto'), 2),
                // todo lo que se le debe (también semanas más viejas que las de la tabla), para pagarlo desde aquí
                'pendientes' => DB::table('pagos_semanales')->where('proveedor_id', $id)->where('estado', 'PENDIENTE')
                    ->where('monto', '>', 0)->whereDate('inicio', '<', $lunes->toDateString())->orderBy('inicio')
                    ->get(['id', 'inicio', 'fin', 'monto'])->map(fn ($b) => ['id' => $b->id, 'inicio' => substr((string) $b->inicio, 0, 10),
                        'fin' => substr((string) $b->fin, 0, 10), 'monto' => (float) $b->monto]),
                'pagado_total' => round((float) DB::table('pagos_semanales')->where('proveedor_id', $id)->where('estado', 'PAGADO')->sum('monto'), 2),
                'semana_en_curso' => ['litros' => round((float) (clone $estaSemana)->sum('litros'), 1),
                    'monto' => round((float) (clone $estaSemana)->sum('monto'), 2), 'se_paga' => $lunes->copy()->endOfWeek(Carbon::SUNDAY)->toDateString()],
            ],
            'historial' => CambiosComunidad::historial($id)['pasos'],
            'historia' => self::historiaDe($id), // para saber si se puede eliminar o solo dar de baja
        ]);
    }

    /** Cuántas entregas, pagos y sanciones tiene: si tiene alguna, no se puede borrar sin perder historia. */
    public static function historiaDe(int $id): array
    {
        return [
            'entregas' => DB::table('entregas')->where('proveedor_id', $id)->count(),
            'pagos' => DB::table('pagos_semanales')->where('proveedor_id', $id)->count(),
            'sanciones' => DB::table('sanciones')->where('proveedor_id', $id)->count(),
        ];
    }

    /**
     * POST /api/v1/admin/proveedores/{id}/eliminar
     * Solo si nunca entregó leche (se registró por error). Si ya tiene
     * entregas o pagos se da de baja (admin/proveedores/{id}/baja).
     */
    public function eliminarProveedor(int $id)
    {
        $p = DB::table('proveedores')->find($id);
        abort_if(! $p, 404, 'Proveedor no encontrado');
        $h = self::historiaDe($id);
        abort_if(array_sum($h) > 0, 422,
            "No se puede eliminar: tiene {$h['entregas']} entregas y {$h['pagos']} pagos registrados. Dalo de baja para no perder su historia.");

        DB::transaction(function () use ($p) {
            DB::table('solicitudes_cambio_zona')->where('proveedor_id', $p->id)->delete();
            DB::table('proveedores')->where('id', $p->id)->delete(); // proveedor_ubicaciones se borra en cascada
            if ($p->user_id) { // su acceso a la app ya no sirve
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $p->user_id)->delete();
                DB::table('users')->where('id', $p->user_id)->delete();
            }
        });

        return response()->json(['eliminado' => $p->nombre]);
    }

    /** POST /api/v1/admin/proveedores/{id}/baja { motivo } — deja de aparecer en las rutas; su historia se queda. */
    public function darDeBaja(Request $r, int $id)
    {
        $p = DB::table('proveedores')->find($id);
        abort_if(! $p, 404, 'Proveedor no encontrado');
        abort_if($p->estado === 'BAJA', 422, 'Ya está dado de baja.');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:3', 'max:300']], ['motivo.required' => 'Escribe por qué se da de baja.']);

        DB::transaction(function () use ($p, $d) {
            DB::table('proveedores')->where('id', $p->id)->update([
                'estado' => 'BAJA', 'motivo_baja' => trim($d['motivo']), 'updated_at' => now(),
            ]);
            if ($p->user_id) {
                DB::table('users')->where('id', $p->user_id)->update(['activo' => false, 'updated_at' => now()]);
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $p->user_id)->delete();
            }
        });

        return response()->json(['baja' => $p->nombre]);
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
