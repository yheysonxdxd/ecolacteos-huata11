<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** GET /admin/negocio: ventas, producción e inventario para el admin. */
class AdminNegocioTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    private int $queso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        Sanctum::actingAs($this->usuarios['ADMIN']);

        $this->queso = DB::table('productos')->insertGetId([
            'nombre' => 'Queso Paria', 'unidad' => 'quesos de 1 kg',
            'rendimiento_min' => 0.12, 'rendimiento_max' => 0.13, 'vida_util_dias' => 45,
        ]);
        $lote = DB::table('lotes')->insertGetId([
            'uuid' => (string) Str::uuid(), 'codigo' => 'L-1', 'producto_id' => $this->queso,
            'litros_leche' => 100, 'esperado_min' => 12, 'esperado_max' => 13, 'obtenido' => 13,
            'estado' => 'TERMINADO', 'vence_el' => now()->addDays(30)->toDateString(),
            'operario_id' => $this->usuarios['OPERARIO']->id, 'iniciado_en' => now(), 'cerrado_en' => now(),
        ]);
        $mayorista = DB::table('clientes')->insertGetId(['nombre' => 'Comercial Altiplano', 'tipo' => 'MAYORISTA']);
        $mercado = DB::table('clientes')->insertGetId(['nombre' => 'Mercado Unión', 'tipo' => 'DIRECTO']);

        $venta = fn ($cliente, $cant, $precio, $cuando) => DB::table('ventas')->insert([
            'uuid' => (string) Str::uuid(), 'cliente_id' => $cliente, 'producto_id' => $this->queso, 'lote_id' => $lote,
            'cantidad' => $cant, 'precio_unitario' => $precio, 'total' => $cant * $precio,
            'registrada_por' => $this->usuarios['COMPRAS']->id, 'vendida_en' => $cuando,
        ]);
        $venta($mayorista, 5, 19, now('America/Lima'));
        $venta($mercado, 2, 20, now('America/Lima')->subDays(3));
        $venta($mayorista, 1, 19, now('America/Lima')->subDays(20)); // solo entra en "mes"

        DB::table('insumos')->insert([
            ['nombre' => 'Cuajo', 'unidad' => 'L', 'stock' => 0.1, 'stock_minimo' => 0.5],
            ['nombre' => 'Sal', 'unidad' => 'kg', 'stock' => 50, 'stock_minimo' => 10],
        ]);
    }

    public function test_ventas_por_periodo(): void
    {
        $this->getJson('/api/v1/admin/negocio?periodo=dia')->assertOk()
            ->assertJsonPath('ventas.total', 95)->assertJsonPath('ventas.cantidad', 1);
        $this->getJson('/api/v1/admin/negocio?periodo=semana')->assertOk()
            ->assertJsonPath('ventas.total', 135)->assertJsonPath('ventas.cantidad', 2);
        $this->getJson('/api/v1/admin/negocio?periodo=mes')->assertOk()
            ->assertJsonPath('ventas.total', 154)->assertJsonPath('ventas.cantidad', 3);
    }

    public function test_ventas_por_tipo_de_cliente_y_mejores_clientes(): void
    {
        $v = $this->getJson('/api/v1/admin/negocio?periodo=mes')->json('ventas');

        $this->assertEquals(114, $v['por_tipo']['MAYORISTA']);
        $this->assertEquals(40, $v['por_tipo']['DIRECTO']);
        $this->assertSame('Comercial Altiplano', $v['clientes'][0]['nombre']);
        $this->assertCount(5, $v['barras']); // el mes se muestra por semanas
    }

    public function test_semana_tiene_una_barra_por_dia(): void
    {
        $barras = $this->getJson('/api/v1/admin/negocio?periodo=semana')->json('ventas.barras');
        $this->assertCount(7, $barras);
        $this->assertEquals(95, end($barras)['total']); // hoy
    }

    public function test_inventario_y_produccion(): void
    {
        $d = $this->getJson('/api/v1/admin/negocio?periodo=semana')->json();

        $this->assertEquals(13 - 8, $d['inventario']['productos'][0]['stock']); // producido − vendido
        $this->assertSame(['Cuajo'], array_column($d['inventario']['insumos_bajo_minimo'], 'nombre'));
        $this->assertSame(1, $d['produccion']['lotes']);
        $this->assertEquals(100, $d['produccion']['litros']);
    }

    public function test_solo_el_admin_lo_ve(): void
    {
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $this->getJson('/api/v1/admin/negocio')->assertForbidden();
    }
}
