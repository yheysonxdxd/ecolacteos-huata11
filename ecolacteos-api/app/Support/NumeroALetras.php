<?php

namespace App\Support;

/** 105.50 → "CIENTO CINCO CON 50/100 SOLES" (para el total de la nota de venta). */
class NumeroALetras
{
    private const UNIDADES = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
        'VEINTE', 'VEINTIUNO', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS',
        'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE'];
    private const DECENAS = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
    private const CENTENAS = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS',
        'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    public static function soles(float $monto): string
    {
        $entero = (int) floor(round($monto, 2));
        $centimos = (int) round(($monto - $entero) * 100);
        if ($centimos === 100) { $entero++; $centimos = 0; }

        $letras = $entero === 0 ? 'CERO' : self::numero($entero);

        return sprintf('%s CON %02d/100 SOLES', $letras, $centimos);
    }

    private static function numero(int $n): string
    {
        if ($n >= 1000000) {
            $millones = intdiv($n, 1000000);
            $resto = $n % 1000000;
            $txt = $millones === 1 ? 'UN MILLÓN' : self::numero($millones) . ' MILLONES';
            return trim($txt . ($resto ? ' ' . self::numero($resto) : ''));
        }
        if ($n >= 1000) {
            $miles = intdiv($n, 1000);
            $resto = $n % 1000;
            $txt = $miles === 1 ? 'MIL' : self::apocope(self::centenas($miles)) . ' MIL';
            return trim($txt . ($resto ? ' ' . self::centenas($resto) : ''));
        }

        return self::centenas($n);
    }

    private static function centenas(int $n): string
    {
        if ($n === 100) return 'CIEN';
        $c = intdiv($n, 100);
        $resto = $n % 100;

        return trim(self::CENTENAS[$c] . ' ' . self::decenas($resto));
    }

    private static function decenas(int $n): string
    {
        if ($n < 30) return self::UNIDADES[$n];
        $d = intdiv($n, 10);
        $u = $n % 10;

        return self::DECENAS[$d] . ($u ? ' Y ' . self::UNIDADES[$u] : '');
    }

    /** "VEINTIUNO MIL" → "VEINTIÚN MIL", "UNO" → "UN" delante de MIL. */
    private static function apocope(string $s): string
    {
        return preg_replace(['/VEINTIUNO$/', '/UNO$/'], ['VEINTIÚN', 'UN'], $s);
    }
}
