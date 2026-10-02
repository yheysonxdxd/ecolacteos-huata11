<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los productores casi no tienen internet: piden el cambio de comunidad por
 * teléfono o yendo a Huata, y lo registra el administrador. Se guarda por
 * dónde llegó el pedido y quién lo registró. (El pedido por la app sigue.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_cambio_zona', function (Blueprint $t) {
            $t->enum('canal', ['APP', 'LLAMADA', 'OFICINA'])->default('APP')->after('motivo');
            $t->foreignId('registrada_por')->nullable()->after('canal')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_cambio_zona', function (Blueprint $t) {
            $t->dropConstrainedForeignId('registrada_por');
            $t->dropColumn('canal');
        });
    }
};
