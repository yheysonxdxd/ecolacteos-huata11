<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/admin/negocio?periodo=dia|semana|mes
 * Lo que pasa después del acopio, para el administrador: ventas (qué, a quién,
 * cuánto), producción (lotes y rendimiento) e inventario (queso/yogurt en
 * stock, insumos bajo mínimo, órdenes de compra por recibir).
 * Solo ADMIN (ruta bajo admin/).
 */
class AdminNegocioController extends Controller
{
    private const DIAS = [1 => 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'];

    public function resumen(Request $r)
    {
        $hoy = Carbon::today('America/Lima');
        $periodo = $r->query('periodo', 'semana');
        $desde = match ($periodo) {
            'dia'   => $hoy->copy(),
            'mes'   => $hoy->copy()->subDays(29),
            default => $hoy->copy()->subDays(6),
        };
        $hasta = $hoy->copy()->endOfDay();

        return response()->json([
            'desde'      => $desde->toDateString(),
            'hasta'      => $hoy->toDateString(),
            'ventas'     => $this->ventas($desde, $hasta, $periodo),
            'produccion' => $this->produccion($desde, $hasta),
            'inventario' => $this->inventario(),
        ]);
    }

    private function ventas(Carbon $desde, Carbon $hasta, string $periodo): array
    {
        $base = fn () => DB::table('ventas as v')->whereBetween('v.vendida_en', [$desde, $hasta]);

        $porProducto = $base()->join('productos as p', 'p.id', '=', 'v.producto_id')
            ->groupBy('p.id', 'p.nombre', 'p.unidad')->orderByDesc(DB::raw('SUM(v.total)'))
            ->get(['p.nombre', 'p.unidad', DB::raw('SUM(v.cantidad) as cantidad'), DB::raw('SUM(v.total) as total')]);

        $porTipo = $base()->join('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->groupBy('c.tipo')->selectRaw('c.tipo as tipo, SUM(v.total) as total')->pluck('total', 'tipo');

        $clientes = $base()->join('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->groupBy('c.id', 'c.nombre', 'c.tipo')->orderByDesc(DB::raw('SUM(v.total)'))->limit(5)
            ->get(['c.nombre', 'c.tipo', DB::raw('SUM(v.total) as total'), DB::raw('COUNT(*) as ventas')]);

        // barras: por día en la semana, por semana en el mes
        $barras = [];
        if ($periodo === 'semana') {
            $totales = $base()->groupBy(DB::raw('DATE(v.vendida_en)'))
                ->selectRaw('DATE(v.vendida_en) as dia, SUM(v.total) as total')->pluck('total', 'dia');
            for ($d = $desde->copy(); $d <= $hasta; $d->addDay()) {
                $barras[] = ['etiqueta' => self::DIAS[$d->dayOfWeekIso], 'total' => (float) ($totales[$d->toDateString()] ?? 0)];
            }
        } elseif ($periodo === 'mes') {
            $filas = $base()->get(['v.vendida_en', 'v.total']);
            for ($i = 0, $ini = $desde->copy(); $ini <= $hasta; $i++, $ini->addDays(7)) {
                $fin = $ini->copy()->addDays(6)->endOfDay();
                $barras[] = [
                    'etiqueta' => 'S' . ($i + 1),
                    'total' => (float) $filas->filter(fn ($v) => Carbon::parse($v->vendida_en)->between($ini, $fin))->sum('total'),
                ];
            }
        }

        $ultimas = $base()
            ->join('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->join('productos as p', 'p.id', '=', 'v.producto_id')
            ->leftJoin('users as u', 'u.id', '=', 'v.registrada_por')
            ->orderByDesc('v.vendida_en')->limit(10)
            ->get(['v.vendida_en', 'c.nombre as cliente', 'p.nombre as producto', 'v.cantidad', 'v.total', 'u.name as vendedor']);

        return [
            'total'        => round((float) $base()->sum('v.total'), 2),
            'cantidad'     => $base()->count(),
            'por_producto' => $porProducto,
            'por_tipo'     => [
                'MAYORISTA' => (float) ($porTipo['MAYORISTA'] ?? 0),
                'DIRECTO'   => (float) ($porTipo['DIRECTO'] ?? 0),
                'PLANTA'    => (float) ($porTipo['PLANTA'] ?? 0),
            ],
            'clientes'     => $clientes,
            'barras'       => $barras,
            'ultimas'      => $ultimas,
        ];
    }

    private function produccion(Carbon $desde, Carbon $hasta): array
    {
        $lotes = DB::table('lotes as l')->join('productos as p', 'p.id', '=', 'l.producto_id')
            ->whereBetween('l.iniciado_en', [$desde, $hasta]);

        $porProducto = (clone $lotes)->groupBy('p.id', 'p.nombre', 'p.unidad')->get([
            'p.nombre', 'p.unidad',
            DB::raw('COUNT(*) as lotes'),
            DB::raw('SUM(l.litros_leche) as litros'),
            DB::raw("SUM(CASE WHEN l.estado = 'TERMINADO' THEN l.obtenido ELSE 0 END) as obtenido"),
        ]);

        return [
            'lotes'            => (clone $lotes)->count(),
            'en_curso'         => (clone $lotes)->where('l.estado', '!=', 'TERMINADO')->count(),
            'fuera_referencia' => (clone $lotes)->where('l.fuera_referencia', true)->count(),
            'litros'           => (float) (clone $lotes)->sum('l.litros_leche'),
            'por_producto'     => $porProducto,
        ];
    }

    private function inventario(): array
    {
        // stock de producto terminado = lo producido (lotes terminados) − lo vendido
        // (mismo cálculo que ComprasAppController::productos)
        $productos = DB::table('productos')->orderBy('id')->get()->map(function ($p) {
            $producido = (float) DB::table('lotes')->where('producto_id', $p->id)->where('estado', 'TERMINADO')->sum('obtenido');
            $vendido = (float) DB::table('ventas')->where('producto_id', $p->id)->sum('cantidad');
            $porVencer = (float) DB::table('lotes')->where('producto_id', $p->id)->where('estado', 'TERMINADO')
                ->whereBetween('vence_el', [now('America/Lima')->toDateString(), now('America/Lima')->addDays(7)->toDateString()])
                ->count();

            return ['nombre' => $p->nombre, 'unidad' => $p->unidad, 'stock' => round($producido - $vendido, 2), 'lotes_por_vencer' => $porVencer];
        });

        $bajoMinimo = DB::table('insumos')->whereColumn('stock', '<', 'stock_minimo')->orderBy('nombre')
            ->get(['nombre', 'unidad', 'stock', 'stock_minimo']);

        $ordenes = DB::table('ordenes_compra')->where('estado', 'EMITIDA');

        return [
            'productos'          => $productos,
            'insumos_bajo_minimo' => $bajoMinimo,
            'ordenes_por_recibir' => (clone $ordenes)->count(),
            'ordenes_monto'      => round((float) (clone $ordenes)->sum('costo_estimado'), 2),
        ];
    }
}
