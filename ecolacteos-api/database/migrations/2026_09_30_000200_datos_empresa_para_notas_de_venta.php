<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Datos de la planta que salen en la cabecera de la nota de venta.
 * Se cambian en la tabla parametros (sin tocar el código). RUC y teléfono
 * quedan vacíos hasta que la planta los indique.
 */
return new class extends Migration
{
    private const DATOS = [
        'empresa.nombre'    => ['Planta de Lácteos Ecolácteos Huata', 'Nombre en la cabecera de la nota de venta'],
        'empresa.direccion' => ['Huata, Puno', 'Dirección en la nota de venta'],
        'empresa.ruc'       => ['', 'RUC de la planta (vacío: no se muestra)'],
        'empresa.telefono'  => ['', 'Teléfono de la planta (vacío: no se muestra)'],
    ];

    public function up(): void
    {
        foreach (self::DATOS as $clave => [$valor, $descripcion]) {
            DB::table('parametros')->insertOrIgnore([
                'clave' => $clave, 'valor' => $valor, 'descripcion' => $descripcion,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('parametros')->whereIn('clave', array_keys(self::DATOS))->delete();
    }
};
