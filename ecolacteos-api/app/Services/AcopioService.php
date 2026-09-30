<?php

namespace App\Services;

use App\Models\Entrega;
use App\Models\Parametro;
use App\Models\Proveedor;
use App\Models\RecepcionPlanta;
use Illuminate\Support\Carbon;

class AcopioService
{
    /**
     * Aviso de desvío contra el promedio del proveedor. Devuelve null si no hay
     * promedio todavía (el alta de campo lo deja opcional a propósito).
     */
    public function desvioPromedio(Proveedor $p, float $litros): ?array
    {
        if (! $p->promedio_litros || $p->promedio_litros <= 0 || $litros <= 0) {
            return null;
        }
        $pct = (($litros - (float) $p->promedio_litros) / (float) $p->promedio_litros) * 100;
        $tol = (float) Parametro::valor('acopio.desvio_promedio_pct', 20);

        return abs($pct) >= $tol
            ? ['pct' => round($pct, 1), 'promedio' => (float) $p->promedio_litros]
            : null;
    }

    /** Recalcula el promedio con las últimas N entregas aceptadas. */
    public function recalcularPromedio(Proveedor $p, int $muestras = 21): void
    {
        $prom = Entrega::where('proveedor_id', $p->id)
            ->where('ausente', false)
            ->where('estado_calidad', 'ACEPTADO')
            ->latest('fecha')->limit($muestras)->avg('litros');

        if ($prom) {
            $p->update(['promedio_litros' => round($prom, 2)]);
        }
    }

    /** Resumen de cierre de ruta del día para un vehículo. */
    public function cierreRuta(int $vehiculoId, Carbon $fecha): array
    {
        $proveedores = Proveedor::where('vehiculo_id', $vehiculoId)
            ->where('estado', 'ACTIVO')->get();
        $entregas = Entrega::whereIn('proveedor_id', $proveedores->pluck('id'))
            ->whereDate('fecha', $fecha)->get();

        $litros = $entregas->where('ausente', false)->sum('litros');
        $precio = (float) Parametro::valor('precio.leche_litro', 1.80);

        return [
            'litros'        => round($litros, 2),
            'monto'         => round($litros * $precio, 2),
            'registrados'   => $entregas->count(),
            'total_ruta'    => $proveedores->count(),
            'ausentes'      => $entregas->where('ausente', true)->count(),
            'sin_registrar' => $proveedores->whereNotIn('id', $entregas->pluck('proveedor_id'))
                ->pluck('nombre')->values(),
        ];
    }

    /**
     * Conciliación campo vs. planta. Fase 1: litros_planta se digita al
     * descargar. Fase 2: lo escribe el caudalímetro de la tina de recepción.
     */
    public function conciliar(int $vehiculoId, Carbon $fecha, float $litrosPlanta, string $origen = 'MANUAL'): RecepcionPlanta
    {
        $campo = Entrega::where('vehiculo_id', $vehiculoId)
            ->whereDate('fecha', $fecha)->where('ausente', false)->sum('litros');

        $pct = $campo > 0 ? (($litrosPlanta - $campo) / $campo) * 100 : 0;
        $tol = (float) Parametro::valor('conciliacion.tolerancia_pct', 1.5);

        return RecepcionPlanta::updateOrCreate(
            ['vehiculo_id' => $vehiculoId, 'fecha' => $fecha->toDateString()],
            [
                'litros_campo'     => round($campo, 2),
                'litros_planta'    => round($litrosPlanta, 2),
                'diferencia_pct'   => round($pct, 2),
                'fuera_tolerancia' => abs($pct) > $tol,
                'origen'           => $origen,
            ]
        );
    }
}
