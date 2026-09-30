<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['rendimiento_min' => 'decimal:4', 'rendimiento_max' => 'decimal:4'];
    public function recetaInsumos() { return $this->hasMany(RecetaInsumo::class); }
    public function lotes()         { return $this->hasMany(Lote::class); }
    public function precios()       { return $this->hasMany(PrecioVenta::class); }
}
