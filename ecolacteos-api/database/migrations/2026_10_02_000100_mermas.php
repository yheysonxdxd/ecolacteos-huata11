<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pérdidas (mermas) de leche y de producto terminado: la leche que se corta o
 * se echa, el queso que se pasa, el yogurt que se malogra. Se descuentan del
 * stock y salen en el kardex. (Las de insumos ya van como ajuste del kardex.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mermas', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->enum('tipo', ['LECHE', 'PRODUCTO']);
            $t->foreignId('producto_id')->nullable()->constrained();
            $t->foreignId('lote_id')->nullable()->constrained();   // de qué lote era (opcional)
            $t->decimal('cantidad', 10, 3);                         // litros de leche / unidades del producto
            $t->string('motivo', 20);                               // CORTADA, VENCIDO, MALOGRADO…
            $t->string('detalle', 200)->nullable();
            $t->foreignId('registrado_por')->constrained('users');
            $t->dateTime('fecha');
            $t->timestamps();
            $t->index(['tipo', 'producto_id']);
        });

        // de qué entregas salió la leche perdida (como lote_entrega en los lotes)
        Schema::create('merma_entrega', function (Blueprint $t) {
            $t->id();
            $t->foreignId('merma_id')->constrained('mermas')->cascadeOnDelete();
            $t->foreignId('entrega_id')->constrained();
            $t->decimal('litros', 7, 2);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merma_entrega');
        Schema::dropIfExists('mermas');
    }
};
