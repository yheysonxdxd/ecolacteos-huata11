<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    use HasFactory;

    protected $table = 'proveedores';

    protected $guarded = ['id'];

    protected $casts = [
        'promedio_litros'          => 'decimal:2',
        'ubicacion_actualizada_en' => 'date',
    ];

    public function comunidad() { return $this->belongsTo(Comunidad::class); }
    public function vehiculo()  { return $this->belongsTo(Vehiculo::class); }
    public function entregas()  { return $this->hasMany(Entrega::class); }
    public function sanciones() { return $this->hasMany(Sancion::class); }
    public function pagos()     { return $this->hasMany(PagoSemanal::class); }

    public function ubicaciones()
    {
        return $this->hasMany(ProveedorUbicacion::class)->latest('vigente_desde');
    }

    public function scopeActivos($q) { return $q->where('estado', 'ACTIVO'); }

    public function getDeBajaAttribute(): bool
    {
        return $this->estado === 'BAJA';
    }

    /** Un proveedor recién registrado puede no tener promedio todavía. */
    public function getTienePromedioAttribute(): bool
    {
        return $this->promedio_litros !== null && (float) $this->promedio_litros > 0;
    }
}
