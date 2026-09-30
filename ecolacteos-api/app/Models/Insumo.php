<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Insumo extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['stock' => 'decimal:3', 'stock_minimo' => 'decimal:3'];
    public function movimientos() { return $this->hasMany(MovimientoInsumo::class); }
    public function getBajoMinimoAttribute(): bool
    {
        return (float) $this->stock < (float) $this->stock_minimo;
    }
}
