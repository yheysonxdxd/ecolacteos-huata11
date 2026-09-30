<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Compras y almacén: inventario, órdenes de compra, ventas y movimientos. */
class ComprasAppController extends Controller
{
    /** Costo unitario de cada insumo según su última orden de compra. */
    private function costos()
    {
        return DB::table('ordenes_compra')->orderBy('created_at')->get()
            ->mapWithKeys(fn ($o) => [$o->insumo_id => $o->cantidad > 0 ? round($o->costo_estimado / $o->cantidad, 4) : 0]);
    }

    /** Stock de producto terminado = obtenido en lotes cerrados − vendido. */
    private function productos()
    {
        $precios = DB::table('precios_venta')->whereNull('vigente_hasta')->get()->groupBy('producto_id');
        return DB::table('productos')->orderBy('id')->get()->map(function ($p) use ($precios) {
            $producido = (float) DB::table('lotes')->where('producto_id', $p->id)->where('estado', 'TERMINADO')->sum('obtenido');
            $vendido = (float) DB::table('ventas')->where('producto_id', $p->id)->sum('cantidad');
            return [
                'id' => $p->id, 'nombre' => $p->nombre, 'unidad' => $p->unidad,
                'stock' => round($producido - $vendido, 2),
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

        return response()->json(['insumos' => $insumos, 'productos' => $this->productos()]);
    }

    /** GET /api/v1/compras/ordenes — últimas 20, las emitidas primero. */
    public function ordenes()
    {
        $filas = DB::table('ordenes_compra as o')->join('insumos as i', 'i.id', '=', 'o.insumo_id')
            ->orderByRaw("o.estado = 'EMITIDA' DESC")->orderByDesc('o.created_at')->limit(20)
            ->select('o.id', 'o.codigo', 'o.cantidad', 'o.proveedor_comercial', 'o.costo_estimado',
                'o.estado', 'o.created_at', 'i.nombre as insumo', 'i.unidad')
            ->get();
        $proveedores = DB::table('ordenes_compra')->distinct()->orderBy('proveedor_comercial')->pluck('proveedor_comercial');

        return response()->json(['ordenes' => $filas, 'proveedores' => $proveedores]);
    }

    /** POST /api/v1/compras/ordenes { insumo_id, cantidad, proveedor_comercial } */
    public function emitir(Request $r)
    {
        $data = $r->validate([
            'insumo_id' => ['required', 'exists:insumos,id'],
            'cantidad' => ['required', 'numeric', 'min:0.001'],
            'proveedor_comercial' => ['required', 'string', 'max:255'],
        ]);
        $ultimo = DB::table('ordenes_compra')->where('codigo', 'like', 'OC-%')->get('codigo')
            ->map(fn ($o) => (int) substr($o->codigo, 3))->max() ?? 100;
        $codigo = 'OC-'.($ultimo + 1);
        $costo = round(($this->costos()[$data['insumo_id']] ?? 0) * $data['cantidad'], 2);

        DB::table('ordenes_compra')->insert([
            'uuid' => (string) Str::uuid(), 'codigo' => $codigo, 'insumo_id' => $data['insumo_id'],
            'cantidad' => $data['cantidad'], 'proveedor_comercial' => $data['proveedor_comercial'],
            'costo_estimado' => $costo, 'estado' => 'EMITIDA', 'emitida_por' => $r->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['codigo' => $codigo, 'costo_estimado' => $costo], 201);
    }

    /** POST /api/v1/compras/ordenes/{id}/recibir — entra el stock al almacén. */
    public function recibir(Request $r, int $id)
    {
        return DB::transaction(function () use ($r, $id) {
            $o = DB::table('ordenes_compra')->lockForUpdate()->find($id);
            abort_if(! $o, 404, 'Orden no encontrada');
            abort_if($o->estado !== 'EMITIDA', 422, 'La orden ya fue recibida o anulada');

            DB::table('ordenes_compra')->where('id', $id)->update(['estado' => 'RECIBIDA', 'updated_at' => now()]);
            DB::table('insumos')->where('id', $o->insumo_id)->increment('stock', $o->cantidad);
            DB::table('movimientos_insumo')->insert([
                'uuid' => (string) Str::uuid(), 'insumo_id' => $o->insumo_id, 'cantidad' => $o->cantidad,
                'referencia' => "{$o->codigo} · recepción de compra", 'origen_type' => 'orden_compra', 'origen_id' => $o->id,
                'registrado_por' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return response()->json(['codigo' => $o->codigo, 'estado' => 'RECIBIDA']);
        });
    }

    /** GET /api/v1/compras/ventas-datos — productos con stock y precios, y clientes. */
    public function datosVenta()
    {
        return response()->json([
            'productos' => $this->productos(),
            'clientes' => DB::table('clientes')->where('activo', true)->orderBy('tipo')->orderBy('nombre')
                ->get(['id', 'nombre', 'tipo']),
        ]);
    }

    /** POST /api/v1/compras/ventas { cliente_id, producto_id, cantidad } — precio según el tipo de cliente. */
    public function vender(Request $r)
    {
        $data = $r->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'producto_id' => ['required', 'exists:productos,id'],
            'cantidad' => ['required', 'numeric', 'min:0.01'],
        ]);
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

        DB::table('ventas')->insert([
            'uuid' => (string) Str::uuid(), 'cliente_id' => $cliente->id, 'producto_id' => $data['producto_id'],
            'lote_id' => $lote, 'cantidad' => $data['cantidad'], 'precio_unitario' => $precio, 'total' => $total,
            'registrada_por' => $r->user()->id, 'vendida_en' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['total' => $total, 'precio_unitario' => (float) $precio], 201);
    }

    /** GET /api/v1/compras/movimientos — insumos y ventas, lo más reciente primero. */
    public function movimientos()
    {
        $ins = DB::table('movimientos_insumo as m')->join('insumos as i', 'i.id', '=', 'm.insumo_id')
            ->orderByDesc('m.created_at')->limit(40)
            ->get(['i.nombre as que', 'm.referencia as ref', 'm.cantidad', 'i.unidad', 'm.created_at as fecha'])
            ->map(fn ($m) => [
                'que' => $m->que, 'ref' => $m->ref, 'fecha' => $m->fecha, 'entrada' => $m->cantidad > 0,
                'delta' => ($m->cantidad > 0 ? '+' : '−').rtrim(rtrim(number_format(abs($m->cantidad), 3, '.', ''), '0'), '.').' '.$m->unidad,
            ]);
        $ventas = DB::table('ventas as v')->join('productos as p', 'p.id', '=', 'v.producto_id')
            ->join('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->orderByDesc('v.vendida_en')->limit(40)
            ->get(['p.nombre as que', 'c.nombre as cliente', 'v.total', 'v.cantidad', 'v.vendida_en as fecha'])
            ->map(fn ($v) => [
                'que' => $v->que, 'ref' => "Venta · {$v->cliente} · S/ ".number_format($v->total, 2),
                'fecha' => $v->fecha, 'entrada' => false,
                'delta' => '−'.rtrim(rtrim(number_format($v->cantidad, 2, '.', ''), '0'), '.'),
            ]);

        return response()->json([
            'movimientos' => $ins->concat($ventas)->sortByDesc('fecha')->take(50)->values(),
        ]);
    }
}
