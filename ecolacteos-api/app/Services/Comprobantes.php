<?php

namespace App\Services;

use App\Models\Parametro;
use Illuminate\Support\Facades\DB;

/**
 * Nota de venta, boleta y factura: qué pide cada una y su numeración.
 *
 * Reglas de SUNAT que se aplican aquí:
 * - Serie de 4 caracteres: B… la boleta y F… la factura; número de hasta 8 cifras
 *   desde el 1 (R.S. 123-2022/SUNAT, anexo 1).
 * - La factura va a un RUC (comprador que usa el crédito fiscal).
 * - La boleta identifica al comprador (DNI o RUC y nombre) cuando pasa de S/ 700.
 * - IGV 18 % (16 % IGV + 2 % IPM). Queso y yogurt están gravados: el Apéndice I
 *   de la Ley del IGV solo exonera la leche cruda entera. Los precios de venta ya
 *   incluyen el IGV, así que se separa del total.
 *
 * Esto arma el comprobante en el sistema; todavía NO se envía a SUNAT (eso pide
 * certificado digital y un OSE o el SEE-SOL), por eso el PDF lo avisa.
 */
class Comprobantes
{
    public const TIPOS = ['NOTA', 'BOLETA', 'FACTURA'];

    /** Desde este total la boleta debe llevar DNI o RUC y nombre del comprador. */
    public const BOLETA_IDENTIFICAR_DESDE = 700.0;

    public static function titulo(string $tipo): string
    {
        return ['BOLETA' => 'BOLETA DE VENTA ELECTRÓNICA', 'FACTURA' => 'FACTURA ELECTRÓNICA'][$tipo] ?? 'NOTA DE VENTA';
    }

    public static function serie(string $tipo): string
    {
        return $tipo === 'FACTURA'
            ? Parametro::valor('comprobante.serie_factura', 'F001')
            : Parametro::valor('comprobante.serie_boleta', 'B001');
    }

    /** B001-00000012; la nota de venta sigue con NV-000123. */
    public static function numero(?string $serie, ?int $numero, int $notaId): string
    {
        return $serie && $numero
            ? $serie . '-' . str_pad((string) $numero, 8, '0', STR_PAD_LEFT)
            : 'NV-' . str_pad((string) $notaId, 6, '0', STR_PAD_LEFT);
    }

    /** "Cliente sin registrar" y "Proveedor de leche": su nombre no va en una boleta (igual que en la web). */
    public static function esGeneral(object $cliente): bool
    {
        return ! $cliente->ruc && preg_match('/sin registrar|vecino de huata|^proveedor de leche$/i', (string) $cliente->nombre);
    }

    /** El número de una nota (id de su primera fila): B001-00000012 o NV-000123. */
    public static function deNota(int $notaId): string
    {
        $n = DB::table('ventas')->where('id', $notaId)->first(['comprobante_serie', 'comprobante_numero']);

        return self::numero($n->comprobante_serie ?? null, $n->comprobante_numero ?? null, $notaId);
    }

    /** El siguiente número de la serie. Llamar dentro de la transacción de la venta. */
    public static function siguiente(string $serie): int
    {
        $ultimo = DB::table('ventas')->where('comprobante_serie', $serie)->lockForUpdate()->max('comprobante_numero');

        return (int) $ultimo + 1;
    }

    /** RUC con su dígito verificador (módulo 11) y uno de los inicios que da SUNAT. */
    public static function rucValido(?string $ruc): bool
    {
        if (! $ruc || ! preg_match('/^(10|15|16|17|20)\d{9}$/', $ruc)) return false;
        $factores = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        foreach ($factores as $i => $f) $suma += (int) $ruc[$i] * $f;
        $digito = 11 - ($suma % 11);
        if ($digito >= 10) $digito -= 10;

        return $digito === (int) $ruc[10];
    }

    /** Separa el IGV de un total que ya lo incluye: [op. gravada, IGV, % IGV]. */
    public static function desglose(float $total): array
    {
        $pct = (float) Parametro::valor('impuesto.igv_pct', 18);
        $gravada = round($total / (1 + $pct / 100), 2);

        return [$gravada, round($total - $gravada, 2), $pct];
    }

    /**
     * Revisa lo que pide el comprobante y devuelve el error para la persona, o null.
     * [doc] y [nombre] son del comprador (ya tomados del cliente si no se escribieron).
     */
    public static function error(string $tipo, float $total, ?string $doc, ?string $nombre): ?string
    {
        if ($tipo === 'NOTA') return null;
        if (! self::rucValido(Parametro::valor('empresa.ruc', ''))) {
            return 'Para dar boletas o facturas falta el RUC de la planta: ponlo en Configuración → Empresa.';
        }
        if ($tipo === 'FACTURA') {
            if (! $doc || strlen($doc) !== 11) return 'La factura va a un RUC: escribe el RUC de quien compra (11 dígitos).';
            if (! self::rucValido($doc)) return 'Ese RUC no es válido: revisa los 11 dígitos.';
            if (mb_strlen(trim((string) $nombre)) < 3) return 'Escribe la razón social de quien compra.';
        }
        if ($tipo === 'BOLETA') {
            if ($doc && strlen($doc) === 11 && ! self::rucValido($doc)) return 'Ese RUC no es válido: revisa los 11 dígitos.';
            if ($total > self::BOLETA_IDENTIFICAR_DESDE && (! $doc || mb_strlen(trim((string) $nombre)) < 3)) {
                return 'La boleta pasa de S/ 700: SUNAT pide el DNI (o RUC) y el nombre de quien compra.';
            }
        }

        return null;
    }
}
