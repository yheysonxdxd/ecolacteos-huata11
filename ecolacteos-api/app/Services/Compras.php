<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Compras de insumos: costos, comprobantes y la foto del comprobante. */
class Compras
{
    public const IGV = 0.18;

    public const COMPROBANTES = ['FACTURA' => 'Factura', 'BOLETA' => 'Boleta', 'RECIBO' => 'Recibo / sin comprobante'];

    /** Fotos de hasta 5 MB (la app y la web ya las achican antes de mandarlas). */
    private const FOTO_MAX = 5 * 1024 * 1024;

    /**
     * Costo unitario de cada insumo según su última compra: lo que de verdad se
     * pagó si ya llegó, si no lo estimado.
     *
     * @return Collection<int, float> insumo_id => costo por unidad
     */
    public static function costosUnitarios(): Collection
    {
        return DB::table('orden_compra_items as it')->join('ordenes_compra as o', 'o.id', '=', 'it.orden_compra_id')
            ->where('o.estado', '!=', 'ANULADA')->where('it.cantidad', '>', 0)
            ->orderBy('o.created_at')->orderBy('it.id')
            ->get(['it.insumo_id', 'it.cantidad', 'it.costo_estimado', 'it.costo_real'])
            ->mapWithKeys(fn ($i) => [$i->insumo_id => round((float) ($i->costo_real ?? $i->costo_estimado) / (float) $i->cantidad, 4)]);
    }

    /** Costo por unidad de un insumo dentro de una orden (para el kardex). */
    public static function costoEnOrden(?object $item): ?float
    {
        if (! $item || (float) $item->cantidad <= 0) return null;
        return (float) ($item->costo_real ?? $item->costo_estimado) / (float) $item->cantidad;
    }

    /** Texto del comprobante: "Factura F001-234", "Boleta B001-56", "Recibo". */
    public static function textoComprobante(?string $tipo, ?string $serie, ?string $numero): ?string
    {
        if (! $tipo) return null;
        $nombre = $tipo === 'RECIBO' ? 'Recibo' : self::COMPROBANTES[$tipo];
        $num = trim(($serie ?? '').($serie && $numero ? '-' : '').($numero ?? ''));
        return $num !== '' ? "$nombre $num" : $nombre;
    }

    /** DNI: 8 dígitos. RUC: 11 dígitos que empiezan en 10, 15, 16, 17 o 20. */
    public static function documentoValido(string $tipo, string $doc): bool
    {
        return $tipo === 'DNI'
            ? (bool) preg_match('/^\d{8}$/', $doc)
            : (bool) preg_match('/^(10|15|16|17|20)\d{9}$/', $doc);
    }

    /**
     * Guarda la foto del comprobante (base64, con o sin "data:image/...;base64,")
     * y devuelve la ruta dentro del disco local (privado).
     */
    public static function guardarFoto(object $orden, string $base64): string
    {
        $datos = base64_decode(preg_replace('/^data:image\/[a-z]+;base64,/i', '', $base64), true);
        $info = $datos ? @getimagesizefromstring($datos) : false;
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
        if (! $ext) throw ValidationException::withMessages(['foto' => 'La foto no es una imagen válida (JPG, PNG o WEBP).']);
        if (strlen($datos) > self::FOTO_MAX) throw ValidationException::withMessages(['foto' => 'La foto pesa más de 5 MB.']);

        if ($orden->foto) Storage::disk('local')->delete($orden->foto);
        $ruta = "comprobantes/{$orden->codigo}-".now()->format('YmdHis').".$ext";
        Storage::disk('local')->put($ruta, $datos);

        return $ruta;
    }
}
