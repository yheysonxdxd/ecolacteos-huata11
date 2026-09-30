<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudCambioZona extends Model
{
    protected $table = 'solicitudes_cambio_zona';
    protected $guarded = ['id'];
    protected $casts = ['solicitada_para' => 'date', 'vigente_desde' => 'date'];
    public function proveedor() { return $this->belongsTo(Proveedor::class); }
    public function origen()  { return $this->belongsTo(Comunidad::class, 'comunidad_origen_id'); }
    public function destino() { return $this->belongsTo(Comunidad::class, 'comunidad_destino_id'); }
}
