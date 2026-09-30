<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de Calidad con el formato que usa la app ({ lista: [...] }).
 * El registro del análisis y las sanciones siguen por /sync/push (ANALISIS),
 * que usa CalidadService::resolver.
 */
class CalidadAppController extends Controller
{
    /** GET /api/v1/calidad/pendientes — entregas que esperan diagnóstico. */
    public function pendientes()
    {
        $filas = DB::table('entregas')
            ->join('proveedores', 'proveedores.id', '=', 'entregas.proveedor_id')
            ->join('comunidades', 'comunidades.id', '=', 'proveedores.comunidad_id')
            ->join('vehiculos', 'vehiculos.id', '=', 'entregas.vehiculo_id')
            ->where('entregas.ausente', false)
            ->where('entregas.estado_calidad', 'PENDIENTE')
            ->orderBy('entregas.registrado_en')
            ->select('entregas.id', 'entregas.fecha', 'entregas.litros', 'entregas.registrado_en',
                'proveedores.nombre as proveedor', 'proveedores.promedio_litros',
                'comunidades.nombre as comunidad', 'vehiculos.codigo as vehiculo')
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id, 'fecha' => $e->fecha, 'hora' => Carbon::parse($e->registrado_en)->format('H:i'),
                'litros' => (float) $e->litros, 'proveedor' => $e->proveedor,
                'promedio' => $e->promedio_litros ? (float) $e->promedio_litros : null,
                'comunidad' => $e->comunidad, 'vehiculo' => $e->vehiculo,
            ]);

        return response()->json(['entregas' => $filas]);
    }

    /** GET /api/v1/calidad/historial — últimos 60 análisis con su veredicto. */
    public function historial()
    {
        $filas = DB::table('analisis_calidad as a')
            ->join('entregas as e', 'e.id', '=', 'a.entrega_id')
            ->join('proveedores as p', 'p.id', '=', 'e.proveedor_id')
            ->join('vehiculos as v', 'v.id', '=', 'e.vehiculo_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.analista_id')
            ->whereNotNull('a.veredicto')
            ->orderByDesc('a.tomada_en')->limit(60)
            ->select('a.id', 'a.muestra', 'a.veredicto', 'a.causas', 'a.grasa', 'a.agua_anadida',
                'a.temperatura', 'a.tomada_en', 'e.fecha', 'e.litros', 'p.nombre as proveedor',
                'v.codigo as vehiculo', 'u.name as analista')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'proveedor' => $a->proveedor, 'vehiculo' => $a->vehiculo,
                'fecha' => $a->fecha, 'hora' => Carbon::parse($a->tomada_en)->format('H:i'),
                'litros' => (float) $a->litros, 'muestra' => $a->muestra, 'veredicto' => $a->veredicto,
                'causas' => json_decode($a->causas ?? '[]', true) ?: [],
                'grasa' => (float) $a->grasa, 'agua' => (float) $a->agua_anadida,
                'temperatura' => (float) $a->temperatura, 'analista' => $a->analista,
            ]);

        return response()->json(['analisis' => $filas]);
    }
}
