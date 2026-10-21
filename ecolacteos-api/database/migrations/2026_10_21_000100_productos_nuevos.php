<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Productos que la planta crea sola (otro queso, yogurt de sabores):
 * tipo para agruparlos y "activo" para dejar de producir uno sin borrar su historia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $t) {
            $t->string('tipo', 10)->default('OTRO')->after('nombre'); // QUESO | YOGURT | OTRO
            $t->boolean('activo')->default(true)->after('vida_util_dias');
        });
        DB::table('productos')->where('nombre', 'like', 'Queso%')->update(['tipo' => 'QUESO']);
        DB::table('productos')->where('nombre', 'like', 'Yogur%')->update(['tipo' => 'YOGURT']);
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $t) {
            $t->dropColumn(['tipo', 'activo']);
        });
    }
};
