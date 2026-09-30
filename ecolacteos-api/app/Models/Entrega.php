<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Entrega extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'fecha'                => 'date',
        'litros'               => 'decimal:2',
        'monto'                => 'decimal:2',
        'ausente'              => 'boolean',
        'confirmada_proveedor' => 'boolean',
        'registrado_en'        => 'datetime',
        'recibido_en'          => 'datetime',
        'confirmada_en'        => 'datetime',
    ];

    public function proveedor() { return $this->belongsTo(Proveedor::class); }
    public function vehiculo()  { return $this->belongsTo(Vehiculo::class); }
    public function analisis()  { return $this->hasMany(AnalisisCalidad::class); }

    public function lotes()
    {
        return $this->belongsToMany(Lote::class, 'lote_entrega')->withPivot('litros');
    }

    public function scopeDelDia($q, $fecha) { return $q->whereDate('fecha', $fecha); }
    public function scopeAceptadas($q) { return $q->where('estado_calidad', 'ACEPTADO'); }
}
