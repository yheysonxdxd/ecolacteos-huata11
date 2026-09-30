<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Zona extends Model
{
    protected $guarded = ['id'];
    public function comunidades() { return $this->hasMany(Comunidad::class); }
    public function vehiculos()   { return $this->hasMany(Vehiculo::class); }
}
