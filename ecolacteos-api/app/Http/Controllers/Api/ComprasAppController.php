<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Compras;
use App\Services\Formulas;
use App\Services\Mermas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Compras y almacén: inventario, ventas y movimientos (las órdenes están en OrdenesCompraController). */
class ComprasAppController extends Controller
{
    /** Costo unitario de cada insumo según su última compra. */
    private function costos()
    {
        return Compras::costosUnitarios();
    }

    /** Stock de producto terminado = obtenido en lotes cerrados − vendido − perdido. */
    private function productos()
    {
        $precios = DB::table('precios_venta')->whereNull('vigente_hasta')->get()->groupBy('producto_id');
        return DB::table('productos')->orderBy('id')->get()->map(function ($p) use ($precios) {
            return [
                'id' => $p->id, 'nombre' => $p->nombre, 'unidad' => $p->unidad,
                'stock' => Mermas::stockProducto($p->id),
                'precios' => ($precios[$p->id] ?? collect())->mapWithKeys(fn ($x) => [$x->tipo_cliente => (float) $x->precio]),
            ];
        });
    }

    /** GET /api/v1/compras/inventario */
    public function inventario()
    {
        $costos = $this->costos();
        $insumos = DB::table('insumos')->orderBy('nombre')->get()->map(fn ($i) => [
            'id' => $i->id, 'nombre' => $i->nombre, 'unidad' => $i->unidad,
            'stock' => (float) $i->stock, 'minimo' => (float) $i->stock_minimo,
            'costo' => (float) ($costos[$i->id] ?? 0),
        ]);

        return response()->json(['insumos' => $insumos, 'productos' => $this->productos(), 'avisos' => Formulas::avisos()]);
    }

    /** GET /api/v1/compras/ventas-datos — productos con stock y precios, y clientes. */
    public function datosVenta()
    {
        return response()->json([
            'productos' => $this->productos(),
            'clientes' => DB::table('clientes')->where('activo', true)->orderBy('tipo')->orderBy('nombre')
                ->get(['id', 'nombre', 'tipo', 'email']),
        ]);
    }

    /** POST /api/v1/compras/ventas { cliente_id, producto_id, cantidad } — precio según el tipo de cliente. */
    public function vender(Request $r)
    {
        $data = $r->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'producto_id' => ['required', 'exists:productos,id'],
            'cantidad' => ['required', 'numeric', 'min:0.01'],
            'correo' => ['nullable', 'email', 'max:120'], // opcional: mandar la nota de venta
        ], ['correo.email' => 'Ese correo no es válido.']);
        $cliente = DB::table('clientes')->find($data['cliente_id']);
        $precio = DB::table('precios_venta')->where('producto_id', $data['producto_id'])
            ->where('tipo_cliente', $cliente->tipo)->whereNull('vigente_hasta')->value('precio');
        abort_if(! $precio, 422, 'No hay precio vigente para ese tipo de cliente');

        $stock = collect($this->productos())->firstWhere('id', (int) $data['producto_id'])['stock'] ?? 0;
        abort_if($stock < $data['cantidad'], 422, "Solo hay $stock en stock");

        // el lote cerrado más antiguo que aún no vence
        $lote = DB::table('lotes')->where('producto_id', $data['producto_id'])->where('estado', 'TERMINADO')
            ->where('vence_el', '>=', now()->toDateString())->orderBy('iniciado_en')->value('id');
        $total = round($precio * $data['cantidad'], 2);

        $id = DB::table('ventas')->insertGetId([
            'uuid' => (string) Str::uuid(), 'cliente_id' => $cliente->id, 'producto_id' => $data['producto_id'],
            'lote_id' => $lote, 'cantidad' => $data['cantidad'], 'precio_unitario' => $precio, 'total' => $total,
            'registrada_por' => $r->user()->id, 'vendida_en' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // si pidieron correo, se manda la nota; si falla, la venta igual queda hecha
        $errorCorreo = ! empty($data['correo'])
            ? NotaVentaController::mandar(app(\App\Services\NotaVentaPdf::class), $id, $data['correo'])
            : null;

        // id y número de la nota de venta, para imprimirla (NotaVentaController)
        return response()->json(['total' => $total, 'precio_unitario' => (float) $precio,
            'id' => $id, 'numero' => NotaVentaController::numero($id),
            'correo' => $data['correo'] ?? null,
            'correo_enviado' => ! empty($data['correo']) && ! $errorCorreo,
            'correo_error' => $errorCorreo], 201);
    }

    /** GET /api/v1/compras/movimientos — insumos y ventas, lo más reciente primero. */
    public function movimientos(Request $r)
    {
        if ($r->filled('mes')) {
            // la web: todas las ventas del mes, con su total
            [$desde, $hasta, $mes] = Compras::mes($r->query('mes'));
            $ventas = $this->ventas()
                ->when($desde, fn ($q) => $q->whereBetween(DB::raw('DATE(v.vendida_en)'), [$desde, $hasta]))->get();

            return response()->json([
                'movimientos' => $ventas->map(fn ($v) => $this->filaVenta($v))->values(),
                'periodo' => $mes + ['vendido' => round((float) $ventas->sum('total'), 2), 'ventas' => $ventas->count()],
            ]);
        }

        $ins = DB::table('movimientos_insumo as m')->join('insumos as i', 'i.id', '=', 'm.insumo_id')
            ->orderByDesc('m.created_at')->limit(40)
            ->get(['i.nombre as que', 'm.referencia as ref', 'm.cantidad', 'i.unidad', 'm.created_at as fecha'])
            ->map(fn ($m) => [
                'que' => $m->que, 'ref' => $m->ref, 'fecha' => $m->fecha, 'entrada' => $m->cantidad > 0,
                'delta' => ($m->cantidad > 0 ? '+' : '−').rtrim(rtrim(number_format(abs($m->cantidad), 3, '.', ''), '0'), '.').' '.$m->unidad,
            ]);
        $ventas = $this->ventas()->limit(40)->get()->map(fn ($v) => $this->filaVenta($v));

        return response()->json([
            'movimientos' => $ins->concat($ventas)->sortByDesc('fecha')->take(50)->values(),
        ]);
    }

    private function ventas()
    {
        return DB::table('ventas as v')->join('productos as p', 'p.id', '=', 'v.producto_id')
            ->join('clientes as c', 'c.id', '=', 'v.cliente_id')->orderByDesc('v.vendida_en')
            ->select(['v.id', 'p.nombre as que', 'c.nombre as cliente', 'v.total', 'v.cantidad', 'v.vendida_en as fecha']);
    }

    private function filaVenta(object $v): array
    {
        return [
            'venta_id' => $v->id, 'numero' => NotaVentaController::numero($v->id),
            'que' => $v->que, 'ref' => "Venta · {$v->cliente} · S/ ".number_format($v->total, 2),
            'fecha' => $v->fecha, 'entrada' => false,
            'delta' => '−'.rtrim(rtrim(number_format($v->cantidad, 2, '.', ''), '0'), '.'),
        ];
    }
}
