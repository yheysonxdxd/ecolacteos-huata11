<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalisisCalidad extends Model
{
    protected $table = 'analisis_calidad';

    protected $guarded = ['id'];

    protected $casts = [
        'grasa'        => 'decimal:2',
        'proteina'     => 'decimal:2',
        'densidad'     => 'decimal:4',
        'temperatura'  => 'decimal:2',
        'agua_anadida' => 'decimal:2',
        'ph'           => 'decimal:2',
        'significada'  => 'boolean',
        'causas'       => 'array',
        'tomada_en'    => 'datetime',
    ];

    public function entrega()  { return $this->belongsTo(Entrega::class); }
    public function analista() { return $this->belongsTo(User::class, 'analista_id'); }
    public function sancion()  { return $this->hasOne(Sancion::class, 'analisis_id'); }
}
