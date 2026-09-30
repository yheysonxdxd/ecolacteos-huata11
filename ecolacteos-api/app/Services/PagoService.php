<?php

namespace App\Services;

use App\Models\Entrega;
use App\Models\PagoSemanal;
use App\Models\Parametro;
use App\Models\Proveedor;
use Illuminate\Support\Carbon;

class PagoService
{
    /**
     * Boleta semanal (lunes a domingo) que se entrega el viernes.
     * Solo litros ACEPTADOS; el precio es el congelado en cada entrega, así que
     * una entrega adulterada entra con su precio reducido.
     */
    public function generar(Proveedor $p, Carbon $cualquierDiaDeLaSemana): PagoSemanal
    {
        $inicio = $cualquierDiaDeLaSemana->copy()->startOfWeek(Carbon::MONDAY);
        $fin    = $inicio->copy()->endOfWeek(Carbon::SUNDAY);

        $entregas = Entrega::where('proveedor_id', $p->id)
            ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->orderBy('fecha')->get();

        $base   = (float) Parametro::valor('precio.leche_litro', 1.80);
        $detalle = [];
        $litros = 0.0;
        $monto  = 0.0;

        for ($d = $inicio->copy(); $d->lte($fin); $d->addDay()) {
            $e = $entregas->firstWhere('fecha', $d->toDateString());
            $aceptada = $e && ! $e->ausente && $e->estado_calidad === 'ACEPTADO';

            $detalle[] = [
                'fecha'  => $d->toDateString(),
                'litros' => $aceptada ? (float) $e->litros : 0,
                'monto'  => $aceptada ? (float) $e->monto : 0,
                'nota'   => ! $e ? 'sin registro'
                    : ($e->ausente ? 'no entregó'
                    : ($e->estado_calidad === 'RECHAZADO' ? 'rechazado en calidad' : null)),
            ];

            if ($aceptada) {
                $litros += (float) $e->litros;
                $monto  += (float) $e->monto;
            }
        }

        return PagoSemanal::updateOrCreate(
            ['proveedor_id' => $p->id, 'semana_iso' => $inicio->format('o-\\WW')],
            [
                'inicio'           => $inicio->toDateString(),
                'fin'              => $fin->toDateString(),
                'litros_aceptados' => round($litros, 2),
                'precio_litro'     => $base,
                'monto'            => round($monto, 2),
                'detalle_dias'     => $detalle,
            ]
        );
    }
}
