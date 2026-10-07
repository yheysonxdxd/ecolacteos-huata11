<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una venta puede llevar varios productos (queso y yogurt al mismo cliente).
 * Cada producto sigue siendo una fila de "ventas" (los reportes no cambian) y
 * las filas de la misma venta comparten nota_id: el id de la primera, que da
 * el número de la nota de venta. Las ventas de antes quedan con nota_id vacío.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $t) {
            $t->foreignId('nota_id')->nullable()->after('id')->constrained('ventas');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $t) {
            $t->dropConstrainedForeignId('nota_id');
        });
    }
};
