<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/calidad/muestreo
 * Calidad trabaja por muestreo: el analista elige a cualquier proveedor.
 * Devuelve las entregas de hoy y de ayer que todavía no tienen análisis
 * (ya están aceptadas: ver AceptacionAutomatica) y cuántas analizó hoy.
 * Mismo formato de fila que /calidad/pendientes, para reusar el diagnóstico.
 */
class CalidadMuestreoController extends Controller
{
    public function entregas()
    {
        $hoy = Carbon::today('America/Lima');

        $filas = DB::table('entregas')
            ->join('proveedores', 'proveedores.id', '=', 'entregas.proveedor_id')
            ->join('comunidades', 'comunidades.id', '=', 'proveedores.comunidad_id')
            ->join('vehiculos', 'vehiculos.id', '=', 'entregas.vehiculo_id')
            ->where('entregas.ausente', false)
            ->where('entregas.fecha', '>=', $hoy->copy()->subDay()->toDateString())
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('analisis_calidad')
                ->whereColumn('analisis_calidad.entrega_id', 'entregas.id'))
            ->orderByDesc('entregas.fecha')->orderBy('vehiculos.codigo')->orderBy('proveedores.nombre')
            ->select('entregas.id', 'entregas.fecha', 'entregas.litros', 'entregas.registrado_en',
                'proveedores.nombre as proveedor', 'proveedores.promedio_litros',
                'comunidades.nombre as comunidad', 'vehiculos.codigo as vehiculo')
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id, 'fecha' => $e->fecha, 'hora' => Carbon::parse($e->registrado_en)->format('H:i'),
                'litros' => (float) $e->litros, 'proveedor' => $e->proveedor,
                'promedio' => $e->promedio_litros ? (float) $e->promedio_litros : null,
                'comunidad' => $e->comunidad, 'vehiculo' => $e->vehiculo,
                'es_hoy' => $e->fecha === $hoy->toDateString(),
            ]);

        $analizadasHoy = DB::table('analisis_calidad')
            ->where('tomada_en', '>=', $hoy->toDateString())->count();

        return response()->json(['entregas' => $filas, 'analizadas_hoy' => $analizadasHoy]);
    }
}
