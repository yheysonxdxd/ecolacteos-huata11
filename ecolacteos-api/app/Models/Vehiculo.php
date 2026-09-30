<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehiculo extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['ruta_circular' => 'boolean', 'orden_ruta' => 'array'];
    public function zona()        { return $this->belongsTo(Zona::class); }
    public function acopiador()   { return $this->belongsTo(User::class, 'acopiador_id'); }
    public function proveedores() { return $this->hasMany(Proveedor::class); }
    public function entregas()    { return $this->hasMany(Entrega::class); }
    public function esMotocar(): bool { return $this->tipo === 'MOTOCAR'; }
}
