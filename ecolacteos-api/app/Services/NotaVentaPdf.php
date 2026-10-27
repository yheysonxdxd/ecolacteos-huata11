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

    /** Id de la nota a la que pertenece una fila de venta (la primera fila de la venta). */
    public static function notaDe(int $ventaId): ?int
    {
        $v = DB::table('ventas')->where('id', $ventaId)->first(['id', 'nota_id']);

        return $v ? (int) ($v->nota_id ?? $v->id) : null;
    }

    /**
     * Datos de la venta para la plantilla, o null si no existe. [id] puede ser
     * cualquier fila de la venta: la nota lleva todos sus productos.
     */
    public function datos(int $id): ?array
    {
        $nota = self::notaDe($id);
        if (! $nota) {
            return null;
        }
        $filas = DB::table('ventas as v')
            ->join('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->join('productos as p', 'p.id', '=', 'v.producto_id')
            ->leftJoin('lotes as l', 'l.id', '=', 'v.lote_id')
            ->leftJoin('users as u', 'u.id', '=', 'v.registrada_por')
            ->where(fn ($q) => $q->where('v.id', $nota)->orWhere('v.nota_id', $nota))
            ->orderBy('v.id')
            ->get(['v.*', 'c.nombre as cliente', 'c.tipo as tipo_cliente', 'c.ruc', 'p.nombre as producto',
                'p.unidad', 'l.codigo as lote', 'l.vence_el', 'u.name as vendedor']);
        $v = $filas->first();
        $total = round((float) $filas->sum('total'), 2);
        $unidad = fn ($u) => str_starts_with($u, 'quesos') ? 'Unidad (1 kg)' : ucfirst(strtok($u, ' '));

        return [
            'numero'   => self::numero($nota),
            // una línea por producto (la plantilla y el correo las recorren)
            'lineas'   => $filas->map(fn ($f) => [
                'producto' => $f->producto, 'unidad' => $unidad($f->unidad), 'cantidad' => (float) $f->cantidad,
                'precio' => (float) $f->precio_unitario, 'total' => (float) $f->total,
                'lote' => $f->lote, 'vence' => $f->vence_el ? Carbon::parse($f->vence_el)->format('d/m/Y') : null,
            ])->all(),
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
            // los del primer producto (lo que ya usaban el correo y las pruebas)
            'producto' => $v->producto,
            'unidad'   => $unidad($v->unidad),
            'cantidad' => (float) $v->cantidad,
            'precio'   => (float) $v->precio_unitario,
            'total'    => $total,
            'letras'   => NumeroALetras::soles($total),
            'lote'     => $v->lote,
            'vence'    => $v->vence_el ? Carbon::parse($v->vence_el)->format('d/m/Y') : null,
            'vendedor' => $v->vendedor,
            'pago'     => ['EFECTIVO' => 'Efectivo', 'YAPE' => 'Yape', 'TARJETA' => 'Tarjeta'][$v->metodo_pago ?? 'EFECTIVO'] ?? $v->metodo_pago,
            'operacion' => $v->pago_operacion ?? null,
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
