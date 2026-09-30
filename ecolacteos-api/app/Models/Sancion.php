<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sancion extends Model
{
    protected $table = 'sanciones';
    protected $guarded = ['id'];
    protected $casts = ['precio_aplicado' => 'decimal:2'];
    public function proveedor() { return $this->belongsTo(Proveedor::class); }
    public function analisis()  { return $this->belongsTo(AnalisisCalidad::class, 'analisis_id'); }
}
