<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProveedorUbicacion extends Model
{
    protected $table = 'proveedor_ubicaciones';
    protected $guarded = ['id'];
    protected $casts = ['vigente_desde' => 'date'];
    public function proveedor() { return $this->belongsTo(Proveedor::class); }
    public function comunidad() { return $this->belongsTo(Comunidad::class); }
}
