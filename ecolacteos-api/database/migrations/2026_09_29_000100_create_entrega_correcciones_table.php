<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de correcciones de entregas: cuando el acopiador vuelve a
 * registrar a un proveedor el mismo día, la entrega se corrige y aquí queda
 * qué había antes y quién lo cambió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entrega_correcciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entrega_id')->constrained('entregas');
            $t->char('uuid_anterior', 36)->index();
            $t->char('uuid_nuevo', 36)->unique();
            $t->decimal('litros_antes', 8, 2);
            $t->decimal('litros_despues', 8, 2);
            $t->boolean('ausente_antes');
            $t->boolean('ausente_despues');
            $t->dateTime('registrado_en_antes')->nullable();
            $t->dateTime('registrado_en_despues')->nullable();
            $t->foreignId('corregido_por')->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entrega_correcciones');
    }
};
