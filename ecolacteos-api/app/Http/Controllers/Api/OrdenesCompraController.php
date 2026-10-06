<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Compras;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Órdenes de compra de insumos, con vendedor y comprobante.
 *
 * 1. Emitir: a quién se le compra (vendedor de la lista) y qué insumos.
 * 2. Recibir ("Llegó"): el comprobante que entregó (factura, boleta o recibo),
 *    lo que de verdad se pagó por cada insumo y, si se quiere, la foto del
 *    comprobante. Recién ahí sube el stock.
 * La foto se ve con un enlace firmado de 30 minutos, como la nota de venta.
 */
class OrdenesCompraController extends Controller
{
    /** GET /api/v1/compras/ordenes — últimas 30, las por recibir primero. */
    public function index()
    {
        $ordenes = DB::table('ordenes_compra as o')->leftJoin('vendedores_insumo as v', 'v.id', '=', 'o.vendedor_id')
            ->orderByRaw("o.estado = 'EMITIDA' DESC")->orderByDesc('o.created_at')->orderByDesc('o.id')->limit(30)
            ->get(['o.*', 'v.nombre as v_nombre', 'v.tipo_documento as v_tipo', 'v.documento as v_doc']);
        $items = DB::table('orden_compra_items as it')->join('insumos as i', 'i.id', '=', 'it.insumo_id')
            ->whereIn('it.orden_compra_id', $ordenes->pluck('id'))->orderBy('it.id')
            ->get(['it.id', 'it.orden_compra_id', 'it.insumo_id', 'it.cantidad', 'it.costo_estimado', 'it.costo_real', 'i.nombre as insumo', 'i.unidad'])
            ->groupBy('orden_compra_id');

        return response()->json([
            'ordenes' => $ordenes->map(fn ($o) => [
                'id' => $o->id, 'codigo' => $o->codigo, 'estado' => $o->estado, 'created_at' => $o->created_at,
                'vendedor' => $o->vendedor_id
                    ? ['id' => $o->vendedor_id, 'nombre' => $o->v_nombre, 'tipo_documento' => $o->v_tipo, 'documento' => $o->v_doc]
                    : null,
                'proveedor' => $o->v_nombre ?? $o->proveedor_comercial,
                'items' => ($items[$o->id] ?? collect())->map(fn ($i) => [
                    'id' => $i->id, 'insumo_id' => $i->insumo_id, 'insumo' => $i->insumo, 'unidad' => $i->unidad,
                    'cantidad' => (float) $i->cantidad, 'costo_estimado' => (float) $i->costo_estimado,
                    'costo_real' => $i->costo_real !== null ? (float) $i->costo_real : null,
                ])->values(),
                'costo_estimado' => (float) $o->costo_estimado,
                'comprobante_tipo' => $o->comprobante_tipo, 'comprobante_serie' => $o->comprobante_serie,
                'comprobante_numero' => $o->comprobante_numero, 'comprobante_fecha' => $o->comprobante_fecha,
                'comprobante' => Compras::textoComprobante($o->comprobante_tipo, $o->comprobante_serie, $o->comprobante_numero),
                'subtotal' => $o->subtotal !== null ? (float) $o->subtotal : null,
                'igv' => $o->igv !== null ? (float) $o->igv : null,
                'total' => $o->total !== null ? (float) $o->total : null,
                'tiene_foto' => (bool) $o->foto, 'recibida_en' => $o->recibida_en,
            ]),
            'vendedores' => self::vendedores(),
            'comprobantes' => Compras::COMPROBANTES,
        ]);
    }

    public static function vendedores()
    {
        return DB::table('vendedores_insumo')->where('activo', true)->orderBy('nombre')
            ->get(['id', 'tipo_documento', 'documento', 'nombre', 'telefono', 'direccion']);
    }

    /** POST /api/v1/compras/ordenes { vendedor_id, items: [{ insumo_id, cantidad }] } */
    public function emitir(Request $r)
    {
        $data = $r->validate([
            'vendedor_id' => ['required', Rule::exists('vendedores_insumo', 'id')->where('activo', true)],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.insumo_id' => ['required', 'distinct', 'exists:insumos,id'],
            'items.*.cantidad' => ['required', 'numeric', 'min:0.001'],
        ], [
            'vendedor_id.required' => 'Elige a quién se le compra.',
            'vendedor_id.exists' => 'Ese vendedor no existe o está desactivado.',
            'items.required' => 'Agrega al menos un insumo.',
            'items.min' => 'Agrega al menos un insumo.',
            'items.*.insumo_id.distinct' => 'Ese insumo está dos veces: junta las cantidades en una sola línea.',
            'items.*.cantidad.min' => 'La cantidad debe ser mayor a 0.',
        ]);
        $vendedor = DB::table('vendedores_insumo')->find($data['vendedor_id']);
        $costos = Compras::costosUnitarios();

        return DB::transaction(function () use ($r, $data, $vendedor, $costos) {
            $ultimo = DB::table('ordenes_compra')->where('codigo', 'like', 'OC-%')->lockForUpdate()->get('codigo')
                ->map(fn ($o) => (int) substr($o->codigo, 3))->max() ?? 100;
            $codigo = 'OC-'.($ultimo + 1);
            $lineas = collect($data['items'])->map(fn ($i) => [
                'insumo_id' => $i['insumo_id'], 'cantidad' => $i['cantidad'],
                'costo_estimado' => round(($costos[$i['insumo_id']] ?? 0) * $i['cantidad'], 2),
            ]);
            $total = round($lineas->sum('costo_estimado'), 2);

            $id = DB::table('ordenes_compra')->insertGetId([
                'uuid' => (string) Str::uuid(), 'codigo' => $codigo, 'vendedor_id' => $vendedor->id,
                'proveedor_comercial' => $vendedor->nombre, 'costo_estimado' => $total, 'estado' => 'EMITIDA',
                'emitida_por' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('orden_compra_items')->insert($lineas->map(fn ($l) => $l + [
                'orden_compra_id' => $id, 'created_at' => now(), 'updated_at' => now(),
            ])->all());

            return response()->json(['id' => $id, 'codigo' => $codigo, 'costo_estimado' => $total], 201);
        });
    }

    /**
     * POST /api/v1/compras/ordenes/{id}/recibir
     * { comprobante_tipo, comprobante_serie?, comprobante_numero?, comprobante_fecha,
     *   items: [{ id, costo_real }], vendedor_id?, foto? (base64) }
     */
    public function recibir(Request $r, int $id)
    {
        $r->merge(['comprobante_serie' => $r->filled('comprobante_serie') ? strtoupper(trim($r->input('comprobante_serie'))) : null]);
        $conNumero = Rule::requiredIf(fn () => in_array($r->input('comprobante_tipo'), ['FACTURA', 'BOLETA'], true));
        $data = $r->validate([
            'comprobante_tipo' => ['required', Rule::in(array_keys(Compras::COMPROBANTES))],
            'comprobante_serie' => [$conNumero, 'nullable', 'regex:/^[A-Z0-9]{1,4}$/'],
            'comprobante_numero' => [$conNumero, 'nullable', 'regex:/^\d{1,8}$/'],
            'comprobante_fecha' => ['required', 'date', 'before_or_equal:today'],
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.costo_real' => ['required', 'numeric', 'min:0'],
            'vendedor_id' => ['nullable', Rule::exists('vendedores_insumo', 'id')->where('activo', true)],
            'foto' => ['nullable', 'string'],
        ], [
            'comprobante_tipo.required' => 'Elige el tipo de comprobante.',
            'comprobante_serie.required' => 'Escribe la serie del comprobante (ej. F001 o B001).',
            'comprobante_serie.regex' => 'La serie tiene hasta 4 letras o números (ej. F001).',
            'comprobante_numero.required' => 'Escribe el número del comprobante.',
            'comprobante_numero.regex' => 'El número del comprobante son solo dígitos (hasta 8).',
            'comprobante_fecha.required' => 'Escribe la fecha del comprobante.',
            'comprobante_fecha.before_or_equal' => 'La fecha del comprobante no puede ser futura.',
            'items.*.costo_real.required' => 'Escribe cuánto se pagó por cada insumo.',
            'items.*.costo_real.min' => 'Lo pagado no puede ser negativo.',
        ]);

        return DB::transaction(function () use ($r, $id, $data) {
            $o = DB::table('ordenes_compra')->lockForUpdate()->find($id);
            abort_if(! $o, 404, 'Orden no encontrada');
            abort_if($o->estado !== 'EMITIDA', 422, 'La orden ya fue recibida o anulada');

            $items = DB::table('orden_compra_items')->where('orden_compra_id', $id)->get()->keyBy('id');
            $pagado = collect($data['items'])->keyBy('id');
            abort_if($pagado->keys()->sort()->values()->all() !== $items->keys()->sort()->values()->all(), 422,
                'Falta lo pagado de algún insumo de la orden.');

            $vendedorId = $o->vendedor_id ?? ($data['vendedor_id'] ?? null);
            $vendedor = $vendedorId ? DB::table('vendedores_insumo')->find($vendedorId) : null;
            $tipo = $data['comprobante_tipo'];
            abort_if($tipo === 'FACTURA' && $vendedor?->tipo_documento !== 'RUC', 422,
                'Solo se puede registrar factura si el vendedor tiene RUC. Si te dio boleta, elige boleta.');

            $serie = $tipo === 'RECIBO' ? ($data['comprobante_serie'] ?? null) : $data['comprobante_serie'];
            $numero = isset($data['comprobante_numero']) ? (ltrim($data['comprobante_numero'], '0') ?: '0') : null;
            if ($tipo !== 'RECIBO' && $vendedorId) {
                $repetida = DB::table('ordenes_compra')->where('id', '!=', $id)->where('vendedor_id', $vendedorId)
                    ->where('comprobante_tipo', $tipo)->where('comprobante_serie', $serie)->where('comprobante_numero', $numero)->value('codigo');
                abort_if($repetida, 422, "Ese comprobante ya se registró en la orden $repetida.");
            }

            $total = round($pagado->sum(fn ($p) => (float) $p['costo_real']), 2);
            $subtotal = $tipo === 'FACTURA' ? round($total / (1 + Compras::IGV), 2) : null;
            $cambios = [
                'estado' => 'RECIBIDA', 'vendedor_id' => $vendedorId,
                'proveedor_comercial' => $vendedor->nombre ?? $o->proveedor_comercial,
                'comprobante_tipo' => $tipo, 'comprobante_serie' => $serie, 'comprobante_numero' => $numero,
                'comprobante_fecha' => $data['comprobante_fecha'],
                'subtotal' => $subtotal, 'igv' => $subtotal !== null ? round($total - $subtotal, 2) : null, 'total' => $total,
                'recibida_en' => now(), 'recibida_por' => $r->user()->id, 'updated_at' => now(),
            ];
            if (! empty($data['foto'])) $cambios['foto'] = Compras::guardarFoto($o, $data['foto']);
            DB::table('ordenes_compra')->where('id', $id)->update($cambios);

            $comprobante = Compras::textoComprobante($tipo, $serie, $numero);
            foreach ($items as $it) {
                DB::table('orden_compra_items')->where('id', $it->id)->update(['costo_real' => $pagado[$it->id]['costo_real'], 'updated_at' => now()]);
                DB::table('insumos')->where('id', $it->insumo_id)->increment('stock', $it->cantidad);
                DB::table('movimientos_insumo')->insert([
                    'uuid' => (string) Str::uuid(), 'insumo_id' => $it->insumo_id, 'cantidad' => $it->cantidad,
                    'referencia' => "{$o->codigo} · $comprobante", 'origen_type' => 'orden_compra', 'origen_id' => $o->id,
                    'registrado_por' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return response()->json(['codigo' => $o->codigo, 'estado' => 'RECIBIDA', 'total' => $total, 'comprobante' => $comprobante]);
        });
    }

    /** POST /api/v1/compras/ordenes/{id}/foto { foto } — poner o cambiar la foto después. */
    public function subirFoto(Request $r, int $id)
    {
        $r->validate(['foto' => ['required', 'string']], ['foto.required' => 'Elige la foto del comprobante.']);
        $o = DB::table('ordenes_compra')->find($id);
        abort_if(! $o, 404, 'Orden no encontrada');
        DB::table('ordenes_compra')->where('id', $id)->update(['foto' => Compras::guardarFoto($o, $r->input('foto')), 'updated_at' => now()]);

        return response()->json(['codigo' => $o->codigo, 'tiene_foto' => true]);
    }

    /** GET /api/v1/compras/ordenes/{id}/foto — enlace firmado para ver la foto. */
    public function enlaceFoto(int $id)
    {
        $o = DB::table('ordenes_compra')->find($id);
        abort_if(! $o || ! $o->foto, 404, 'Esta compra no tiene foto del comprobante.');

        return response()->json([
            'url' => URL::temporarySignedRoute('comprobante.foto', now()->addMinutes(30), ['id' => $id], absolute: false),
        ]);
    }

    /** GET /api/v1/comprobantes/{id}/foto?expires=…&signature=… */
    public function verFoto(int $id)
    {
        $o = DB::table('ordenes_compra')->find($id);
        abort_if(! $o || ! $o->foto || ! Storage::disk('local')->exists($o->foto), 404);

        return Storage::disk('local')->response($o->foto, "{$o->codigo}-comprobante.".pathinfo($o->foto, PATHINFO_EXTENSION));
    }
}
