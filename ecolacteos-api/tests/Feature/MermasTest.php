<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Pérdidas de leche y de producto terminado. */
class MermasTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    private int $queso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        $this->queso = DB::table('productos')->insertGetId([
            'nombre' => 'Queso Paria', 'unidad' => 'quesos de 1 kg',
            'rendimiento_min' => 0.12, 'rendimiento_max' => 0.13, 'vida_util_dias' => 45,
        ]);
    }

    private function entrega(float $litros, int $proveedor, string $fecha): int
    {
        return DB::table('entregas')->insertGetId([
            'uuid' => (string) Str::uuid(), 'proveedor_id' => $proveedor, 'vehiculo_id' => $this->vehiculo['C-01'],
            'registrado_por' => $this->usuarios['ACOPIADOR']->id, 'fecha' => $fecha, 'litros' => $litros,
            'ausente' => false, 'estado_calidad' => 'ACEPTADO', 'precio_litro' => 1.8, 'registrado_en' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function otroProveedor(): int
    {
        return DB::table('proveedores')->insertGetId([
            'uuid' => (string) Str::uuid(), 'nombre' => 'Juan Mamani Condori', 'dni' => '40112244',
            'comunidad_id' => $this->comunidad['Carata'], 'vehiculo_id' => $this->vehiculo['C-01'], 'estado' => 'ACTIVO',
        ]);
    }

    public function test_leche_cortada_baja_el_tanque_y_sale_en_el_kardex(): void
    {
        $hoy = now('America/Lima')->toDateString();
        $this->entrega(60, $this->proveedorId, now('America/Lima')->subDay()->toDateString());
        $this->entrega(40, $this->otroProveedor(), $hoy);

        Sanctum::actingAs($this->usuarios['OPERARIO']);
        $this->postJson('/api/v1/planta/mermas', ['tipo' => 'LECHE', 'cantidad' => 70, 'motivo' => 'CORTADA'])
            ->assertCreated()->assertJsonPath('stock', 30);
        $this->postJson('/api/v1/planta/mermas', ['tipo' => 'LECHE', 'cantidad' => 31, 'motivo' => 'CORTADA'])->assertStatus(422);
        $this->postJson('/api/v1/planta/mermas', ['tipo' => 'LECHE', 'cantidad' => 1, 'motivo' => 'OTRO'])
            ->assertStatus(422)->assertJsonValidationErrors('detalle');

        // la primera entrega se perdió entera y de la segunda quedan 30 L para producir
        $this->assertEquals(30, $this->getJson('/api/v1/planta/recetas')->json('tanque'));

        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $k = $this->getJson('/api/v1/compras/kardex?tipo=leche')->assertOk()->json();
        $this->assertSame('MERMA', end($k['filas'])['tipo']);
        $this->assertSame('Se perdió: se cortó o se agrió', end($k['filas'])['texto']);
        $this->assertEquals(30, $k['totales']['saldo']['cantidad']);
    }

    public function test_lote_usa_lo_que_queda_de_una_entrega_a_medias(): void
    {
        $this->entrega(100, $this->proveedorId, now('America/Lima')->toDateString());
        DB::table('recetas_insumo')->delete();

        Sanctum::actingAs($this->usuarios['OPERARIO']);
        $this->postJson('/api/v1/planta/lotes', ['producto_id' => $this->queso, 'litros' => 60])->assertCreated();
        // antes los 40 L que sobraban de la entrega desaparecían del tanque
        $this->assertEquals(40, $this->getJson('/api/v1/planta/recetas')->json('tanque'));
        $this->postJson('/api/v1/planta/lotes', ['producto_id' => $this->queso, 'litros' => 40])->assertCreated();
        $this->assertEquals(0, $this->getJson('/api/v1/planta/recetas')->json('tanque'));
    }

    public function test_queso_vencido_baja_el_stock_para_vender(): void
    {
        $lote = DB::table('lotes')->insertGetId([
            'uuid' => (string) Str::uuid(), 'codigo' => 'L-1', 'producto_id' => $this->queso,
            'litros_leche' => 100, 'esperado_min' => 12, 'esperado_max' => 13, 'obtenido' => 12,
            'estado' => 'TERMINADO', 'vence_el' => now()->toDateString(),
            'operario_id' => $this->usuarios['OPERARIO']->id, 'iniciado_en' => now()->subDays(40), 'cerrado_en' => now()->subDays(40),
        ]);

        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $this->postJson('/api/v1/compras/mermas', ['tipo' => 'PRODUCTO', 'producto_id' => $this->queso, 'lote_id' => $lote,
            'cantidad' => 3, 'motivo' => 'VENCIDO'])->assertCreated()->assertJsonPath('stock', 9);
        $this->postJson('/api/v1/compras/mermas', ['tipo' => 'PRODUCTO', 'producto_id' => $this->queso,
            'cantidad' => 10, 'motivo' => 'MALOGRADO'])->assertStatus(422);
        $this->postJson('/api/v1/compras/mermas', ['tipo' => 'PRODUCTO', 'producto_id' => $this->queso,
            'cantidad' => 1, 'motivo' => 'CORTADA'])->assertStatus(422); // motivo de leche

        $this->assertEquals(9, collect($this->getJson('/api/v1/compras/inventario')->json('productos'))->firstWhere('id', $this->queso)['stock']);
        $k = $this->getJson("/api/v1/compras/kardex?tipo=producto&id={$this->queso}")->json();
        $this->assertSame(['PRODUCCION', 'MERMA'], array_column($k['filas'], 'tipo'));
        $this->assertStringContainsString('lote L-1', $k['filas'][1]['texto']);
        $this->assertSame('MERMA', $k['resumen'][1]['tipo']);

        $m = $this->getJson('/api/v1/compras/mermas')->assertOk()->json();
        $this->assertSame('Se venció o se pasó', $m['mermas'][0]['motivo_texto']);
    }

    public function test_productor_no_registra_mermas(): void
    {
        Sanctum::actingAs($this->usuarios['PRODUCTOR']);
        $this->postJson('/api/v1/compras/mermas', ['tipo' => 'LECHE', 'cantidad' => 1, 'motivo' => 'CORTADA'])->assertForbidden();
        $this->postJson('/api/v1/planta/mermas', ['tipo' => 'LECHE', 'cantidad' => 1, 'motivo' => 'CORTADA'])->assertForbidden();
    }
}
