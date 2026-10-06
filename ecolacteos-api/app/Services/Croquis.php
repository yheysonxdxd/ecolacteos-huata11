<?php

namespace App\Services;

use App\Http\Controllers\Api\MapaController;

/**
 * Pasa la ubicación real (GPS) de una comunidad a coordenadas del croquis de
 * la app. El marco es el mismo con el que se dibujaron el lago y las
 * carreteras de la app (CroquisHuataDatos.kt): si se cambia aquí, hay que
 * volver a generar ese archivo.
 */
class Croquis
{
    public const OESTE = -70.10;
    public const ESTE = -69.85;
    public const NORTE = -15.53;
    public const SUR = -15.72;
    public const ANCHO = 400;
    public const ALTO = 314;
    /** Margen para que un pin no quede cortado en el borde del dibujo. */
    private const MARGEN = 10;

    /** [x, y] del croquis para una latitud y longitud. */
    public static function punto(float $lat, float $lng): array
    {
        $x = ($lng - self::OESTE) / (self::ESTE - self::OESTE) * self::ANCHO;
        $y = (self::NORTE - $lat) / (self::NORTE - self::SUR) * self::ALTO;

        return [
            round(min(max($x, self::MARGEN), self::ANCHO - self::MARGEN), 1),
            round(min(max($y, self::MARGEN), self::ALTO - self::MARGEN), 1),
        ];
    }

    /** [x, y] de una comunidad: desde su GPS o, si aún no lo tiene, desde la posición aproximada. */
    public static function deComunidad(object $c): array
    {
        [$lat, $lng] = $c->latitud !== null && $c->longitud !== null
            ? [(float) $c->latitud, (float) $c->longitud]
            : MapaController::aproximar($c->croquis_x, $c->croquis_y);

        return self::punto($lat, $lng);
    }
}
