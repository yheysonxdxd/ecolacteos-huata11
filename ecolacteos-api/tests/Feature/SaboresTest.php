<?php

namespace Tests\Feature;

use App\Services\Formulas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Sabores: el yogurt de durazno usa la fórmula del natural y solo agrega su saborizante. */
class SaboresTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    private int $natural;
    private int $azucar;
    private int $fresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        $this->natural = DB::table('productos')->insertGetId([
            'nombre' => 'Yogurt natural', 'tipo' => 'YOGURT', 'unidad' => 'litros', 'rendimiento_min' => 0.95,
            'rendimiento_max' => 0.95, 'vida_util_dias' => 21,
            'procedimiento' => json_encode(['Pasteurizar a 85 °C', 'Envasar']),
        ]);
        $this->azucar = DB::table('insumos')->insertGetId(['nombre' => 'Azúcar blanca', 'unidad' => 'kg', 'stock' => 50]);
        $this->fresa = DB::table('insumos')->insertGetId(['nombre' => 'Saborizante fresa', 'unidad' => 'L', 'stock' => 4]);
        DB::table('recetas_insumo')->insert([
            ['producto_id' => $this->natural, 'insumo_id' => $this->azucar, 'cantidad_por_100l' => 8],
            ['producto_id' => $this->natural, 'insumo_id' => $this->fresa, 'cantidad_por_100l' => 0.3], // como estaba antes
        ]);
        DB::table('precios_venta')->insert(['producto_id' => $this->natural, 'tipo_cliente' => 'DIRECTO', 'precio' => 5,
            'vigente_desde' => now()->subMonth()->toDateString()]);
        Sanctum::actingAs($this->usuarios['ADMIN']);
    }

    private function receta(int $producto): array
    {
        return Formulas::recetas([$producto])[$producto]->mapWithKeys(fn ($i) => [$i->nombre => (float) $i->cantidad_por_100l])->all();
    }

    public function test_el_natural_queda_sin_saborizante_y_la_fresa_es_su_sabor(): void
    {
        Formulas::separarSabores();

        $fresa = DB::table('productos')->where('nombre', 'Yogurt de fresa')->first();
        $this->assertSame(['Azúcar blanca' => 8.0], $this->receta($this->natural));
        $this->assertSame(['Azúcar blanca' => 8.0, 'Saborizante fresa' => 0.3], $this->receta($fresa->id));
        $this->assertSame($this->natural, (int) $fresa->base_id);
        $this->assertEquals(5, DB::table('precios_venta')->where('producto_id', $fresa->id)->value('precio')); // mismo precio
        Formulas::separarSabores(); // correrlo otra vez no duplica
        $this->assertSame(1, DB::table('productos')->where('nombre', 'Yogurt de fresa')->count());
    }

    public function test_agregar_sabor_con_insumo_nuevo_y_heredar_la_base(): void
    {
        $id = $this->postJson('/api/v1/admin/sabores', [
            'base_id' => $this->natural, 'sabor' => 'Durazno', 'nombre' => 'Yogurt de durazno',
            'insumo_nuevo' => ['nombre' => 'Saborizante durazno', 'unidad' => 'L'], 'por_100l' => 0.3,
        ])->assertOk()->json('id');

        $receta = $this->receta($id);
        $this->assertEquals(8, $receta['Azúcar blanca']);               // de la base
        $this->assertEquals(0.3, $receta['Saborizante durazno']);       // lo suyo
        $p = collect($this->getJson('/api/v1/compras/formulas')->json('productos'))->firstWhere('id', $id);
        $this->assertSame('Yogurt natural', $p['base']);
        $this->assertSame('Envasar', $p['procedimiento'][1]);
        $this->assertStringContainsString('durazno', end($p['procedimiento']));
        $this->assertEquals(5, DB::table('precios_venta')->where('producto_id', $id)->value('precio')); // precios de la base

        // si cambia la base, cambia el sabor
        DB::table('recetas_insumo')->where('producto_id', $this->natural)->where('insumo_id', $this->azucar)->update(['cantidad_por_100l' => 6]);
        $this->assertEquals(6, $this->receta($id)['Azúcar blanca']);
    }

    public function test_producir_un_sabor_descuenta_la_base_y_el_saborizante(): void
    {
        Formulas::separarSabores();
        $fresa = DB::table('productos')->where('nombre', 'Yogurt de fresa')->value('id');
        $this->aceptarLeche(200);
        Sanctum::actingAs($this->usuarios['OPERARIO']);

        $this->postJson('/api/v1/planta/lotes', ['producto_id' => $fresa, 'litros' => 100])->assertSuccessful();

        $this->assertEquals(42, (float) DB::table('insumos')->find($this->azucar)->stock);  // 50 − 8
        $this->assertEquals(3.7, (float) DB::table('insumos')->find($this->fresa)->stock);  // 4 − 0,3
    }

    public function test_un_sabor_no_se_edita_con_la_formula_completa(): void
    {
        Formulas::separarSabores();
        $fresa = DB::table('productos')->where('nombre', 'Yogurt de fresa')->value('id');
        $this->postJson("/api/v1/admin/formulas/$fresa", ['insumos' => [], 'procedimiento' => []])->assertStatus(422);
        $this->postJson('/api/v1/admin/sabores', ['base_id' => $fresa, 'sabor' => 'X', 'nombre' => 'Yogurt X',
            'insumo_id' => $this->fresa, 'por_100l' => 1])->assertStatus(422)->assertJsonValidationErrors('base_id');
    }

    /** 200 L de leche aceptada de hoy en el tanque. */
    private function aceptarLeche(float $litros): void
    {
        DB::table('entregas')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'proveedor_id' => $this->proveedorId,
            'vehiculo_id' => DB::table('proveedores')->where('id', $this->proveedorId)->value('vehiculo_id'),
            'fecha' => now('America/Lima')->toDateString(), 'litros' => $litros, 'estado_calidad' => 'ACEPTADO',
            'registrado_por' => $this->usuarios['ACOPIADOR']->id, 'registrado_en' => now(),
        ]);
    }
}
