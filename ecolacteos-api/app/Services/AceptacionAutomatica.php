<?php

namespace App\Services;

use App\Models\Entrega;
use App\Models\Parametro;

/**
 * Calidad trabaja por muestreo: el analista va a lugares al azar y analiza a
 * quien elige, no a todos. Por eso toda entrega queda ACEPTADA al llegar (se
 * paga y planta puede usarla). Si calidad la analiza y sale mal,
 * CalidadService::resolver la pasa a RECHAZADA o a precio reducido.
 *
 * Solo toca entregas que calidad todavía NO analizó.
 */
class AceptacionAutomatica
{
    public static function aplicar(Entrega $e): void
    {
        if ($e->analisis()->exists()) {
            return; // ya la vio calidad: manda su veredicto
        }

        $precio = (float) Parametro::valor('precio.leche_litro', 1.80);
        $e->update([
            'estado_calidad' => 'ACEPTADO',
            'precio_litro'   => $e->ausente ? null : $precio,
            'monto'          => $e->ausente ? 0 : round((float) $e->litros * $precio, 2),
        ]);
    }
}
