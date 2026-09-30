<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovimientoInsumo extends Model
{
    protected $table = 'movimientos_insumo';
    protected $guarded = ['id'];
    protected $casts = ['cantidad' => 'decimal:3'];
    public function insumo() { return $this->belongsTo(Insumo::class); }
    public function origen() { return $this->morphTo(); }
}
