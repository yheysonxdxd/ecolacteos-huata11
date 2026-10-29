<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tipos de precio que el administrador puede ampliar (Bodega, Feria, Restaurante…).
 * Los tres de siempre quedan como "fijos": no se quitan, porque el sistema los usa
 * (el proveedor de leche compra a precio de PLANTA y "Cliente sin registrar" es DIRECTO).
 * El tipo del cliente y el de cada precio dejan de ser una lista cerrada en la base.
 */
return new class extends Migration
{
    private const FIJOS = [
        ['MAYORISTA', 'Mayorista', '🏪', 'Compra para revender'],
        ['DIRECTO', 'Cliente directo', '🏠', 'Para su casa'],
        ['PLANTA', 'Proveedor de leche', '🥛', 'Productores que entregan leche'],
    ];

    public function up(): void
    {
        Schema::create('tipos_precio', function (Blueprint $t) {
            $t->string('codigo', 20)->primary(); // MAYORISTA, BODEGA…: lo que se guarda en clientes.tipo
            $t->string('nombre', 60);
            $t->string('icono', 8)->nullable();
            $t->string('ayuda', 120)->nullable();
            $t->unsignedSmallInteger('orden')->default(0);
            $t->boolean('fijo')->default(false);
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });
        foreach (self::FIJOS as $i => [$codigo, $nombre, $icono, $ayuda]) {
            DB::table('tipos_precio')->insert(['codigo' => $codigo, 'nombre' => $nombre, 'icono' => $icono, 'ayuda' => $ayuda,
                'orden' => $i + 1, 'fijo' => true, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('clientes', fn (Blueprint $t) => $t->string('tipo', 20)->change());
        Schema::table('precios_venta', fn (Blueprint $t) => $t->string('tipo_cliente', 20)->change());
    }

    public function down(): void
    {
        // lo que no sea de los tres de siempre vuelve a cliente directo
        DB::table('clientes')->whereNotIn('tipo', ['MAYORISTA', 'DIRECTO', 'PLANTA'])->update(['tipo' => 'DIRECTO']);
        DB::table('precios_venta')->whereNotIn('tipo_cliente', ['MAYORISTA', 'DIRECTO', 'PLANTA'])->delete();
        Schema::table('clientes', fn (Blueprint $t) => $t->enum('tipo', ['MAYORISTA', 'DIRECTO', 'PLANTA'])->change());
        Schema::table('precios_venta', fn (Blueprint $t) => $t->enum('tipo_cliente', ['MAYORISTA', 'DIRECTO', 'PLANTA'])->change());
        Schema::dropIfExists('tipos_precio');
    }
};
