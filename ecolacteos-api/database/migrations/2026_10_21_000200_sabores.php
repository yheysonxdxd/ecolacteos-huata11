<?php

use App\Services\Formulas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sabores y variedades (Yogurt de fresa, Queso Paria con orégano): un producto
 * que usa la fórmula de su base y le agrega lo suyo. El yogurt natural queda
 * sin saborizante y la fresa pasa a ser su primer sabor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $t) {
            $t->foreignId('base_id')->nullable()->after('id')->constrained('productos');
            $t->string('variante', 40)->nullable()->after('nombre');       // "Fresa"
            $t->string('paso_variante', 300)->nullable()->after('procedimiento'); // dónde va lo del sabor
        });
        Formulas::separarSabores();
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('base_id');
            $t->dropColumn(['variante', 'paso_variante']);
        });
    }
};
