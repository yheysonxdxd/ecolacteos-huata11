<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comunidad extends Model
{
    protected $table = 'comunidades';
    protected $guarded = ['id'];
    public function zona()        { return $this->belongsTo(Zona::class); }
    public function proveedores() { return $this->hasMany(Proveedor::class); }
}
