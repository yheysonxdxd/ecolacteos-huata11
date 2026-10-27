<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Solo vender": a alguien que no se registra como cliente. La venta va a un cliente genérico
 * (Vecino de Huata, Proveedor de leche: de ahí sale el precio) y aquí se guarda quién compró
 * de verdad, que es lo que sale en la nota de venta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $t) {
            $t->string('comprador_nombre', 150)->nullable()->after('cliente_id');
            $t->string('comprador_documento', 11)->nullable()->after('comprador_nombre');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $t) {
            $t->dropColumn(['comprador_nombre', 'comprador_documento']);
        });
    }
};
