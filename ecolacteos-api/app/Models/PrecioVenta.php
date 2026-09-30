<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrecioVenta extends Model
{
    protected $table = 'precios_venta';
    protected $guarded = ['id'];
    protected $casts = ['precio' => 'decimal:2', 'vigente_desde' => 'date'];
    public function producto() { return $this->belongsTo(Producto::class); }

    public static function vigente(int $productoId, string $tipoCliente): ?float
    {
        $p = static::where('producto_id', $productoId)
            ->where('tipo_cliente', $tipoCliente)
            ->whereNull('vigente_hasta')
            ->latest('vigente_desde')->first();

        return $p ? (float) $p->precio : null;
    }
}
