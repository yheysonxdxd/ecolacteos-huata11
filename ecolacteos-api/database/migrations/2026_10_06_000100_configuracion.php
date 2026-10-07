<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración desde la web:
 *  - precios_leche: el precio por litro y desde qué día rige (cada entrega usa el de su fecha).
 *  - configuracion_historial: quién cambió qué, cuándo, y el valor de antes y el nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('precios_leche', function (Blueprint $t) {
            $t->id();
            $t->decimal('precio', 8, 4);
            $t->date('vigente_desde')->unique();
            $t->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('configuracion_historial', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 80);
            $t->string('valor_antes')->nullable();
            $t->string('valor_nuevo')->nullable();
            $t->date('vigente_desde')->nullable(); // solo para el precio de la leche
            $t->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index('created_at');
        });

        // el precio que ya se usaba rige desde siempre
        $actual = DB::table('parametros')->where('clave', 'precio.leche_litro')->value('valor') ?? 1.80;
        DB::table('precios_leche')->insert([
            'precio' => $actual, 'vigente_desde' => '2000-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_historial');
        Schema::dropIfExists('precios_leche');
    }
};
