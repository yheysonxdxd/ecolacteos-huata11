<?php

use App\Services\Mermas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. Pérdidas de insumos (el saco que se rompe, la bolsa que se moja) en la misma tabla que las de leche y productos,
 *    con su valor en soles (lo que costó lo perdido, guardado al registrarla) y una foto opcional.
 * 2. Equipos de la planta (y los carros), para anotarles sus reparaciones y su mantenimiento.
 * 3. Gastos de planta que no son insumos: reparaciones, mantenimiento, combustible, luz/agua/gas, transporte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mermas', function (Blueprint $t) {
            $t->enum('tipo', ['LECHE', 'PRODUCTO', 'INSUMO'])->change();
            $t->foreignId('insumo_id')->nullable()->after('lote_id')->constrained('insumos');
            $t->decimal('valor', 10, 2)->nullable()->after('cantidad');
            $t->string('foto')->nullable()->after('detalle');
        });
        // las pérdidas que ya había: su valor con los costos de hoy
        foreach (DB::table('mermas')->get() as $m) {
            DB::table('mermas')->where('id', $m->id)->update(['valor' => Mermas::valor($m->tipo, $m->producto_id ?? $m->insumo_id, $m->lote_id, (float) $m->cantidad, $m->fecha)]);
        }

        Schema::create('equipos', function (Blueprint $t) {
            $t->id();
            $t->string('nombre', 80)->unique();
            $t->enum('tipo', ['PLANTA', 'VEHICULO'])->default('PLANTA');
            $t->foreignId('vehiculo_id')->nullable()->constrained('vehiculos');
            $t->unsignedSmallInteger('mantenimiento_dias')->nullable(); // cada cuántos días le toca mantenimiento (vacío = no se avisa)
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });
        // los carros ya están en el sistema; los equipos de la planta los agrega la administración
        foreach (DB::table('vehiculos')->orderBy('codigo')->get() as $v) {
            DB::table('equipos')->insert(['nombre' => ($v->tipo === 'MOTOCAR' ? 'Motocar ' : 'Carro ').$v->codigo, 'tipo' => 'VEHICULO',
                'vehiculo_id' => $v->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('gastos_planta', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->date('fecha');
            $t->enum('categoria', ['REPARACION', 'MANTENIMIENTO', 'COMBUSTIBLE', 'SERVICIOS', 'TRANSPORTE', 'OTRO']);
            $t->foreignId('equipo_id')->nullable()->constrained('equipos');
            $t->decimal('monto', 10, 2);
            $t->string('detalle', 200)->nullable();
            $t->string('foto')->nullable();
            $t->foreignId('registrado_por')->constrained('users');
            $t->timestamps();
            $t->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gastos_planta');
        Schema::dropIfExists('equipos');
        Schema::table('mermas', function (Blueprint $t) {
            $t->dropConstrainedForeignId('insumo_id');
            $t->dropColumn(['valor', 'foto']);
        });
        DB::table('mermas')->where('tipo', 'INSUMO')->delete();
        Schema::table('mermas', fn (Blueprint $t) => $t->enum('tipo', ['LECHE', 'PRODUCTO'])->change());
    }
};
