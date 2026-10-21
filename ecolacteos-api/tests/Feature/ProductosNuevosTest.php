<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** La planta crea sus propios productos (otro queso, yogurt de sabores) y deja de producir uno sin borrarlo. */
class ProductosNuevosTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    private int $yogurt;
    private int $azucar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        $this->yogurt = DB::table('productos')->insertGetId([
            'nombre' => 'Yogurt natural', 'tipo' => 'YOGURT', 'unidad' => 'botellas de 1 L', 'rendimiento_min' => 0.95,
            'rendimiento_max' => 0.95, 'vida_util_dias' => 21, 'procedimiento' => json_encode(['Pasteurizar a 85 °C']),
        ]);
        $this->azucar = DB::table('insumos')->insertGetId(['nombre' => 'Azúcar blanca', 'unidad' => 'kg', 'stock' => 50]);
        DB::table('recetas_insumo')->insert(['producto_id' => $this->yogurt, 'insumo_id' => $this->azucar, 'cantidad_por_100l' => 8]);
        Sanctum::actingAs($this->usuarios['ADMIN']);
    }

    private function nuevo(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/admin/configuracion/productos', $extra + [
            'nombre' => 'Yogurt de durazno', 'tipo' => 'YOGURT', 'unidad' => 'botellas de 1 L',
            'rinde_min' => 95, 'rinde_max' => 95, 'vida_util_dias' => 21,
        ]);
    }

    public function test_crea_un_yogurt_copiando_la_formula_y_con_precios(): void
    {
        $id = $this->nuevo(['copiar_de' => $this->yogurt, 'precios' => ['MAYORISTA' => 6.5, 'DIRECTO' => 8]])->assertOk()->json('id');

        $p = DB::table('productos')->find($id);
        $this->assertSame('YOGURT', $p->tipo);
        $this->assertEquals(0.95, (float) $p->rendimiento_max);           // 95 por cada 100 L
        $this->assertSame(['Pasteurizar a 85 °C'], json_decode($p->procedimiento, true));
        $this->assertEquals(8, DB::table('recetas_insumo')->where('producto_id', $id)->value('cantidad_por_100l'));
        $this->assertEquals(8, DB::table('precios_venta')->where('producto_id', $id)->where('tipo_cliente', 'DIRECTO')->value('precio'));
        // ya se puede producir: sale en Fórmulas
        $this->assertContains('Yogurt de durazno', collect($this->getJson('/api/v1/compras/formulas')->json('productos'))->pluck('nombre'));
    }

    public function test_no_acepta_nombre_repetido_ni_rendimiento_al_reves(): void
    {
        $this->nuevo(['nombre' => 'Yogurt natural'])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->nuevo(['rinde_min' => 14, 'rinde_max' => 12])->assertStatus(422)->assertJsonValidationErrors('rinde_max');
    }

    public function test_el_que_ya_no_se_produce_no_sale_para_producir(): void
    {
        $this->postJson('/api/v1/admin/configuracion/productos', [
            'id' => $this->yogurt, 'nombre' => 'Yogurt natural', 'tipo' => 'YOGURT', 'unidad' => 'botellas de 1 L',
            'rinde_min' => 95, 'rinde_max' => 95, 'vida_util_dias' => 21, 'activo' => false,
        ])->assertOk();

        $this->assertNotContains('Yogurt natural', collect($this->getJson('/api/v1/compras/formulas')->json('productos'))->pluck('nombre'));
        $this->postJson('/api/v1/planta/lotes', ['producto_id' => $this->yogurt, 'litros' => 50])
            ->assertStatus(422)->assertJsonValidationErrors('producto_id');
        // su historia no se borra
        $this->assertDatabaseHas('productos', ['id' => $this->yogurt, 'activo' => false]);
    }

    public function test_solo_el_admin_crea_productos(): void
    {
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $this->nuevo()->assertForbidden();
    }
}
