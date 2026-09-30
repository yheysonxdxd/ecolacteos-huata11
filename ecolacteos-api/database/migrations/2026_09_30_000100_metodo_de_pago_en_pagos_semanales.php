<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** El admin registra cómo pagó cada boleta (efectivo o Yape) y quién la marcó como pagada. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos_semanales', function (Blueprint $t) {
            $t->enum('metodo', ['EFECTIVO', 'YAPE'])->nullable()->after('pagado_el');
            $t->foreignId('pagado_por')->nullable()->after('metodo')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('pagos_semanales', function (Blueprint $t) {
            $t->dropConstrainedForeignId('pagado_por');
            $t->dropColumn('metodo');
        });
    }
};
