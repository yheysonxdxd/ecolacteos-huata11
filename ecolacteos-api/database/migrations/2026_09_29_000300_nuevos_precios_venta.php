<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Precios de venta desde el 29-set-2026:
 *   Queso Paria:    mayorista S/ 21 · localidad (mercados) S/ 20 · proveedores en planta S/ 19
 *   Yogurt natural: S/ 5 por litro → S/ 20 el balde de 4 L, para todos
 * El precio anterior no se borra: queda con vigente_hasta (las ventas pasadas
 * guardan su propio precio_unitario). Si no hay precios cargados (instalación
 * nueva) no hace nada: el DatosHuataSeeder ya trae estos valores.
 */
return new class extends Migration
{
    private const PRECIOS = [
        'Queso Paria'    => ['MAYORISTA' => 21.00, 'DIRECTO' => 20.00, 'PLANTA' => 19.00],
        'Yogurt natural' => ['MAYORISTA' => 20.00, 'DIRECTO' => 20.00, 'PLANTA' => 20.00],
    ];

    public function up(): void
    {
        $hoy = now('America/Lima')->toDateString();

        foreach (self::PRECIOS as $nombre => $porTipo) {
            $productoId = DB::table('productos')->where('nombre', $nombre)->value('id');
            if (! $productoId) {
                continue;
            }
            foreach ($porTipo as $tipo => $precio) {
                $actual = DB::table('precios_venta')->where('producto_id', $productoId)
                    ->where('tipo_cliente', $tipo)->whereNull('vigente_hasta')->first();
                if (! $actual || (float) $actual->precio === $precio) {
                    continue;
                }
                DB::table('precios_venta')->where('id', $actual->id)->update(['vigente_hasta' => $hoy, 'updated_at' => now()]);
                DB::table('precios_venta')->insert([
                    'producto_id' => $productoId, 'tipo_cliente' => $tipo, 'precio' => $precio,
                    'vigente_desde' => $hoy, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // vuelve a los precios anteriores: borra los de hoy y reabre los cerrados hoy
        $hoy = now('America/Lima')->toDateString();
        DB::table('precios_venta')->where('vigente_desde', $hoy)->whereNull('vigente_hasta')->delete();
        DB::table('precios_venta')->where('vigente_hasta', $hoy)->update(['vigente_hasta' => null]);
    }
};
