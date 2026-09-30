<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Parámetros editables sin desplegar: precios, rangos de calidad, tolerancias.
 * La app los descarga en el pull y los usa para evaluar offline.
 */
class Parametro extends Model
{
    protected $table = 'parametros';
    protected $primaryKey = 'clave';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    public static function valor(string $clave, $default = null)
    {
        return Cache::rememberForever("param:$clave", function () use ($clave, $default) {
            return static::find($clave)?->valor ?? $default;
        });
    }

    protected static function booted(): void
    {
        static::saved(fn ($p) => Cache::forget("param:{$p->clave}"));
    }
}
