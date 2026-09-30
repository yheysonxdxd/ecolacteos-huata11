<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagoSemanal extends Model
{
    protected $table = 'pagos_semanales';
    protected $guarded = ['id'];
    protected $casts = [
        'inicio' => 'date', 'fin' => 'date', 'pagado_el' => 'date',
        'litros_aceptados' => 'decimal:2', 'monto' => 'decimal:2',
        'detalle_dias' => 'array',
    ];
    public function proveedor() { return $this->belongsTo(Proveedor::class); }
}
