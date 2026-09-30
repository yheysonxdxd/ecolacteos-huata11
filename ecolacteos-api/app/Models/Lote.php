<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lote extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'litros_leche'     => 'decimal:2',
        'esperado_min'     => 'decimal:2',
        'esperado_max'     => 'decimal:2',
        'obtenido'         => 'decimal:2',
        'desvio_pct'       => 'decimal:2',
        'fuera_referencia' => 'boolean',
        'vence_el'         => 'date',
        'iniciado_en'      => 'datetime',
        'cerrado_en'       => 'datetime',
    ];

    public function producto() { return $this->belongsTo(Producto::class); }
    public function operario() { return $this->belongsTo(User::class, 'operario_id'); }
    public function ventas()   { return $this->hasMany(Venta::class); }

    public function entregas()
    {
        return $this->belongsToMany(Entrega::class, 'lote_entrega')->withPivot('litros');
    }

    /** Proveedores cuya leche entró a este lote (trazabilidad). */
    public function proveedores()
    {
        return Proveedor::whereIn('id', $this->entregas()->pluck('proveedor_id'))->get();
    }
}
