<?php

use App\Services\Formulas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fórmulas de producción del queso y el yogurt:
 * 1. Cada producto guarda su procedimiento (pasos con temperaturas y tiempos).
 * 2. Se cargan las fórmulas de la planta (insumos por cada 100 L de leche) en los
 *    productos que todavía no tienen ninguna. Antes solo existían en los datos de
 *    prueba: en la base real el operario veía la receta vacía y al iniciar un
 *    lote no se descontaba azúcar, cuajo, etc.
 * 3. Un insumo aparece una sola vez en cada fórmula.
 * Después las cambia el administrador desde la web de oficina (Fórmulas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $t) {
            $t->json('procedimiento')->nullable()->after('vida_util_dias');
        });

        // si un insumo se repitió en una fórmula, queda una sola fila con la suma
        $repetidos = DB::table('recetas_insumo')->select('producto_id', 'insumo_id')
            ->groupBy('producto_id', 'insumo_id')->havingRaw('COUNT(*) > 1')->get();
        foreach ($repetidos as $r) {
            $filas = DB::table('recetas_insumo')->where('producto_id', $r->producto_id)
                ->where('insumo_id', $r->insumo_id)->orderBy('id')->get();
            DB::table('recetas_insumo')->where('id', $filas[0]->id)
                ->update(['cantidad_por_100l' => $filas->sum('cantidad_por_100l')]);
            DB::table('recetas_insumo')->whereIn('id', $filas->skip(1)->pluck('id'))->delete();
        }
        Schema::table('recetas_insumo', function (Blueprint $t) {
            $t->unique(['producto_id', 'insumo_id']);
        });

        Formulas::cargarIniciales();
    }

    public function down(): void
    {
        Schema::table('recetas_insumo', function (Blueprint $t) {
            $t->dropUnique(['producto_id', 'insumo_id']);
        });
        Schema::table('productos', function (Blueprint $t) {
            $t->dropColumn('procedimiento');
        });
    }
};
