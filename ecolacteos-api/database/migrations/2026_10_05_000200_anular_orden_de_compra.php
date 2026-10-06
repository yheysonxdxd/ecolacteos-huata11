<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Anular una orden de compra que no llegó: quién, cuándo y por qué. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes_compra', function (Blueprint $t) {
            $t->string('anulada_motivo')->nullable()->after('recibida_por');
            $t->timestamp('anulada_en')->nullable()->after('anulada_motivo');
            $t->foreignId('anulada_por')->nullable()->after('anulada_en')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_compra', function (Blueprint $t) {
            $t->dropConstrainedForeignId('anulada_por');
            $t->dropColumn(['anulada_motivo', 'anulada_en']);
        });
    }
};
