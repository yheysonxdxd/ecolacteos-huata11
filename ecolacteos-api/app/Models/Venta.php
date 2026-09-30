<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    protected $guarded = ['id'];
    protected $casts = [
        'cantidad' => 'decimal:2', 'precio_unitario' => 'decimal:2',
        'total' => 'decimal:2', 'vendida_en' => 'datetime',
    ];
    public function cliente()  { return $this->belongsTo(Cliente::class); }
    public function producto() { return $this->belongsTo(Producto::class); }
    public function lote()     { return $this->belongsTo(Lote::class); }
}
