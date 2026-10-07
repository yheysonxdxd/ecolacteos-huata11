<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Configuracion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Configuración del sistema (solo el administrador): datos de la empresa,
 * precios (leche y venta), límites de calidad y tolerancias de acopio.
 * Cada cambio queda en el historial con quién lo hizo y el valor de antes.
 */
class ConfiguracionController extends Controller
{
    private const TIPOS_CLIENTE = ['MAYORISTA' => 'Mayorista', 'DIRECTO' => 'Cliente directo', 'PLANTA' => 'Proveedor de leche'];

    private function hoy(): Carbon
    {
        return Carbon::today('America/Lima');
    }

    /** GET /api/v1/admin/configuracion */
    public function index()
    {
        $hoy = $this->hoy()->toDateString();
        $precios = DB::table('precios_venta')->whereNull('vigente_hasta')->get()->groupBy('producto_id');

        return response()->json([
            'grupos' => Configuracion::GRUPOS,
            'campos' => Configuracion::campos(),
            'precio_leche' => [
                'hoy' => Configuracion::precioLeche(),
                // los que ya están anotados para más adelante (se pueden quitar)
                'programados' => DB::table('precios_leche')->where('vigente_desde', '>', $hoy)->orderBy('vigente_desde')
                    ->get(['id', 'precio', 'vigente_desde'])->map(fn ($p) => ['id' => $p->id, 'precio' => (float) $p->precio, 'desde' => $p->vigente_desde]),
                'anteriores' => DB::table('precios_leche')->where('vigente_desde', '<=', $hoy)->where('vigente_desde', '>', '2000-01-01')
                    ->orderByDesc('vigente_desde')->limit(6)->get(['precio', 'vigente_desde'])
                    ->map(fn ($p) => ['precio' => (float) $p->precio, 'desde' => $p->vigente_desde]),
            ],
            'tipos_cliente' => self::TIPOS_CLIENTE,
            'precios_venta' => DB::table('productos')->orderBy('id')->get(['id', 'nombre', 'unidad'])->map(fn ($p) => [
                'producto_id' => $p->id, 'producto' => $p->nombre, 'unidad' => $p->unidad,
                'precios' => collect(self::TIPOS_CLIENTE)->mapWithKeys(fn ($_, $tipo) => [
                    $tipo => ($x = ($precios[$p->id] ?? collect())->firstWhere('tipo_cliente', $tipo)) ? (float) $x->precio : null,
                ]),
            ]),
            'historial' => $this->historial(),
        ]);
    }

    /** POST /api/v1/admin/configuracion { valores: { clave: valor } } */
    public function guardar(Request $r)
    {
        $r->validate(['valores' => ['required', 'array', 'min:1']], ['valores.required' => 'No hay nada que guardar.']);
        $n = Configuracion::guardar($r->input('valores'), $r->user()->id);

        return response()->json(['cambios' => $n]);
    }

    /**
     * POST /api/v1/admin/configuracion/precio-leche { precio, desde }
     * Desde hoy o un día que todavía no llega: las entregas de días anteriores conservan su precio.
     */
    public function precioLeche(Request $r)
    {
        $d = $r->validate([
            'precio' => ['required', 'numeric', 'min:0.5', 'max:10'],
            'desde'  => ['required', 'date', 'after_or_equal:' . $this->hoy()->toDateString()],
        ], [
            'precio.required' => 'Escribe el precio por litro.',
            'precio.min' => 'El precio por litro tiene que estar entre S/ 0,50 y S/ 10.',
            'precio.max' => 'El precio por litro tiene que estar entre S/ 0,50 y S/ 10.',
            'desde.after_or_equal' => 'El precio nuevo rige desde hoy o desde un día que todavía no llega; lo ya entregado conserva su precio.',
        ]);
        $desde = Carbon::parse($d['desde'])->toDateString();
        $antes = Configuracion::precioLeche($desde);

        DB::transaction(function () use ($d, $desde, $antes, $r) {
            DB::table('precios_leche')->updateOrInsert(['vigente_desde' => $desde], [
                'precio' => $d['precio'], 'registrado_por' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('configuracion_historial')->insert([
                'clave' => 'precio.leche_litro', 'valor_antes' => (string) $antes, 'valor_nuevo' => (string) $d['precio'],
                'vigente_desde' => $desde, 'usuario_id' => $r->user()->id, 'created_at' => now(),
            ]);
        });

        return response()->json(['precio' => (float) $d['precio'], 'desde' => $desde, 'hoy' => Configuracion::precioLeche()]);
    }

    /** POST /api/v1/admin/configuracion/precio-leche/{id}/quitar — quita un precio anotado para más adelante. */
    public function quitarPrecioLeche(Request $r, int $id)
    {
        $p = DB::table('precios_leche')->find($id);
        abort_unless($p, 404, 'Ese precio no existe.');
        abort_unless($p->vigente_desde > $this->hoy()->toDateString(), 422, 'Ese precio ya está rigiendo: para cambiarlo, anota uno nuevo.');

        DB::transaction(function () use ($p, $r) {
            DB::table('precios_leche')->where('id', $p->id)->delete();
            DB::table('configuracion_historial')->insert([
                'clave' => 'precio.leche_litro', 'valor_antes' => (string) (float) $p->precio, 'valor_nuevo' => null,
                'vigente_desde' => $p->vigente_desde, 'usuario_id' => $r->user()->id, 'created_at' => now(),
            ]);
        });

        return response()->json(['ok' => true]);
    }

    /** POST /api/v1/admin/configuracion/precio-venta { producto_id, tipo_cliente, precio } — rige desde hoy. */
    public function precioVenta(Request $r)
    {
        $d = $r->validate([
            'producto_id'  => ['required', 'exists:productos,id'],
            'tipo_cliente' => ['required', 'in:' . implode(',', array_keys(self::TIPOS_CLIENTE))],
            'precio'       => ['required', 'numeric', 'min:0.1', 'max:10000'],
        ], ['precio.required' => 'Escribe el precio.', 'precio.min' => 'El precio tiene que ser mayor a 0.']);
        $hoy = $this->hoy()->toDateString();

        $antes = DB::transaction(function () use ($d, $hoy, $r) {
            $actual = DB::table('precios_venta')->where('producto_id', $d['producto_id'])->where('tipo_cliente', $d['tipo_cliente'])
                ->whereNull('vigente_hasta')->lockForUpdate()->first();
            if ($actual && (float) $actual->precio === (float) $d['precio']) return false;
            if ($actual && $actual->vigente_desde === $hoy) {
                // cambiado dos veces el mismo día: se corrige ese mismo
                DB::table('precios_venta')->where('id', $actual->id)->update(['precio' => $d['precio'], 'updated_at' => now()]);
            } else {
                if ($actual) DB::table('precios_venta')->where('id', $actual->id)->update(['vigente_hasta' => $hoy, 'updated_at' => now()]);
                DB::table('precios_venta')->insert([
                    'producto_id' => $d['producto_id'], 'tipo_cliente' => $d['tipo_cliente'], 'precio' => $d['precio'],
                    'vigente_desde' => $hoy, 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('configuracion_historial')->insert([
                'clave' => "venta.{$d['producto_id']}.{$d['tipo_cliente']}", 'valor_antes' => $actual ? (string) (float) $actual->precio : null,
                'valor_nuevo' => (string) $d['precio'], 'vigente_desde' => $hoy, 'usuario_id' => $r->user()->id, 'created_at' => now(),
            ]);
            return $actual ? (float) $actual->precio : null;
        });

        return response()->json(['cambio' => $antes !== false, 'antes' => $antes ?: null, 'precio' => (float) $d['precio']]);
    }

    /** Los últimos 40 cambios, en palabras: "Grasa mínima: 3 → 3,2 %". */
    private function historial()
    {
        $productos = DB::table('productos')->pluck('nombre', 'id');

        return DB::table('configuracion_historial as h')->leftJoin('users as u', 'u.id', '=', 'h.usuario_id')
            ->orderByDesc('h.created_at')->orderByDesc('h.id')->limit(40)
            ->get(['h.*', 'u.name as usuario'])
            ->map(function ($h) use ($productos) {
                $que = Configuracion::etiqueta($h->clave);
                $tipo = Configuracion::CAMPOS[$h->clave][3] ?? null;
                $unidad = Configuracion::CAMPOS[$h->clave][4] ?? null;
                $f = function ($v) use ($h, $tipo, $unidad) {
                    if ($v === null || $v === '') return '—';
                    if (str_starts_with($h->clave, 'precio.leche') || str_starts_with($h->clave, 'venta.')) return 'S/ ' . number_format((float) $v, 2, ',', ' ');
                    if ($tipo === 'porcentaje') return rtrim(rtrim(number_format((float) $v * 100, 1, ',', ''), '0'), ',') . ' %';
                    if ($tipo === 'numero') return rtrim(rtrim(number_format((float) $v, 3, ',', ''), '0'), ',') . ($unidad ? " {$unidad}" : '');
                    return $v;
                };
                if (str_starts_with($h->clave, 'venta.')) {
                    [, $prod, $tipoCli] = explode('.', $h->clave);
                    $que = 'Precio de ' . ($productos[$prod] ?? 'producto') . ' · ' . (self::TIPOS_CLIENTE[$tipoCli] ?? $tipoCli);
                }
                return [
                    'que' => $que, 'antes' => $f($h->valor_antes), 'despues' => $h->valor_nuevo === null ? 'quitado' : $f($h->valor_nuevo),
                    'desde' => $h->vigente_desde, 'usuario' => $h->usuario, 'fecha' => $h->created_at,
                ];
            });
    }
}
