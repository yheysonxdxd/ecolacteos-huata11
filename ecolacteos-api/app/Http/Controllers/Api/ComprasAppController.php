<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TiposPrecio;
use App\Services\Compras;
use App\Services\Comprobantes;
use App\Services\ConsultaDocumento;
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

    /**
     * Stock de producto terminado = obtenido en lotes cerrados − vendido − perdido.
     * Para vender: stock = solo lo que no venció, con sus lotes (el que vence primero arriba)
     * y cuánto ya venció (eso no se vende: se registra como pérdida).
     */
    private function productos(bool $paraVender = false)
    {
        $precios = DB::table('precios_venta')->whereNull('vigente_hasta')->get()->groupBy('producto_id');
        return DB::table('productos')->orderBy('id')->get()->map(function ($p) use ($precios, $paraVender) {
            $fila = [
                'id' => $p->id, 'nombre' => $p->nombre, 'tipo' => $p->tipo, 'base_id' => $p->base_id, 'variante' => $p->variante, 'unidad' => $p->unidad, 'activo' => (bool) $p->activo,
                'stock' => Mermas::stockProducto($p->id),
                'precios' => ($precios[$p->id] ?? collect())->mapWithKeys(fn ($x) => [$x->tipo_cliente => (float) $x->precio]),
            ];
            if ($paraVender) {
                [$vencidos, $buenos] = Mermas::lotesConStock($p->id)->partition('vencido');
                $fila['stock'] = round((float) $buenos->sum('queda'), 2);
                $fila['vencido'] = round((float) $vencidos->sum('queda'), 2);
                $hoy = now('America/Lima')->startOfDay();
                $fila['lotes'] = $buenos->map(fn ($l) => ['codigo' => $l->codigo, 'vence_el' => $l->vence_el, 'queda' => $l->queda,
                    'dias' => (int) round($hoy->diffInDays(\Illuminate\Support\Carbon::parse($l->vence_el, 'America/Lima'), false))])->values();
            }
            return $fila;
        })->filter(fn ($p) => $p['activo'] || $p['stock'] > 0)->values(); // el que ya no se produce sale mientras quede stock
    }

    /** GET /api/v1/compras/inventario */
    public function inventario()
    {
        $costos = $this->costos();
        $vendedores = Compras::ultimosVendedores();
        // en qué productos se usa cada insumo (QUESO, YOGURT…), para agruparlos en Almacén
        $tipos = DB::table('productos')->where('activo', true)->pluck('tipo', 'id');
        $usadoEn = [];
        foreach (Formulas::recetas($tipos->keys()->all()) as $productoId => $filas) {
            foreach ($filas as $f) $usadoEn[$f->id][$tipos[$productoId] ?? 'OTRO'] = true;
        }
        // lo que se gasta por día (lo usado en lotes los últimos 30 días / 30), para decir "alcanza para unos N días"
        $gasto30 = DB::table('movimientos_insumo')->where('origen_type', 'lote')->where('cantidad', '<', 0) // lo que se usa al producir, no lo perdido
            ->where('created_at', '>=', now('America/Lima')->subDays(30))
            ->groupBy('insumo_id')->selectRaw('insumo_id, -SUM(cantidad) as total')->pluck('total', 'insumo_id');
        // cuánto se compró la última vez de cada insumo: la Nueva compra lo propone como cantidad
        $ultimaCantidad = DB::table('orden_compra_items as it')->join('ordenes_compra as o', 'o.id', '=', 'it.orden_compra_id')
            ->where('o.estado', 'RECIBIDA')->orderByRaw('COALESCE(o.comprobante_fecha, DATE(o.created_at))')->orderBy('o.id')
            ->pluck('it.cantidad', 'it.insumo_id'); // pluck se queda con la última de cada insumo
        $insumos = DB::table('insumos')->orderBy('nombre')->get()->map(fn ($i) => [
            'id' => $i->id, 'nombre' => $i->nombre, 'unidad' => $i->unidad,
            'stock' => (float) $i->stock, 'minimo' => (float) $i->stock_minimo,
            'consumo_dia' => round((float) ($gasto30[$i->id] ?? 0) / 30, 4),
            'ultima_cantidad' => isset($ultimaCantidad[$i->id]) ? (float) $ultimaCantidad[$i->id] : null,
            'costo' => (float) ($costos[$i->id] ?? 0),               // lo que se pagó por unidad la última vez
            'ultimo_vendedor_id' => $vendedores[$i->id] ?? null,      // a quién se le compró la última vez
            'usado_en' => array_keys($usadoEn[$i->id] ?? []),         // tipos de producto que lo llevan
        ]);
        $avisos = Formulas::avisos();

        // productos con su stock para vender (sin lo vencido) y lo vencido aparte, igual que al vender
        return response()->json(['insumos' => $insumos, 'productos' => $this->productos(true), 'avisos' => $avisos,
            'por_comprar' => Compras::porComprar($avisos), 'por_vencer' => self::porVencer()]);
    }

    /** Días antes del vencimiento en que un lote ya se avisa "por vencer" (la web también lo usa al vender). */
    public const DIAS_AVISO_VENCE = 7;

    /**
     * Lotes con stock que ya vencieron o vencen en DIAS_AVISO_VENCE días o menos, lo más urgente primero:
     * [{ producto_id, producto, tipo, unidad, codigo, queda, vence_el, dias, vencido }].
     * Sirve para avisar "véndelo primero" (o, si ya venció, registrarlo como pérdida).
     */
    public static function porVencer(): array
    {
        $hoy = now('America/Lima')->startOfDay();
        $limite = $hoy->copy()->addDays(self::DIAS_AVISO_VENCE)->toDateString();
        $productos = DB::table('productos')->whereIn('id', DB::table('lotes')->where('estado', 'TERMINADO')
            ->where('vence_el', '<=', $limite)->select('producto_id'))->get(['id', 'nombre', 'tipo', 'unidad']);

        return $productos->flatMap(fn ($p) => Mermas::lotesConStock($p->id)
            ->filter(fn ($l) => $l->vence_el <= $limite)
            ->map(fn ($l) => [
                'producto_id' => $p->id, 'producto' => $p->nombre, 'tipo' => $p->tipo, 'unidad' => $p->unidad,
                'lote_id' => $l->id, 'codigo' => $l->codigo, 'queda' => $l->queda, 'vence_el' => $l->vence_el, 'vencido' => $l->vencido,
                'dias' => (int) round($hoy->diffInDays(\Illuminate\Support\Carbon::parse($l->vence_el, 'America/Lima'), false)),
            ]))->sortBy('dias')->values()->all();
    }

    /** Un lote se muestra como "nuevo" los primeros DIAS_NUEVO días desde que se hizo. */
    public const DIAS_NUEVO = 2;

    /**
     * GET /api/v1/compras/lotes-almacen — todos los lotes con stock, por producto y en el orden en que
     * salen al vender (el que vence primero arriba): cuándo se hizo, cuándo vence, cuánto queda y su estado.
     * estado: VENCIDO | POR_VENCER (vence en DIAS_AVISO_VENCE días o menos) | NUEVO (hecho hace DIAS_NUEVO días o menos) | BIEN.
     */
    public function lotesAlmacen()
    {
        $hoy = now('America/Lima')->startOfDay();
        $dias = fn ($fecha) => (int) round($hoy->diffInDays(\Illuminate\Support\Carbon::parse($fecha, 'America/Lima')->startOfDay(), false));
        $productos = DB::table('productos')->orderBy('tipo')->orderBy('nombre')->get(['id', 'nombre', 'tipo', 'variante', 'base_id', 'unidad'])
            ->map(function ($p) use ($dias) {
                $lotes = Mermas::lotesConStock($p->id)->values()->map(function ($l, $i) use ($dias) {
                    $vence = $dias($l->vence_el);
                    $hecho = -$dias($l->iniciado_en); // hace cuántos días se hizo
                    return [
                        'lote_id' => $l->id, 'codigo' => $l->codigo, 'orden' => $i + 1,
                        'elaborado' => substr((string) $l->iniciado_en, 0, 10), 'vence_el' => substr((string) $l->vence_el, 0, 10),
                        'dias' => $vence, 'hecho_hace' => $hecho, 'vida_dias' => max(1, $hecho + $vence),
                        'obtenido' => $l->obtenido, 'queda' => $l->queda, 'vencido' => $l->vencido,
                        'estado' => $l->vencido ? 'VENCIDO' : ($vence <= self::DIAS_AVISO_VENCE ? 'POR_VENCER' : ($hecho <= self::DIAS_NUEVO ? 'NUEVO' : 'BIEN')),
                    ];
                });
                return ['id' => $p->id, 'producto_id' => $p->id, 'nombre' => $p->nombre, 'tipo' => $p->tipo, 'variante' => $p->variante, 'base_id' => $p->base_id,
                    'unidad' => $p->unidad, 'queda' => round((float) $lotes->sum('queda'), 2), 'lotes' => $lotes->all()];
            })->filter(fn ($p) => count($p['lotes']))->values();

        $todos = $productos->flatMap(fn ($p) => $p['lotes']);
        return response()->json(['productos' => $productos, 'dias_aviso' => self::DIAS_AVISO_VENCE, 'dias_nuevo' => self::DIAS_NUEVO,
            'cuenta' => ['lotes' => $todos->count()] + collect(['NUEVO', 'BIEN', 'POR_VENCER', 'VENCIDO'])
                ->mapWithKeys(fn ($e) => [$e => $todos->where('estado', $e)->count()])->all()]);
    }

    /** GET /api/v1/compras/ventas-datos — productos con stock y precios, y clientes. */
    public function datosVenta()
    {
        // ultima_venta: para que la web muestre arriba a los clientes de siempre
        $ultimas = DB::table('ventas')->select('cliente_id', DB::raw('MAX(created_at) as ultima_venta'))->groupBy('cliente_id');
        $clientes = DB::table('clientes')->leftJoinSub($ultimas, 'u', 'u.cliente_id', '=', 'clientes.id')
            ->orderBy('tipo')->orderBy('nombre')
            ->get(['id', 'nombre', 'tipo', 'ruc', 'telefono', 'email', 'activo', 'u.ultima_venta']);

        return response()->json([
            'productos' => $this->productos(true),
            'clientes' => $clientes->where('activo', true)->values(),
            'inactivos' => $clientes->where('activo', false)->values(), // la web los muestra si se piden
            'tipos_precio' => TiposPrecio::todos(), // para elegir el tipo de un cliente y mostrar su nombre
        ]);
    }

    /**
     * POST /api/v1/compras/clientes[/{id}] { nombre, tipo (un tipo de precio, menos PLANTA), documento?, telefono?, email?, activo? }
     * A quién le vendemos. El documento (DNI u RUC) es opcional y se guarda en "ruc".
     */
    public function guardarCliente(Request $r, ?int $id = null)
    {
        if ($id) {
            $c = DB::table('clientes')->find($id);
            abort_unless($c, 404, 'Cliente no encontrado.');
            abort_if($c->tipo === 'PLANTA', 422, 'Ese cliente es del sistema y no se edita.');
        }
        $r->merge(['documento' => preg_replace('/\D/', '', (string) $r->input('documento')) ?: null]);
        $d = $r->validate([
            'nombre'    => ['required', 'string', 'min:3', 'max:255'],
            'tipo'      => ['required', 'in:' . implode(',', TiposPrecio::paraClientes())],
            'documento' => ['nullable', 'string'],
            'telefono'  => ['nullable', 'regex:/^[0-9 +]{6,20}$/'],
            'email'     => ['nullable', 'email', 'max:120'],
        ], [
            'nombre.required' => 'Escribe el nombre del cliente o de su negocio.',
            'nombre.min'      => 'El nombre es muy corto.',
            'tipo.required'   => 'Elige qué precio paga (mayorista, cliente directo…).',
            'tipo.in'         => 'Ese tipo de precio no existe o ya no se usa.',
            'telefono.regex'  => 'El teléfono solo lleva números.',
            'email.email'     => 'Ese correo no es válido.',
        ]);
        $doc = $d['documento'];
        abort_if($doc && ! Compras::documentoValido(strlen($doc) === 8 ? 'DNI' : 'RUC', $doc), 422,
            'El DNI lleva 8 dígitos y el RUC 11 (empieza en 10, 15, 16, 17 o 20).');
        abort_if($doc && DB::table('clientes')->where('ruc', $doc)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists(), 422,
            'Ya hay un cliente con ese DNI/RUC.');

        $fila = ['nombre' => trim($d['nombre']), 'tipo' => $d['tipo'], 'ruc' => $doc,
            'telefono' => $d['telefono'] ?? null, 'email' => $d['email'] ?? null, 'updated_at' => now()];
        if ($id) {
            if ($r->has('activo')) $fila['activo'] = $r->boolean('activo');
            DB::table('clientes')->where('id', $id)->update($fila);
        } else {
            $id = DB::table('clientes')->insertGetId($fila + ['activo' => true, 'created_at' => now()]);
        }

        return response()->json(['id' => $id, 'nombre' => $fila['nombre']], 201);
    }

    /**
     * POST /api/v1/compras/ventas — precio según el tipo de cliente.
     * Un producto: { cliente_id, producto_id, cantidad, correo? }
     * Varios en la misma venta y la misma nota: { cliente_id, items: [{ producto_id, cantidad }], correo? }
     * sin_stock: true vende aunque no alcance (el cliente lo pide y no hay): lo que falta queda
     * sin lote, el stock queda en negativo y se descuenta del próximo lote que se cierre.
     */
    public function vender(Request $r)
    {
        if (! $r->has('items')) {
            $r->merge(['items' => [['producto_id' => $r->input('producto_id'), 'cantidad' => $r->input('cantidad')]]]);
        }
        $data = $r->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.producto_id' => ['required', 'distinct', 'exists:productos,id'],
            'items.*.cantidad' => ['required', 'numeric', 'min:0.01'],
            'correo' => ['nullable', 'email', 'max:120'], // opcional: mandar la nota de venta
            'sin_stock' => ['boolean'],
            // las apps de antes no lo mandan: queda en efectivo
            'metodo_pago' => ['nullable', 'in:EFECTIVO,YAPE,TARJETA'],
            'pago_operacion' => ['nullable', 'string', 'max:30'],
            // "Solo vender": quién compra sin registrarlo como cliente (sale en la nota)
            'comprador' => ['nullable', 'array'],
            'comprador.nombre' => ['required_with:comprador', 'string', 'min:3', 'max:150'],
            'comprador.documento' => ['nullable', 'regex:/^(\d{8}|\d{11})$/'],
            // nota de venta (lo de antes), boleta o factura; las apps de antes no lo mandan: nota
            'comprobante' => ['nullable', 'in:NOTA,BOLETA,FACTURA'],
            'adquirente' => ['nullable', 'array'], // a quién va la boleta o factura (si no, el del cliente)
            'adquirente.documento' => ['nullable', 'regex:/^(\d{8}|\d{11})$/'],
            'adquirente.nombre' => ['nullable', 'string', 'max:150'],
            'adquirente.direccion' => ['nullable', 'string', 'max:200'],
        ], [
            'adquirente.documento.regex' => 'El DNI lleva 8 dígitos y el RUC 11.',
            'comprobante.in' => 'Elige nota de venta, boleta o factura.',
            'comprador.nombre.required_with' => 'Escribe el nombre de quien compra.',
            'comprador.documento.regex' => 'El DNI lleva 8 dígitos y el RUC 11.',
            'metodo_pago.in' => 'Elige efectivo, Yape o tarjeta.',
            'correo.email' => 'Ese correo no es válido.',
            'items.required' => 'Elige al menos un producto y cuánto vender.',
            'items.*.producto_id.distinct' => 'Un producto está repetido: pon toda su cantidad en una sola fila.',
            'items.*.cantidad.min' => 'La cantidad debe ser mayor a 0.',
        ]);
        $cliente = DB::table('clientes')->find($data['cliente_id']);
        $productos = collect($this->productos(true))->keyBy('id');

        // primero se revisa todo (precio y stock de cada producto); si algo falla no se vende nada
        $varios = count($data['items']) > 1;
        $sinStock = $r->boolean('sin_stock');
        $lineas = collect($data['items'])->map(function ($it) use ($cliente, $productos, $varios, $sinStock) {
            $p = $productos[(int) $it['producto_id']];
            $precio = $p['precios'][$cliente->tipo] ?? null;
            abort_if(! $precio, 422, "No hay precio vigente de {$p['nombre']} para ese tipo de cliente");
            $vencido = $p['vencido'] > 0 ? " (otros {$p['vencido']} ya vencieron: regístralos en Pérdidas)" : '';
            abort_if(! $sinStock && $p['stock'] < $it['cantidad'], 422, ($varios ? "Solo hay {$p['stock']} de {$p['nombre']} en stock" : "Solo hay {$p['stock']} en stock").$vencido);
            return ['producto_id' => $p['id'], 'cantidad' => (float) $it['cantidad'], 'precio' => (float) $precio];
        });

        $pago = ['metodo_pago' => $data['metodo_pago'] ?? 'EFECTIVO',
            'pago_operacion' => ($data['metodo_pago'] ?? 'EFECTIVO') !== 'EFECTIVO' ? (trim($data['pago_operacion'] ?? '') ?: null) : null,
            'comprador_nombre' => isset($data['comprador']) ? trim($data['comprador']['nombre']) : null,
            'comprador_documento' => $data['comprador']['documento'] ?? null];

        // boleta o factura: a quién va (lo escrito, o lo del comprador o del cliente) y lo que pide SUNAT
        $tipo = $data['comprobante'] ?? 'NOTA';
        $ad = $data['adquirente'] ?? [];
        $adquirente = $tipo === 'NOTA' ? null : [
            'documento' => $ad['documento'] ?? $pago['comprador_documento'] ?? ($pago['comprador_nombre'] ? null : $cliente->ruc),
            'nombre' => trim($ad['nombre'] ?? '') ?: ($pago['comprador_nombre'] ?? (Comprobantes::esGeneral($cliente) ? null : $cliente->nombre)),
            'direccion' => trim($ad['direccion'] ?? '') ?: null,
        ];
        $error = Comprobantes::error($tipo, round($lineas->sum(fn ($l) => round($l['precio'] * $l['cantidad'], 2)), 2),
            $adquirente['documento'] ?? null, $adquirente['nombre'] ?? null);
        abort_if($error, 422, $error);

        $ids = DB::transaction(function () use ($lineas, $cliente, $r, $sinStock, $pago, $tipo, $adquirente) {
            $ids = [];
            foreach ($lineas as $l) {
                // sale primero lo que vence primero; si un lote no alcanza, sigue con el siguiente (una fila por lote)
                $falta = $l['cantidad'];
                foreach (Mermas::lotesConStock($l['producto_id'])->reject->vencido as $lote) {
                    if ($falta <= 0.005) break;
                    $cant = round(min($falta, $lote->queda), 2);
                    $falta = round($falta - $cant, 2);
                    $ids[] = $this->filaVendida($ids, $cliente->id, $l, $lote->id, $cant, $r->user()->id, $pago);
                }
                abort_if($falta > 0.005 && ! $sinStock, 422, 'Alguien vendió al mismo tiempo y ya no alcanza el stock. Vuelve a intentar.');
                if ($falta > 0.005) $ids[] = $this->filaVendida($ids, $cliente->id, $l, null, $falta, $r->user()->id, $pago); // sin stock: sin lote
            }
            // con varios productos, la primera fila también lleva la nota (así se encuentran todas)
            if (count($ids) > 1) DB::table('ventas')->where('id', $ids[0])->update(['nota_id' => $ids[0]]);
            // boleta o factura: su serie y el número que sigue, en la fila de la nota
            if ($adquirente) {
                $serie = Comprobantes::serie($tipo);
                DB::table('ventas')->where('id', $ids[0])->update([
                    'comprobante_tipo' => $tipo, 'comprobante_serie' => $serie, 'comprobante_numero' => Comprobantes::siguiente($serie),
                    'adquirente_documento' => $adquirente['documento'], 'adquirente_nombre' => $adquirente['nombre'],
                    'adquirente_direccion' => $adquirente['direccion'],
                ]);
            }
            return $ids;
        });
        $id = $ids[0];
        $total = round((float) DB::table('ventas')->whereIn('id', $ids)->sum('total'), 2);
        $precio = $lineas->first()['precio'];

        // si pidieron correo, se manda la nota; si falla, la venta igual queda hecha
        $errorCorreo = ! empty($data['correo'])
            ? NotaVentaController::mandar(app(\App\Services\NotaVentaPdf::class), $id, $data['correo'])
            : null;

        // id y número de la nota de venta, para imprimirla (NotaVentaController)
        return response()->json(['total' => $total, 'precio_unitario' => (float) $precio, 'metodo_pago' => $pago['metodo_pago'],
            'id' => $id, 'numero' => NotaVentaController::numero($id), 'comprobante' => $tipo,
            'correo' => $data['correo'] ?? null,
            'correo_enviado' => ! empty($data['correo']) && ! $errorCorreo,
            'correo_error' => $errorCorreo], 201);
    }

    /** GET /api/v1/compras/documento/{dni o ruc} — el nombre de esa persona o empresa (ver ConsultaDocumento). */
    public function documento(string $numero)
    {
        return response()->json(ConsultaDocumento::buscar($numero));
    }

    private function filaVendida(array $ids, int $clienteId, array $l, ?int $loteId, float $cant, int $usuarioId, array $pago): int
    {
        return DB::table('ventas')->insertGetId($pago + [
            'uuid' => (string) Str::uuid(), 'nota_id' => $ids[0] ?? null, 'cliente_id' => $clienteId,
            'producto_id' => $l['producto_id'], 'lote_id' => $loteId, 'cantidad' => $cant,
            'precio_unitario' => $l['precio'], 'total' => round($l['precio'] * $cant, 2),
            'registrada_por' => $usuarioId, 'vendida_en' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
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
                'periodo' => $mes + ['vendido' => round((float) $ventas->sum('total'), 2), 'ventas' => $ventas->count(),
                    // para cuadrar la caja: cuánto entró en efectivo, por Yape y con tarjeta
                    'por_metodo' => collect(['EFECTIVO', 'YAPE', 'TARJETA'])
                        ->mapWithKeys(fn ($m) => [$m => round((float) $ventas->where('metodo_pago', $m)->sum('total'), 2)])],
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
            // la fila de la nota lleva el comprobante (boleta o factura) de toda la venta
            ->join('ventas as n', 'n.id', '=', DB::raw('COALESCE(v.nota_id, v.id)'))
            ->select(['v.id', DB::raw('COALESCE(v.nota_id, v.id) as nota'), 'p.nombre as que', 'p.unidad', DB::raw('COALESCE(n.adquirente_nombre, v.comprador_nombre, c.nombre) as cliente'), 'v.total', 'v.cantidad', 'v.vendida_en as fecha', 'v.metodo_pago', 'v.pago_operacion',
                'n.comprobante_tipo', 'n.comprobante_serie', 'n.comprobante_numero']);
    }

    private function filaVenta(object $v): array
    {
        return [
            'venta_id' => $v->id, 'numero' => Comprobantes::numero($v->comprobante_serie, $v->comprobante_numero, (int) $v->nota),
            'comprobante' => $v->comprobante_tipo,
            'que' => $v->que, 'ref' => "Venta · {$v->cliente} · S/ ".number_format($v->total, 2),
            'fecha' => $v->fecha, 'entrada' => false,
            'delta' => '−'.rtrim(rtrim(number_format($v->cantidad, 2, '.', ''), '0'), '.'),
            // la web arma una fila por nota con estos datos sueltos (la app usa ref y delta)
            'nota' => (int) $v->nota, 'cliente' => $v->cliente, 'total' => round((float) $v->total, 2),
            'cantidad' => (float) $v->cantidad, 'unidad' => $v->unidad,
            'metodo_pago' => $v->metodo_pago, 'pago_operacion' => $v->pago_operacion,
        ];
    }
}
