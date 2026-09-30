<?php

namespace App\Services;

use App\Models\Parametro;
use App\Support\NumeroALetras;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Arma la nota de venta en PDF (comprobante interno, NO boleta SUNAT).
 * La usan el enlace para imprimir y el envío por correo.
 */
class NotaVentaPdf
{
    public static function numero(int $ventaId): string
    {
        return 'NV-' . str_pad((string) $ventaId, 6, '0', STR_PAD_LEFT);
    }

    /** Datos de la venta para la plantilla, o null si no existe. */
    public function datos(int $id): ?array
    {
        $v = DB::table('ventas as v')
            ->join('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->join('productos as p', 'p.id', '=', 'v.producto_id')
            ->leftJoin('lotes as l', 'l.id', '=', 'v.lote_id')
            ->leftJoin('users as u', 'u.id', '=', 'v.registrada_por')
            ->where('v.id', $id)
            ->first(['v.*', 'c.nombre as cliente', 'c.tipo as tipo_cliente', 'c.ruc', 'p.nombre as producto',
                'p.unidad', 'l.codigo as lote', 'l.vence_el', 'u.name as vendedor']);
        if (! $v) {
            return null;
        }

        return [
            'numero'   => self::numero($v->id),
            'fecha'    => Carbon::parse($v->vendida_en)->format('d/m/Y H:i'),
            'empresa'  => [
                'nombre'    => Parametro::valor('empresa.nombre', 'Planta de Lácteos Ecolácteos Huata'),
                'ruc'       => Parametro::valor('empresa.ruc', ''),
                'direccion' => Parametro::valor('empresa.direccion', 'Huata, Puno'),
                'telefono'  => Parametro::valor('empresa.telefono', ''),
            ],
            'cliente'  => $v->cliente,
            'tipo'     => ['MAYORISTA' => 'Mayorista', 'DIRECTO' => 'Vecino de Huata', 'PLANTA' => 'Proveedor de leche'][$v->tipo_cliente] ?? $v->tipo_cliente,
            'ruc'      => $v->ruc,
            'producto' => $v->producto,
            'unidad'   => str_starts_with($v->unidad, 'quesos') ? 'Unidad (1 kg)' : ucfirst(strtok($v->unidad, ' ')),
            'cantidad' => (float) $v->cantidad,
            'precio'   => (float) $v->precio_unitario,
            'total'    => (float) $v->total,
            'letras'   => NumeroALetras::soles((float) $v->total),
            'lote'     => $v->lote,
            'vence'    => $v->vence_el ? Carbon::parse($v->vence_el)->format('d/m/Y') : null,
            'vendedor' => $v->vendedor,
        ];
    }

    /** El PDF listo (DomPDF) para mostrar o adjuntar. */
    public function pdf(array $datos): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('pdf.nota-venta', $datos)
            ->setPaper('a5')
            ->setOption('isFontSubsettingEnabled', true); // solo las letras usadas: ~25 KB, liviano para WhatsApp
    }
}
