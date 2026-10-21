<?php

namespace App\Services;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Arma un .xlsx simple para descargar desde la web de oficina: título,
 * encabezados en negrita, una fila por registro y,
 * si se pide, una fila de totales.
 *
 * Columnas: ['Encabezado' => tipo], tipo = 'texto' | 'numero' | 'soles' | 'densidad' | 'fecha'.
 * Los números van como números (se pueden sumar en Excel), no como texto.
 */
class Excel
{
    /**
     * @param  array<string,string>  $columnas
     * @param  iterable<array>  $filas  cada fila en el mismo orden que $columnas
     * @param  array<string,float>  $totales  encabezado => total (las demás quedan vacías)
     */
    public static function descargar(string $archivo, string $titulo, array $columnas, iterable $filas, array $totales = []): BinaryFileResponse
    {
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        $tipos = array_values($columnas);

        $w = new Writer();
        $w->openToFile($ruta);
        $hoja = $w->getCurrentSheet();
        // "Pagos del 05/10 al 11/10/2026" → hoja "Pagos" (Excel no acepta / ni más de 31 letras)
        $hoja->setName(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '', preg_split('/ · | del /', $titulo)[0]), 0, 31));
        foreach ($tipos as $i => $tipo) {
            $hoja->setColumnWidth(match ($tipo) { 'numero', 'soles', 'densidad' => 13, 'fecha' => 12, default => 26 }, $i + 1);
        }

        $estilos = array_map(fn ($tipo) => match ($tipo) {
            'numero' => (new Style())->setFormat('#,##0.##'),
            'soles'  => (new Style())->setFormat('"S/ "#,##0.00'),
            'densidad' => (new Style())->setFormat('0.000'),
            'fecha'  => (new Style())->setFormat('dd/mm/yyyy'),
            default  => new Style(),
        }, $tipos);

        $w->addRow(Row::fromValues([$titulo], (new Style())->setFontBold()->setFontSize(14)));
        $w->addRow(Row::fromValues(['Ecolácteos Huata · descargado el ' . now('America/Lima')->format('d/m/Y H:i')],
            (new Style())->setFontColor(Color::rgb(110, 110, 110))));
        $w->addRow(Row::fromValues(array_keys($columnas),
            (new Style())->setFontBold()->setFontColor(Color::WHITE)->setBackgroundColor(Color::rgb(31, 92, 58))));

        foreach ($filas as $f) {
            $w->addRow(Row::fromValuesWithStyles(array_map(fn ($v, $tipo) => self::valor($v, $tipo), $f, $tipos), null, $estilos));
        }

        if ($totales) {
            $fila = array_map(fn ($c) => array_key_exists($c, $totales) ? round($totales[$c], 2) : '', array_keys($columnas));
            $fila[0] = $fila[0] === '' ? 'TOTAL' : $fila[0];
            $w->addRow(Row::fromValuesWithStyles($fila, (new Style())->setFontBold(), $estilos));
        }
        $w->close();

        return response()->download($ruta, $archivo, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    private static function valor(mixed $v, string $tipo): mixed
    {
        if ($v === null || $v === '') {
            return '';
        }

        return match ($tipo) {
            'numero', 'soles' => round((float) $v, 2),
            'densidad' => round((float) $v, 4),
            // fecha de verdad (se ordena y filtra en Excel), sin hora
            'fecha'  => \Carbon\CarbonImmutable::parse($v)->startOfDay()->toDateTimeImmutable(),
            default  => (string) $v,
        };
    }
}
