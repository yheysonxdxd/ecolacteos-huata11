<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Configuración → Precios: tipos de precio nuevos (Bodega, Feria…) para cualquier producto y cliente. */
class TiposPrecioTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    private int $queso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        $this->queso = DB::table('productos')->insertGetId([
            'nombre' => 'Queso Paria', 'unidad' => 'quesos de 1 kg', 'rendimiento_min' => 0.1, 'rendimiento_max' => 0.1, 'vida_util_dias' => 30,
        ]);
        DB::table('lotes')->insert([
            'uuid' => (string) Str::uuid(), 'codigo' => 'L-1', 'producto_id' => $this->queso, 'litros_leche' => 100,
            'esperado_min' => 20, 'esperado_max' => 20, 'obtenido' => 20, 'estado' => 'TERMINADO',
            'vence_el' => now()->addDays(20)->toDateString(), 'operario_id' => $this->usuarios['ADMIN']->id,
            'iniciado_en' => now()->subDay(), 'cerrado_en' => now()->subDay(),
        ]);
        Sanctum::actingAs($this->usuarios['ADMIN']);
    }

    public function test_crear_un_precio_nuevo_ponerlo_y_venderle_a_un_cliente_de_ese_tipo(): void
    {
        $r = $this->postJson('/api/v1/admin/configuracion/tipos-precio', ['nombre' => 'Bodega del mercado', 'icono' => '🛒'])->assertCreated();
        $codigo = $r->json('codigo');
        $this->assertSame('BODEGA_DEL_MERCA', $codigo);

        // sale como una columna más en Precios
        $cfg = $this->getJson('/api/v1/admin/configuracion')->assertOk();
        $this->assertSame('Bodega del mercado', $cfg->json("tipos_cliente.$codigo"));
        $this->assertNull($cfg->json('precios_venta.0.precios.' . $codigo));

        $this->postJson('/api/v1/admin/configuracion/precio-venta', ['producto_id' => $this->queso, 'tipo_cliente' => $codigo, 'precio' => 19.5])
            ->assertOk()->assertJsonPath('cambio', true);

        // un cliente con ese precio, y la venta sale a S/ 19,50
        $cliente = $this->postJson('/api/v1/compras/clientes', ['nombre' => 'Bodega Doña Rosa', 'tipo' => $codigo])->assertCreated()->json('id');
        $this->postJson('/api/v1/compras/ventas', ['cliente_id' => $cliente, 'items' => [['producto_id' => $this->queso, 'cantidad' => 2]]])
            ->assertCreated()->assertJsonPath('total', 39);
        $this->assertContains($codigo, array_column($this->getJson('/api/v1/compras/ventas-datos')->json('tipos_precio'), 'codigo'));
    }

    public function test_no_se_repite_el_nombre_ni_se_quitan_los_de_siempre(): void
    {
        $this->postJson('/api/v1/admin/configuracion/tipos-precio', ['nombre' => 'mayorista'])->assertStatus(422);
        $this->postJson('/api/v1/admin/configuracion/tipos-precio/PLANTA', ['activo' => false])->assertStatus(422);
        $this->postJson('/api/v1/admin/configuracion/tipos-precio/MAYORISTA', ['nombre' => 'Mayorista (revende)'])->assertOk();
        $this->assertSame('Mayorista (revende)', DB::table('tipos_precio')->where('codigo', 'MAYORISTA')->value('nombre'));
    }

    public function test_quitar_uno_que_tiene_clientes_pide_cambiarlos_primero(): void
    {
        $codigo = $this->postJson('/api/v1/admin/configuracion/tipos-precio', ['nombre' => 'Feria'])->json('codigo');
        $id = $this->postJson('/api/v1/compras/clientes', ['nombre' => 'Puesto de la feria', 'tipo' => $codigo])->json('id');
        $this->postJson("/api/v1/admin/configuracion/tipos-precio/$codigo", ['activo' => false])->assertStatus(422)
            ->assertJsonPath('message', '1 cliente tiene el precio «Feria»: cámbiales el tipo primero.');

        $this->postJson("/api/v1/compras/clientes/$id", ['nombre' => 'Puesto de la feria', 'tipo' => 'DIRECTO'])->assertCreated();
        $this->postJson("/api/v1/admin/configuracion/tipos-precio/$codigo", ['activo' => false])->assertOk();
        // ya no se elige ni sale como columna
        $this->postJson('/api/v1/compras/clientes', ['nombre' => 'Otro puesto', 'tipo' => $codigo])->assertStatus(422);
        $this->assertArrayNotHasKey($codigo, $this->getJson('/api/v1/admin/configuracion')->json('tipos_cliente'));
    }

    public function test_solo_el_admin_agrega_precios(): void
    {
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $this->postJson('/api/v1/admin/configuracion/tipos-precio', ['nombre' => 'Bodega'])->assertForbidden();
    }
}
