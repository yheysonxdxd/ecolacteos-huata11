<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1. El yogurt se mide y se vende por LITRO (antes, balde de 4 L), a S/ 5 el litro
 *    para todos. Se convierte todo lo del yogurt: rendimiento, lotes, ventas y
 *    precios (× 4 las cantidades, ÷ 4 los precios; los totales en soles no cambian).
 * 2. Tipos de cliente: MAYORISTA = mayoristas (S/ 21 el queso), DIRECTO = vecinos
 *    de Huata (S/ 20), PLANTA = proveedores de leche (S/ 19). Los puestos de
 *    mercado pasan a mayoristas, "Venta en planta" pasa a "Proveedor de leche" y
 *    se agrega el cliente "Vecino de Huata".
 * Respaldo previo: D:\Respaldos\EcolacteosHuata\ecolacteos_huata_2026-09-29_2348.sql.zip
 */
return new class extends Migration
{
    private const LITROS_POR_BALDE = 4;

    public function up(): void
    {
        $f = self::LITROS_POR_BALDE;
        $yogurt = DB::table('productos')->where('nombre', 'Yogurt natural')->first();

        if ($yogurt && str_starts_with($yogurt->unidad, 'baldes')) {
            DB::table('productos')->where('id', $yogurt->id)->update([
                'unidad'          => 'litros',
                'rendimiento_min' => $yogurt->rendimiento_min * $f,
                'rendimiento_max' => $yogurt->rendimiento_max * $f,
                'updated_at'      => now(),
            ]);
            DB::table('lotes')->where('producto_id', $yogurt->id)->update([
                'esperado_min' => DB::raw("esperado_min * $f"),
                'esperado_max' => DB::raw("esperado_max * $f"),
                'obtenido'     => DB::raw("obtenido * $f"),
            ]);
            DB::table('ventas')->where('producto_id', $yogurt->id)->update([
                'cantidad'        => DB::raw("cantidad * $f"),
                'precio_unitario' => DB::raw("ROUND(precio_unitario / $f, 2)"),
            ]);
            // historial de precios en soles por litro; el vigente pasa a S/ 5 para todos
            DB::table('precios_venta')->where('producto_id', $yogurt->id)
                ->update(['precio' => DB::raw("ROUND(precio / $f, 2)")]);
            $hoy = now('America/Lima')->toDateString();
            foreach (['MAYORISTA', 'DIRECTO', 'PLANTA'] as $tipo) {
                DB::table('precios_venta')->where('producto_id', $yogurt->id)->where('tipo_cliente', $tipo)
                    ->whereNull('vigente_hasta')->where('vigente_desde', '<', $hoy)->update(['vigente_hasta' => $hoy]);
                DB::table('precios_venta')->where('producto_id', $yogurt->id)->where('tipo_cliente', $tipo)
                    ->where('vigente_desde', $hoy)->whereNull('vigente_hasta')->delete();
                DB::table('precios_venta')->insert([
                    'producto_id' => $yogurt->id, 'tipo_cliente' => $tipo, 'precio' => 5.00,
                    'vigente_desde' => $hoy, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // --- clientes ---
        DB::table('clientes')->where('tipo', 'DIRECTO')->where('nombre', 'like', 'Puesto Mercado%')
            ->update(['tipo' => 'MAYORISTA', 'updated_at' => now()]);
        DB::table('clientes')->where('tipo', 'PLANTA')->where('nombre', 'Venta en planta')
            ->update(['nombre' => 'Proveedor de leche', 'updated_at' => now()]);
        if (! DB::table('clientes')->where('nombre', 'Vecino de Huata')->exists()) {
            DB::table('clientes')->insert([
                'nombre' => 'Vecino de Huata', 'tipo' => 'DIRECTO', 'activo' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // para volver atrás: restaurar el respaldo del 29-set 23:48
    }
};
