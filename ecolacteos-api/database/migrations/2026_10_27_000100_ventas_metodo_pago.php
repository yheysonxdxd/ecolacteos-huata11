<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo pagó el cliente: efectivo, Yape o tarjeta (todas las filas de una nota llevan lo mismo).
 * Con Yape o tarjeta se puede anotar el número de operación para cuadrar con el banco.
 * Las ventas de antes quedan como efectivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $t) {
            $t->string('metodo_pago', 10)->default('EFECTIVO')->after('total');
            $t->string('pago_operacion', 30)->nullable()->after('metodo_pago');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $t) {
            $t->dropColumn(['metodo_pago', 'pago_operacion']);
        });
    }
};
