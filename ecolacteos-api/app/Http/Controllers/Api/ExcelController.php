<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Compras;
use App\Services\Comprobantes;
use App\Services\Excel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Descargas en Excel para la web de oficina. Cada una saca lo mismo que se ve
 * en su pantalla (pagos de la semana, padrón, compras) o el mes elegido
 * (?mes=AAAA-MM, o ?mes=todo).
 */
class ExcelController extends Controller
{
    /** GET /admin/excel/pagos?semana=AAAA-MM-DD — boletas de la semana. */
    public function pagos(Request $r)
    {
        $d = app(AdminPagosController::class)->semana($r)->getData(true);

        return Excel::descargar(
            "pagos_{$d['inicio']}_al_{$d['fin']}.xlsx",
            'Pagos del ' . Carbon::parse($d['inicio'])->format('d/m') . ' al ' . Carbon::parse($d['fin'])->format('d/m/Y'),
            ['Productor' => 'texto', 'DNI' => 'texto', 'Comunidad' => 'texto', 'Vehículo' => 'texto',
                'Litros' => 'numero', 'Monto' => 'soles', 'Estado' => 'texto', 'Método' => 'texto',
                'Pagado el' => 'fecha', 'Pagado por' => 'texto'],
            array_map(fn ($b) => [$b['proveedor'], $b['dni'], $b['comunidad'], $b['vehiculo'], $b['litros'],
                $b['monto'], $b['estado'], $b['metodo'], $b['pagado_el'], $b['pagado_por']], $d['boletas']),
            ['Litros' => array_sum(array_column($d['boletas'], 'litros')), 'Monto' => $d['total']],
        );
    }

    /** GET /admin/excel/entregas?mes=AAAA-MM — leche recibida día por día. */
    public function entregas(Request $r)
    {
        [$desde, $hasta, $mes] = Compras::mes($r->query('mes'));
        $filas = DB::table('entregas as e')
            ->join('proveedores as p', 'p.id', '=', 'e.proveedor_id')
            ->join('comunidades as c', 'c.id', '=', 'p.comunidad_id')
            ->join('vehiculos as v', 'v.id', '=', 'e.vehiculo_id')
            ->when($desde, fn ($q) => $q->whereBetween('e.fecha', [$desde, $hasta]))
            ->orderBy('e.fecha')->orderBy('v.codigo')->orderBy('p.nombre')
            ->get(['e.fecha', 'e.registrado_en', 'p.nombre', 'p.dni', 'c.nombre as comunidad', 'v.codigo as vehiculo',
                'e.litros', 'e.ausente', 'e.estado_calidad', 'e.precio_litro', 'e.monto']);

        return Excel::descargar(
            'entregas_' . $mes['mes'] . '.xlsx',
            'Entregas de leche · ' . self::nombreMes($mes['mes']),
            ['Fecha' => 'fecha', 'Hora' => 'texto', 'Productor' => 'texto', 'DNI' => 'texto', 'Comunidad' => 'texto',
                'Vehículo' => 'texto', 'Litros' => 'numero', 'No entregó' => 'texto', 'Calidad' => 'texto',
                'Precio por litro' => 'soles', 'Monto' => 'soles'],
            $filas->map(fn ($e) => [$e->fecha, Carbon::parse($e->registrado_en)->format('H:i'), $e->nombre, $e->dni,
                $e->comunidad, $e->vehiculo, $e->litros, $e->ausente ? 'Sí' : '', $e->estado_calidad,
                $e->precio_litro, $e->monto]),
            ['Litros' => (float) $filas->sum('litros'), 'Monto' => (float) $filas->sum('monto')],
        );
    }

    /** GET /admin/excel/padron — productores con lo que entregan. */
    public function padron()
    {
        $provs = app(AdminAppController::class)->padron()->getData(true)['proveedores'];

        return Excel::descargar(
            'padron_productores.xlsx',
            'Padrón de productores',
            ['Productor' => 'texto', 'DNI' => 'texto', 'Estado' => 'texto', 'Comunidad' => 'texto', 'Vehículo' => 'texto',
                'Vacas en ordeño' => 'numero', 'Promedio declarado (L)' => 'numero', 'Litros últimos 30 días' => 'numero',
                'Días que entregó (30)' => 'numero', 'Litros últimos 7 días' => 'numero', 'Última entrega' => 'fecha',
                'Motivo de baja' => 'texto'],
            array_map(fn ($p) => [$p['nombre'], $p['dni'], $p['estado'], $p['comunidad'], $p['vehiculo'], $p['vacas'],
                $p['promedio'], $p['leche']['litros_30'] ?? 0, $p['leche']['dias_30'] ?? 0, $p['leche']['litros_7'] ?? 0,
                $p['leche']['ultima'] ?? null, $p['motivo_baja']], $provs),
        );
    }

    /** GET /calidad/excel/analisis?mes=AAAA-MM — análisis de calidad del mes. */
    public function analisis(Request $r)
    {
        [$desde, $hasta, $mes] = Compras::mes($r->query('mes'));
        $filas = DB::table('analisis_calidad as a')
            ->join('entregas as e', 'e.id', '=', 'a.entrega_id')
            ->join('proveedores as p', 'p.id', '=', 'e.proveedor_id')
            ->join('vehiculos as v', 'v.id', '=', 'e.vehiculo_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.analista_id')
            ->whereNotNull('a.veredicto')
            ->when($desde, fn ($q) => $q->whereBetween('e.fecha', [$desde, $hasta]))
            ->orderBy('a.tomada_en')
            ->get(['e.fecha', 'a.tomada_en', 'p.nombre', 'v.codigo as vehiculo', 'e.litros', 'a.muestra', 'a.veredicto',
                'a.causas', 'a.grasa', 'a.proteina', 'a.densidad', 'a.temperatura', 'a.agua_anadida', 'a.ph',
                'u.name as analista']);

        return Excel::descargar(
            'analisis_calidad_' . $mes['mes'] . '.xlsx',
            'Análisis de calidad · ' . self::nombreMes($mes['mes']),
            ['Fecha' => 'fecha', 'Hora' => 'texto', 'Productor' => 'texto', 'Vehículo' => 'texto', 'Litros' => 'numero',
                'Muestra' => 'texto', 'Veredicto' => 'texto', 'Causas' => 'texto', 'Grasa %' => 'numero',
                'Proteína %' => 'numero', 'Densidad' => 'densidad', 'Temperatura °C' => 'numero', 'Agua añadida %' => 'numero',
                'pH' => 'numero', 'Analista' => 'texto'],
            $filas->map(fn ($a) => [$a->fecha, Carbon::parse($a->tomada_en)->format('H:i'), $a->nombre, $a->vehiculo,
                $a->litros, $a->muestra, $a->veredicto, implode('; ', json_decode($a->causas ?? '[]', true) ?: []),
                $a->grasa, $a->proteina, $a->densidad, $a->temperatura, $a->agua_anadida, $a->ph, $a->analista]),
        );
    }

    /** GET /compras/excel/ventas?mes=AAAA-MM — cada producto vendido, con su nota. */
    public function ventas(Request $r)
    {
        [$desde, $hasta, $mes] = Compras::mes($r->query('mes'));
        $filas = DB::table('ventas as s')
            ->join('clientes as c', 'c.id', '=', 's.cliente_id')
            ->join('productos as p', 'p.id', '=', 's.producto_id')
            ->leftJoin('users as u', 'u.id', '=', 's.registrada_por')
            ->join('ventas as n', 'n.id', '=', DB::raw('COALESCE(s.nota_id, s.id)')) // la fila con la boleta o factura
            ->when($desde, fn ($q) => $q->whereBetween('s.vendida_en', [$desde . ' 00:00:00', $hasta . ' 23:59:59']))
            ->orderBy('s.vendida_en')->orderBy('s.id')
            ->get(['s.id', 's.nota_id', 's.vendida_en', DB::raw('COALESCE(n.adquirente_nombre, s.comprador_nombre, c.nombre) as cliente'),
                DB::raw('COALESCE(n.adquirente_documento, CASE WHEN s.comprador_nombre IS NULL THEN c.ruc ELSE s.comprador_documento END) as ruc'),
                'n.comprobante_tipo', 'n.comprobante_serie', 'n.comprobante_numero', 'p.nombre as producto',
                's.cantidad', 'p.unidad', 's.precio_unitario', 's.total', 's.metodo_pago', 's.pago_operacion', 'u.name as vendedor']);

        return Excel::descargar(
            'ventas_' . $mes['mes'] . '.xlsx',
            'Ventas · ' . self::nombreMes($mes['mes']),
            ['Fecha' => 'fecha', 'Hora' => 'texto', 'Comprobante' => 'texto', 'Número' => 'texto', 'Cliente' => 'texto', 'DNI / RUC' => 'texto',
                'Producto' => 'texto', 'Cantidad' => 'numero', 'Unidad' => 'texto', 'Precio unitario' => 'soles',
                'Total' => 'soles', 'Pagó con' => 'texto', 'N.° operación' => 'texto', 'Registrada por' => 'texto'],
            $filas->map(fn ($s) => [$s->vendida_en, Carbon::parse($s->vendida_en)->format('H:i'),
                ['BOLETA' => 'Boleta', 'FACTURA' => 'Factura'][$s->comprobante_tipo] ?? 'Nota de venta',
                Comprobantes::numero($s->comprobante_serie, $s->comprobante_numero, (int) ($s->nota_id ?? $s->id)), $s->cliente, $s->ruc,
                $s->producto, $s->cantidad, $s->unidad, $s->precio_unitario, $s->total,
                ['EFECTIVO' => 'Efectivo', 'YAPE' => 'Yape', 'TARJETA' => 'Tarjeta'][$s->metodo_pago] ?? $s->metodo_pago, $s->pago_operacion, $s->vendedor]),
            ['Total' => (float) $filas->sum('total')],
        );
    }

    /** GET /compras/excel/compras?mes=AAAA-MM — compras de insumos, un renglón por insumo. */
    public function compras(Request $r)
    {
        $r->merge(['mes' => $r->query('mes', 'actual')]);
        $ordenes = app(OrdenesCompraController::class)->index($r)->getData(true)['ordenes'];
        [, , $mes] = Compras::mes($r->query('mes'));

        $filas = [];
        $total = 0.0;
        foreach ($ordenes as $o) {
            foreach ($o['items'] as $i) {
                $costo = $i['costo_real'] ?? $i['costo_estimado'];
                if ($o['estado'] !== 'ANULADA') {
                    $total += (float) $costo;
                }
                $filas[] = [$o['recibida_en'] ?? $o['created_at'], $o['codigo'], $o['estado'], $o['proveedor'],
                    $o['vendedor']['documento'] ?? null, $o['comprobante'], $i['insumo'], $i['cantidad'], $i['unidad'],
                    $costo];
            }
        }

        return Excel::descargar(
            'compras_insumos_' . $mes['mes'] . '.xlsx',
            'Compras de insumos · ' . self::nombreMes($mes['mes']),
            ['Fecha' => 'fecha', 'Orden' => 'texto', 'Estado' => 'texto', 'Vendedor' => 'texto', 'DNI/RUC' => 'texto',
                'Comprobante' => 'texto', 'Insumo' => 'texto', 'Cantidad' => 'numero', 'Unidad' => 'texto',
                'Costo' => 'soles'],
            $filas,
            ['Costo' => $total], // las anuladas no suman
        );
    }

    /** "2026-10" → "octubre 2026"; "todo" → "todo el historial". */
    private static function nombreMes(string $mes): string
    {
        return $mes === 'todo' ? 'todo el historial' : Carbon::createFromFormat('!Y-m', $mes)->locale('es')->translatedFormat('F Y');
    }
}
