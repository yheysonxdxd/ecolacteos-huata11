<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ubicación real (GPS) de cada comunidad, para el mapa de la web de oficina.
 * Mientras nadie la corrija, el mapa usa una posición aproximada sacada del
 * croquis (ver MapaController::aproximar). La app sigue usando el croquis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comunidades', function (Blueprint $t) {
            $t->decimal('latitud', 10, 7)->nullable()->after('croquis_y');
            $t->decimal('longitud', 10, 7)->nullable()->after('latitud');
        });
    }

    public function down(): void
    {
        Schema::table('comunidades', function (Blueprint $t) {
            $t->dropColumn(['latitud', 'longitud']);
        });
    }
};
