<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rechazos de Calidad (grasa, temperatura, pH, leche significada): la medida es
 * capacitar al productor. Aquí queda cuándo se le capacitó, quién y qué se le dijo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sanciones', function (Blueprint $t) {
            $t->timestamp('capacitado_en')->nullable()->after('resuelta_por');
            $t->foreignId('capacitado_por')->nullable()->after('capacitado_en')->constrained('users');
            $t->string('capacitacion_nota', 200)->nullable()->after('capacitado_por');
        });
    }

    public function down(): void
    {
        Schema::table('sanciones', function (Blueprint $t) {
            $t->dropConstrainedForeignId('capacitado_por');
            $t->dropColumn(['capacitado_en', 'capacitacion_nota']);
        });
    }
};
