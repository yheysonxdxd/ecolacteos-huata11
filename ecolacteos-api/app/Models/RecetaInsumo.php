<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecetaInsumo extends Model
{
    protected $table = 'recetas_insumo';
    protected $guarded = ['id'];
    public function producto() { return $this->belongsTo(Producto::class); }
    public function insumo()   { return $this->belongsTo(Insumo::class); }
}
