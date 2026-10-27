<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Formulas;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/inicio — las cifras de la pantalla de Inicio de la app según quien entra
 * (operario, compras o calidad). El admin tiene la suya: admin/inicio.
 */
class InicioController extends Controller
{
    public function inicio(Request $r)
    {
        $u = $r->user();
        $hoy = Carbon::today('America/Lima')->toDateString();

        $datos = match ($u->rol) {
            'OPERARIO' => [
                'leche_tanque' => Formulas::litrosEnTanque(), // leche aceptada que todavía no se usó
                'lotes_en_proceso' => DB::table('lotes')->where('estado', 'EN_PROCESO')->count(),
                'cerrados_hoy' => DB::table('lotes')->where('estado', '!=', 'EN_PROCESO')->whereDate('cerrado_en', $hoy)->count(),
                'insumos_bajos' => DB::table('insumos')->whereColumn('stock', '<', 'stock_minimo')->count(),
            ],
            'COMPRAS' => [
                'insumos_bajos' => DB::table('insumos')->whereColumn('stock', '<', 'stock_minimo')->count(),
                'por_llegar' => DB::table('ordenes_compra')->where('estado', 'EMITIDA')->count(),
                'vendido_hoy' => round((float) DB::table('ventas')->whereDate('vendida_en', $hoy)->sum('total'), 2),
                'notas_hoy' => DB::table('ventas')->whereDate('vendida_en', $hoy)->distinct()->count(DB::raw('COALESCE(nota_id, id)')),
            ],
            'CALIDAD' => [
                // lo mismo que la lista de Muestreo: entregas de hoy y ayer sin análisis
                'por_analizar' => DB::table('entregas')->where('ausente', false)
                    ->where('fecha', '>=', Carbon::parse($hoy)->subDay()->toDateString())
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('analisis_calidad')->whereColumn('analisis_calidad.entrega_id', 'entregas.id'))
                    ->count(),
                'analizados_hoy' => DB::table('analisis_calidad')->whereDate('created_at', $hoy)->count(),
                'rechazados_hoy' => DB::table('analisis_calidad')->whereDate('created_at', $hoy)->whereIn('veredicto', ['RECHAZADO', 'ADULTERADA'])->count(),
                'por_capacitar' => DB::table('sanciones')->whereNull('capacitado_en')->distinct()->count('proveedor_id'),
            ],
            default => [],
        };

        return response()->json(['nombre' => $u->name, 'rol' => $u->rol] + $datos);
    }
}
