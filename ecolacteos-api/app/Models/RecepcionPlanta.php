<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecepcionPlanta extends Model
{
    protected $table = 'recepciones_planta';
    protected $guarded = ['id'];
    protected $casts = [
        'fecha' => 'date', 'litros_campo' => 'decimal:2',
        'litros_planta' => 'decimal:2', 'diferencia_pct' => 'decimal:2',
        'fuera_tolerancia' => 'boolean',
    ];
    public function vehiculo() { return $this->belongsTo(Vehiculo::class); }
}
