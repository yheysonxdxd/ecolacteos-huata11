<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenCompra extends Model
{
    protected $table = 'ordenes_compra';
    protected $guarded = ['id'];
    public function insumo() { return $this->belongsTo(Insumo::class); }
}
