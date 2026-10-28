<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kardex valorizado por costo promedio ponderado: cada entrada entra a su
 * costo, cada salida sale al costo promedio del momento. Hay tres tipos:
 *
 *  insumo    entra: compras recibidas y ajustes · sale: lo que usa cada lote, mermas
 *  producto  entra: lo obtenido al cerrar cada lote, al costo del lote
 *                   (leche + insumos que usó) · sale: cada venta
 *  leche     entra: acopio aceptado por día, al precio pagado · sale: lo que entra a cada lote
 *
 * En insumos, el stock que había antes del primer movimiento registrado aparece
 * como "Saldo inicial", así el saldo final siempre cuadra con el stock.
 */
class Kardex
{
    public const TIPOS = ['orden_compra' => 'COMPRA', 'lote' => 'PRODUCCION', 'ajuste' => 'AJUSTE', 'merma' => 'MERMA'];

    /** $solo: de un producto, solo ese (Queso Paria) y no toda su familia (todos los quesos). */
    public static function de(string $tipo, ?int $id, ?string $desde = null, ?string $hasta = null, bool $solo = false): array
    {
        [$item, $movs, $cppInicial] = match ($tipo) {
            'insumo' => self::insumo($id),
            'producto' => self::producto($id, $solo),
            'leche' => self::leche(),
            default => abort(422, 'Tipo de kardex no válido'),
        };

        return ['item' => $item, 'desde' => $desde, 'hasta' => $hasta]
            + self::periodo(self::calcular($movs, $cppInicial), $desde, $hasta);
    }

    // ------------------------------------------------------------ movimientos

    /** @return array{0: array, 1: array, 2: float} */
    private static function insumo(?int $id): array
    {
        $insumo = DB::table('insumos')->find($id);
        abort_if(! $insumo, 404, 'Insumo no encontrado');

        $movs = DB::table('movimientos_insumo as m')->leftJoin('users as u', 'u.id', '=', 'm.registrado_por')
            ->where('m.insumo_id', $id)->orderBy('m.created_at')->orderBy('m.id')
            ->get(['m.id', 'm.cantidad', 'm.referencia', 'm.origen_type', 'm.origen_id', 'm.created_at', 'u.name as usuario']);
        // la línea de este insumo en cada orden de compra (con su vendedor y comprobante)
        $ordenes = DB::table('orden_compra_items as it')->join('ordenes_compra as o', 'o.id', '=', 'it.orden_compra_id')
            ->where('it.insumo_id', $id)->orderBy('o.created_at')->orderBy('it.id')
            ->get(['o.id', 'o.codigo', 'o.proveedor_comercial', 'o.comprobante_tipo', 'o.comprobante_serie', 'o.comprobante_numero',
                'it.cantidad', 'it.costo_estimado', 'it.costo_real'])->keyBy('id');
        $costoDe = fn ($o) => Compras::costoEnOrden($o);

        $lotes = DB::table('lotes as l')->join('productos as p', 'p.id', '=', 'l.producto_id')
            ->whereIn('l.id', $movs->where('origen_type', 'lote')->pluck('origen_id'))
            ->get(['l.id', 'l.codigo', 'p.nombre as producto'])->keyBy('id');

        $lista = [];
        $inicial = round((float) $insumo->stock - (float) $movs->sum('cantidad'), 3);
        if (abs($inicial) >= 0.001) {
            $lista[] = self::mov((string) ($movs->first()->created_at ?? $insumo->created_at ?? now()), 'INICIAL', 'Saldo inicial', null, $inicial,
                texto: 'Ya había en el almacén cuando se empezó a registrar');
        }
        foreach ($movs as $m) {
            $tipo = self::TIPOS[$m->origen_type] ?? 'AJUSTE';
            $oc = $m->origen_type === 'orden_compra' ? ($ordenes[$m->origen_id] ?? null) : null;
            $lote = $m->origen_type === 'lote' ? ($lotes[$m->origen_id] ?? null) : null;
            $texto = match (true) {
                $oc !== null => "Llegó la compra {$oc->codigo} de {$oc->proveedor_comercial}"
                    .(($c = Compras::textoComprobante($oc->comprobante_tipo, $oc->comprobante_serie, $oc->comprobante_numero)) ? " ($c)" : ''),
                $lote !== null => "Se usó para hacer el lote {$lote->codigo} ({$lote->producto})",
                $tipo === 'MERMA' => 'Se perdió: '.preg_replace('/^Pérdida · /u', '', $m->referencia),
                $tipo === 'AJUSTE' => ($m->cantidad > 0 ? 'Se agregó por ajuste: ' : 'Se sacó por ajuste: ')
                    .preg_replace('/^Ajuste · /u', '', $m->referencia),
                default => $m->referencia,
            };
            $lista[] = self::mov((string) $m->created_at, $tipo, $m->referencia, $m->usuario,
                (float) $m->cantidad, $m->cantidad > 0 ? $costoDe($oc) : null, $m->origen_type === 'lote' ? $m->origen_id : null, $texto);
        }

        $item = ['tipo' => 'insumo', 'id' => $insumo->id, 'nombre' => $insumo->nombre, 'unidad' => $insumo->unidad,
            'stock' => (float) $insumo->stock, 'minimo' => (float) $insumo->stock_minimo];

        // antes de cualquier compra, al costo de la primera
        return [$item, $lista, (float) ($costoDe($ordenes->first()) ?? 0)];
    }

    /** Un solo kardex para todos los quesos y otro para todos los yogures (con la misma unidad, para poder sumarlos). */
    public const NOMBRE_FAMILIA = ['YOGURT' => 'Yogurt', 'QUESO' => 'Queso'];

    private static function producto(?int $id, bool $solo = false): array
    {
        $p = DB::table('productos')->find($id);
        abort_if(! $p, 404, 'Producto no encontrado');
        // Queso Paria y andino juntos; Yogurt natural, de fresa, de maracuyá… juntos. Lo demás, con sus sabores.
        // Con $solo, únicamente el producto elegido.
        $familia = ($solo ? DB::table('productos')->where('id', $p->id) : (isset(self::NOMBRE_FAMILIA[$p->tipo])
            ? DB::table('productos')->where('tipo', $p->tipo)->where('unidad', $p->unidad)
            : DB::table('productos')->where('id', $p->base_id ?? $p->id)->orWhere('base_id', $p->base_id ?? $p->id)))
            ->orderBy('id')->pluck('nombre', 'id');
        $base = DB::table('productos')->find($familia->keys()->first());
        $ids = $familia->keys()->all();
        $varios = count($ids) > 1;
        $costoLote = self::costosDeLotes();
        $u = explode(' ', $base->unidad)[0]; // "quesos de 1 kg" → "quesos"
        $con = function (array $m, int $productoId) use ($familia, $varios) {
            $m['que'] = $varios ? $familia[$productoId] : null; // cuál de la familia, para leerlo en la fila
            return $m;
        };

        $lista = [];
        $lotes = DB::table('lotes as l')->leftJoin('users as u', 'u.id', '=', 'l.operario_id')
            ->whereIn('l.producto_id', $ids)->where('l.estado', 'TERMINADO')->where('l.obtenido', '>', 0)
            ->get(['l.id', 'l.producto_id', 'l.codigo', 'l.litros_leche', 'l.obtenido', 'l.cerrado_en', 'l.iniciado_en', 'u.name as usuario']);
        foreach ($lotes as $l) {
            $cu = ($costoLote[$l->id] ?? 0) / $l->obtenido;
            $lista[] = $con(self::mov((string) ($l->cerrado_en ?? $l->iniciado_en), 'PRODUCCION',
                "{$l->codigo} · lote cerrado con ".self::n($l->litros_leche).' L de leche', $l->usuario, (float) $l->obtenido, $cu,
                texto: "Se hizo en el lote {$l->codigo} con ".self::n($l->litros_leche).' L de leche'), $l->producto_id);
        }
        $ventas = DB::table('ventas as v')->join('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->leftJoin('users as u', 'u.id', '=', 'v.registrada_por')->leftJoin('lotes as l', 'l.id', '=', 'v.lote_id')
            ->whereIn('v.producto_id', $ids)
            ->get(['v.id', 'v.producto_id', DB::raw('COALESCE(v.nota_id, v.id) as nota'), 'v.cantidad', 'v.precio_unitario', 'v.vendida_en', DB::raw('COALESCE(v.comprador_nombre, c.nombre) as cliente'), 'l.codigo as lote', 'u.name as usuario']);
        foreach ($ventas as $v) {
            $lista[] = $con(self::mov((string) $v->vendida_en, 'VENTA',
                NotaVentaPdf::numero((int) $v->nota)." · {$v->cliente} · S/ ".number_format($v->precio_unitario, 2).' c/u'.($v->lote ? " · {$v->lote}" : ''),
                $v->usuario, -(float) $v->cantidad,
                texto: "Se vendió a {$v->cliente} a S/ ".number_format($v->precio_unitario, 2).' c/u (nota '.NotaVentaPdf::numero((int) $v->nota).')'), $v->producto_id);
        }

        foreach (self::mermas('PRODUCTO', $ids) as $m) {
            $lista[] = $con(self::mov((string) $m->fecha, 'MERMA', $m->motivo_texto.($m->detalle ? " · {$m->detalle}" : ''), $m->usuario,
                -(float) $m->cantidad, texto: 'Se perdió: '.mb_strtolower($m->motivo_texto).($m->detalle ? " ({$m->detalle})" : '')
                    .($m->lote ? " · lote {$m->lote}" : '')), $m->producto_id);
        }

        $item = ['tipo' => 'producto', 'id' => $base->id, 'unidad' => $u, 'minimo' => 0,
            'nombre' => $varios ? (self::NOMBRE_FAMILIA[$base->tipo] ?? $base->nombre) : $base->nombre,
            'productos' => $varios ? $familia->values() : [], // los que entran en este kardex
            'stock' => array_sum(array_map(fn ($i) => Mermas::stockProducto($i), $ids))];

        // si una venta quedó antes del cierre de su lote, sale al costo del primer lote
        $lista = self::ordenar($lista);
        $primero = collect($lista)->firstWhere('tipo', 'PRODUCCION');

        return [$item, $lista, (float) ($primero['costo'] ?? 0)];
    }

    private static function leche(): array
    {
        $base = Configuracion::precioLeche(); // solo si a una entrega le faltara su precio
        $lista = [];

        // acopio aceptado, un renglón por día (al inicio del día: los lotes de ese día salen después)
        $dias = DB::table('entregas')->where('estado_calidad', 'ACEPTADO')->where('ausente', false)->where('litros', '>', 0)
            ->selectRaw('fecha, SUM(litros) as litros, SUM(litros * COALESCE(precio_litro, ?)) as valor, COUNT(*) as entregas', [$base])
            ->groupBy('fecha')->get();
        foreach ($dias as $d) {
            $lista[] = self::mov(Carbon::parse($d->fecha)->startOfDay()->toDateTimeString(), 'ACOPIO',
                "Acopio aceptado · {$d->entregas} entregas", null, (float) $d->litros, $d->valor / $d->litros,
                texto: "Leche del día aceptada por Calidad ({$d->entregas} entregas de productores)");
        }
        $lotes = DB::table('lote_entrega as le')->join('lotes as l', 'l.id', '=', 'le.lote_id')
            ->join('productos as p', 'p.id', '=', 'l.producto_id')->leftJoin('users as u', 'u.id', '=', 'l.operario_id')
            ->selectRaw('l.id, l.codigo, l.iniciado_en, p.nombre as producto, u.name as usuario, SUM(le.litros) as litros')
            ->groupBy('l.id', 'l.codigo', 'l.iniciado_en', 'p.nombre', 'u.name')->get();
        foreach ($lotes as $l) {
            $lista[] = self::mov((string) $l->iniciado_en, 'PRODUCCION', "{$l->codigo} · {$l->producto}", $l->usuario,
                -(float) $l->litros, null, $l->id, "Se usó para hacer el lote {$l->codigo} ({$l->producto})");
        }

        foreach (self::mermas('LECHE') as $m) {
            $lista[] = self::mov((string) $m->fecha, 'MERMA', $m->motivo_texto.($m->detalle ? " · {$m->detalle}" : ''), $m->usuario,
                -(float) $m->cantidad, texto: 'Se perdió: '.mb_strtolower($m->motivo_texto).($m->detalle ? " ({$m->detalle})" : ''));
        }

        $item = ['tipo' => 'leche', 'id' => null, 'nombre' => 'Leche cruda (aceptada)', 'unidad' => 'L',
            // todo lo que entró menos lo que salió, para que cuadre con los movimientos (también la leche vieja que nunca se usó)
            'stock' => round(Mermas::lecheSinUsar() + Mermas::lecheVieja(), 2), 'minimo' => 0];

        return [$item, self::ordenar($lista), $base];
    }

    /** Costo de cada lote = leche que entró (al promedio del momento) + insumos que se le descontaron. */
    public static function costosDeLotes(): array
    {
        $costo = [];
        [, $leche, $base] = self::leche();
        foreach (self::calcular($leche, $base) as $f) {
            if ($f['lote'] && $f['salida']) $costo[$f['lote']] = ($costo[$f['lote']] ?? 0) + $f['salida']['total'];
        }
        $usados = DB::table('movimientos_insumo')->where('origen_type', 'lote')->distinct()->pluck('insumo_id');
        foreach ($usados as $insumoId) {
            [, $movs, $cpp] = self::insumo($insumoId);
            foreach (self::calcular($movs, $cpp) as $f) {
                if ($f['lote'] && $f['salida']) $costo[$f['lote']] = ($costo[$f['lote']] ?? 0) + $f['salida']['total'];
            }
        }

        return $costo;
    }

    /** Pérdidas registradas, con el motivo en palabras. */
    private static function mermas(string $tipo, array $productos = [])
    {
        return DB::table('mermas as m')->leftJoin('users as u', 'u.id', '=', 'm.registrado_por')
            ->leftJoin('lotes as l', 'l.id', '=', 'm.lote_id')
            ->where('m.tipo', $tipo)->when($productos, fn ($q) => $q->whereIn('m.producto_id', $productos))
            ->get(['m.fecha', 'm.producto_id', 'm.cantidad', 'm.motivo', 'm.detalle', 'l.codigo as lote', 'u.name as usuario'])
            ->map(function ($m) use ($tipo) {
                $m->motivo_texto = Mermas::MOTIVOS[$tipo][$m->motivo] ?? $m->motivo;
                return $m;
            });
    }

    // ---------------------------------------------------------------- cálculo

    private static function mov(string $fecha, string $tipo, string $detalle, ?string $usuario, float $cantidad, ?float $costo = null, $lote = null, ?string $texto = null): array
    {
        $texto ??= $detalle; // explicación en palabras simples para la vista sencilla

        return compact('fecha', 'tipo', 'detalle', 'usuario', 'cantidad', 'costo', 'lote', 'texto');
    }

    private static function ordenar(array $lista): array
    {
        usort($lista, fn ($a, $b) => strcmp($a['fecha'], $b['fecha']) ?: ($b['cantidad'] <=> $a['cantidad'])); // a la misma hora, primero lo que entra
        return $lista;
    }

    /** Recorre los movimientos y calcula el saldo y el costo promedio después de cada uno. */
    private static function calcular(array $movs, float $cpp): array
    {
        $cant = 0.0; $valor = 0.0; $filas = [];
        foreach ($movs as $m) {
            $q = $m['cantidad'];
            if ($q >= 0) {
                $cu = $m['costo'] ?? $cpp;
                $cant += $q; $valor += $q * $cu;
                $fila = ['entrada' => ['cantidad' => $q, 'costo' => $cu, 'total' => $q * $cu], 'salida' => null];
            } else {
                $s = -$q;
                $fila = ['entrada' => null, 'salida' => ['cantidad' => $s, 'costo' => $cpp, 'total' => $s * $cpp]];
                $cant -= $s; $valor -= $s * $cpp;
            }
            // un saldo negativo (stock corregido a mano) se valora al costo vigente para no inflar el promedio
            if ($cant > 0.0005) $cpp = $valor / $cant; else $valor = $cant * $cpp;
            $filas[] = ['fecha' => $m['fecha'], 'tipo' => $m['tipo'], 'detalle' => $m['detalle'], 'usuario' => $m['usuario'], 'lote' => $m['lote'], 'texto' => $m['texto'], 'que' => $m['que'] ?? null]
                + $fila + ['saldo' => ['cantidad' => $cant, 'costo' => $cpp, 'total' => $valor]];
        }

        return $filas;
    }

    /** Filtra por fechas: lo anterior a "desde" se junta en una fila de saldo anterior. */
    private static function periodo(array $filas, ?string $desde, ?string $hasta): array
    {
        $tz = 'America/Lima';
        $ini = $desde ? Carbon::parse($desde, $tz)->startOfDay() : null;
        $fin = $hasta ? Carbon::parse($hasta, $tz)->endOfDay() : null;
        $antes = $ini ? array_values(array_filter($filas, fn ($f) => Carbon::parse($f['fecha'])->lt($ini))) : [];
        $periodo = array_values(array_filter($filas, fn ($f) => (! $ini || Carbon::parse($f['fecha'])->gte($ini))
            && (! $fin || Carbon::parse($f['fecha'])->lte($fin))));
        if ($antes) {
            array_unshift($periodo, ['fecha' => $ini->toDateTimeString(), 'tipo' => 'ANTERIOR', 'detalle' => 'Saldo anterior',
                'texto' => 'Lo que había antes de esta fecha', 'usuario' => null, 'entrada' => null, 'salida' => null, 'saldo' => end($antes)['saldo']]);
        }

        $redondear = fn ($v) => $v === null ? null
            : ['cantidad' => round($v['cantidad'], 3), 'costo' => round($v['costo'], 4), 'total' => round($v['total'], 2)];
        $periodo = array_map(fn ($f) => ['fecha' => $f['fecha'], 'tipo' => $f['tipo'], 'detalle' => $f['detalle'], 'texto' => $f['texto'], 'usuario' => $f['usuario'], 'que' => $f['que'] ?? null,
            'entrada' => $redondear($f['entrada']), 'salida' => $redondear($f['salida']), 'saldo' => $redondear($f['saldo'])], $periodo);
        $suma = fn ($lado, $campo) => round(array_sum(array_map(fn ($f) => $f['tipo'] === 'ANTERIOR' ? 0 : ($f[$lado][$campo] ?? 0), $periodo)), $campo === 'total' ? 2 : 3);

        // cuánto entró y salió por cada tipo de movimiento ("45 kg en producción, 2 kg en ajustes")
        $resumen = [];
        foreach ($periodo as $f) {
            if ($f['tipo'] === 'ANTERIOR') continue;
            $r = &$resumen[$f['tipo']];
            $r ??= ['tipo' => $f['tipo'], 'entra' => 0.0, 'sale' => 0.0];
            $r['entra'] = round($r['entra'] + ($f['entrada']['cantidad'] ?? 0), 3);
            $r['sale'] = round($r['sale'] + ($f['salida']['cantidad'] ?? 0), 3);
            unset($r);
        }

        return [
            'filas' => $periodo,
            'resumen' => array_values($resumen),
            'totales' => [
                'inicio' => $antes ? round(end($antes)['saldo']['cantidad'], 3) : 0,
                'entradas' => ['cantidad' => $suma('entrada', 'cantidad'), 'total' => $suma('entrada', 'total')],
                'salidas' => ['cantidad' => $suma('salida', 'cantidad'), 'total' => $suma('salida', 'total')],
                'saldo' => $periodo ? end($periodo)['saldo'] : ['cantidad' => 0, 'costo' => 0, 'total' => 0],
            ],
        ];
    }

    private static function n($x): string
    {
        return rtrim(rtrim(number_format((float) $x, 2, '.', ','), '0'), '.');
    }
}
