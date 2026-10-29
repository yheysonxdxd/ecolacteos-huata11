<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Configuracion;
use App\Services\TiposPrecio;
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
            // los tres de siempre y los que se agregaron (Bodega, Feria…), con cuántos clientes tiene cada uno
            'tipos_cliente' => $tipos = TiposPrecio::nombres(),
            'tipos_precio' => TiposPrecio::todos(false),
            'precios_venta' => DB::table('productos')->where('activo', true)->orderBy('id')->get(['id', 'nombre', 'unidad'])->map(fn ($p) => [
                'producto_id' => $p->id, 'producto' => $p->nombre, 'unidad' => $p->unidad,
                'precios' => collect($tipos)->mapWithKeys(fn ($_, $tipo) => [
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
            'tipo_cliente' => ['required', 'in:' . implode(',', array_keys(TiposPrecio::nombres()))],
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

    /** POST /api/v1/admin/configuracion/tipos-precio { nombre, icono?, ayuda? } — un precio más (Bodega, Feria…). */
    public function crearTipoPrecio(Request $r)
    {
        $d = $r->validate([
            'nombre' => ['required', 'string', 'max:60'], 'icono' => ['nullable', 'string', 'max:8'], 'ayuda' => ['nullable', 'string', 'max:120'],
        ], ['nombre.required' => 'Escribe el nombre del precio (ej. Bodega, Feria, Restaurante).']);
        $t = TiposPrecio::crear($d['nombre'], $d['icono'] ?? null, $d['ayuda'] ?? null);
        $this->anotar("tipo_precio.{$t['codigo']}", null, $t['nombre'], $r);

        return response()->json($t, 201);
    }

    /**
     * POST /api/v1/admin/configuracion/tipos-precio/{codigo} { nombre?, icono?, ayuda?, activo? }
     * Cambiarle el nombre o dejar de usarlo. Los tres de siempre no se quitan, y uno que
     * todavía tiene clientes tampoco (se les cambia el tipo primero).
     */
    public function cambiarTipoPrecio(Request $r, string $codigo)
    {
        $t = DB::table('tipos_precio')->where('codigo', $codigo)->first();
        abort_unless($t, 404, 'Ese precio no existe.');
        $d = $r->validate([
            'nombre' => ['sometimes', 'string', 'min:3', 'max:60'], 'icono' => ['nullable', 'string', 'max:8'],
            'ayuda' => ['nullable', 'string', 'max:120'], 'activo' => ['sometimes', 'boolean'],
        ], ['nombre.min' => 'El nombre es muy corto.']);
        if (array_key_exists('activo', $d) && ! $d['activo']) {
            abort_if($t->fijo, 422, "«{$t->nombre}» lo usa el sistema y no se puede quitar.");
            $n = DB::table('clientes')->where('tipo', $codigo)->where('activo', true)->count();
            abort_if($n > 0, 422, "{$n} " . ($n === 1 ? 'cliente tiene' : 'clientes tienen') . " el precio «{$t->nombre}»: cámbiales el tipo primero.");
        }
        if (isset($d['nombre'])) {
            $d['nombre'] = trim($d['nombre']);
            abort_if(DB::table('tipos_precio')->where('codigo', '!=', $codigo)->whereRaw('LOWER(nombre) = ?', [mb_strtolower($d['nombre'])])->exists(),
                422, "Ya hay un precio «{$d['nombre']}».");
        }
        DB::table('tipos_precio')->where('codigo', $codigo)->update($d + ['updated_at' => now()]);
        if (isset($d['nombre']) && $d['nombre'] !== $t->nombre) $this->anotar("tipo_precio.{$codigo}", $t->nombre, $d['nombre'], $r);
        if (array_key_exists('activo', $d) && (bool) $d['activo'] !== (bool) $t->activo) {
            $this->anotar("tipo_precio.{$codigo}", $t->nombre, $d['activo'] ? $t->nombre : null, $r);
        }

        return response()->json(['codigo' => $codigo]);
    }

    private function anotar(string $clave, ?string $antes, ?string $nuevo, Request $r): void
    {
        DB::table('configuracion_historial')->insert([
            'clave' => $clave, 'valor_antes' => $antes, 'valor_nuevo' => $nuevo,
            'vigente_desde' => $this->hoy()->toDateString(), 'usuario_id' => $r->user()->id, 'created_at' => now(),
        ]);
    }

    /** Los últimos 40 cambios, en palabras: "Grasa mínima: 3 → 3,2 %". */
    private function historial()
    {
        $productos = DB::table('productos')->pluck('nombre', 'id');
        $tipos = DB::table('tipos_precio')->pluck('nombre', 'codigo'); // también los que ya no se usan

        return DB::table('configuracion_historial as h')->leftJoin('users as u', 'u.id', '=', 'h.usuario_id')
            ->orderByDesc('h.created_at')->orderByDesc('h.id')->limit(40)
            ->get(['h.*', 'u.name as usuario'])
            ->map(function ($h) use ($productos, $tipos) {
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
                if (str_starts_with($h->clave, 'tipo_precio.')) $que = 'Tipo de precio';
                if (str_starts_with($h->clave, 'venta.')) {
                    [, $prod, $tipoCli] = explode('.', $h->clave);
                    $que = 'Precio de ' . ($productos[$prod] ?? 'producto') . ' · ' . ($tipos[$tipoCli] ?? $tipoCli);
                }
                return [
                    'que' => $que, 'antes' => $f($h->valor_antes), 'despues' => $h->valor_nuevo === null ? 'quitado' : $f($h->valor_nuevo),
                    'desde' => $h->vigente_desde, 'usuario' => $h->usuario, 'fecha' => $h->created_at,
                ];
            });
    }
}
