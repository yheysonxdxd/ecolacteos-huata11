<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Fórmulas de producción, avisos de insumos que faltan y kardex valorizado. */
class FormulasKardexTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    private int $yogurt;
    private int $azucar;
    private int $cultivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();

        $this->yogurt = DB::table('productos')->insertGetId([
            'nombre' => 'Yogurt natural', 'unidad' => 'litros',
            'rendimiento_min' => 0.95, 'rendimiento_max' => 0.95, 'vida_util_dias' => 21,
        ]);
        $this->azucar = DB::table('insumos')->insertGetId(['nombre' => 'Azúcar blanca', 'unidad' => 'kg', 'stock' => 12, 'stock_minimo' => 5]);
        $this->cultivo = DB::table('insumos')->insertGetId(['nombre' => 'Cultivo láctico', 'unidad' => 'kg', 'stock' => 1, 'stock_minimo' => 0.5]);
        DB::table('insumos')->insert(['nombre' => 'Sal industrial', 'unidad' => 'kg', 'stock' => 2, 'stock_minimo' => 15]);
        \App\Services\Formulas::cargarIniciales(); // azúcar 8 kg y cultivo 0,01 kg por 100 L (sin saborizante ni envase: no existen)
    }

    /** Leche aceptada en el tanque, lista para procesar. */
    private function tanque(float $litros): void
    {
        DB::table('entregas')->insert([
            'uuid' => (string) Str::uuid(), 'proveedor_id' => $this->proveedorId, 'vehiculo_id' => $this->vehiculo['C-01'],
            'registrado_por' => $this->usuarios['ACOPIADOR']->id, 'fecha' => now('America/Lima')->toDateString(),
            'litros' => $litros, 'ausente' => false, 'estado_calidad' => 'ACEPTADO', 'registrado_en' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_formulas_iniciales_y_cuanto_alcanza(): void
    {
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $p = collect($this->getJson('/api/v1/compras/formulas')->assertOk()->json('productos'))->firstWhere('nombre', 'Yogurt natural');

        $this->assertCount(2, $p['insumos']);
        $this->assertCount(6, $p['procedimiento']);
        $this->assertEquals(150, $p['alcanza_litros']); // 12 kg de azúcar / 8 kg por 100 L
    }

    public function test_avisa_cuando_falta_azucar_para_el_tanque(): void
    {
        $this->tanque(200); // se necesitan 16 kg de azúcar y hay 12
        Sanctum::actingAs($this->usuarios['OPERARIO']);
        $avisos = $this->getJson('/api/v1/planta/recetas')->assertOk()->json('avisos');

        $falta = collect($avisos)->firstWhere('insumo', 'Azúcar blanca');
        $this->assertSame('FALTA', $falta['nivel']);
        $this->assertSame('Yogurt natural', $falta['producto']);
        $this->assertEquals(16, $falta['necesita']);
        $this->assertStringContainsString('Falta Azúcar blanca para Yogurt natural', $falta['texto']);
        // la sal no está en ninguna fórmula pero está bajo el mínimo
        $this->assertSame('BAJO_MINIMO', collect($avisos)->firstWhere('insumo', 'Sal industrial')['nivel']);
        $this->assertNull(collect($avisos)->firstWhere('insumo', 'Cultivo láctico'));
    }

    public function test_no_deja_iniciar_el_lote_si_falta_insumo(): void
    {
        $this->tanque(200);
        Sanctum::actingAs($this->usuarios['OPERARIO']);
        $this->postJson('/api/v1/planta/lotes', ['producto_id' => $this->yogurt, 'litros' => 200])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Falta Azúcar blanca (se necesita 16 kg y hay 12). Pide a Compras que reponga.']);

        $this->postJson('/api/v1/planta/lotes', ['producto_id' => $this->yogurt, 'litros' => 100])->assertCreated();
        $this->assertEquals(4, DB::table('insumos')->where('id', $this->azucar)->value('stock'));

        // el lote en proceso trae su fórmula: lo que se le descontó y los pasos
        $lote = $this->getJson('/api/v1/planta/lotes')->assertOk()->json('lotes.0');
        $this->assertSame(['Azúcar blanca', 'Cultivo láctico'], array_column($lote['insumos'], 'nombre'));
        $this->assertEquals(8, $lote['insumos'][0]['cantidad']);
        $this->assertCount(6, $lote['procedimiento']);
    }

    public function test_admin_cambia_la_formula(): void
    {
        Sanctum::actingAs($this->usuarios['ADMIN']);
        $this->postJson("/api/v1/admin/formulas/{$this->yogurt}", [
            'insumos' => [['insumo_id' => $this->azucar, 'por_100l' => 6]],
            'procedimiento' => ['Pasteurizar a 85 °C', '  ', 'Enfriar e inocular'],
        ])->assertOk()->assertJsonPath('alcanza_litros', 200)->assertJsonPath('procedimiento', ['Pasteurizar a 85 °C', 'Enfriar e inocular']);

        $this->postJson("/api/v1/admin/formulas/{$this->yogurt}", [
            'insumos' => [['insumo_id' => $this->azucar, 'por_100l' => 6], ['insumo_id' => $this->azucar, 'por_100l' => 1]],
            'procedimiento' => [],
        ])->assertStatus(422);

        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $this->postJson("/api/v1/admin/formulas/{$this->yogurt}", ['insumos' => [], 'procedimiento' => []])->assertForbidden();
    }

    public function test_kardex_valorizado_con_promedio_ponderado(): void
    {
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        // compra 50 kg a S/ 4 el kg (antes había 12 kg sin compra registrada)
        $oc = $this->postJson('/api/v1/compras/ordenes', ['insumo_id' => $this->azucar, 'cantidad' => 50, 'proveedor_comercial' => 'Distribuidora Juliaca']);
        $oc->assertCreated();
        DB::table('ordenes_compra')->where('codigo', $oc->json('codigo'))->update(['costo_estimado' => 200]);
        $ocId = DB::table('ordenes_compra')->where('codigo', $oc->json('codigo'))->value('id');
        $this->postJson("/api/v1/compras/ordenes/$ocId/recibir")->assertOk();

        // producción: 100 L de yogurt gastan 8 kg
        $this->tanque(100);
        Sanctum::actingAs($this->usuarios['OPERARIO']);
        $this->postJson('/api/v1/planta/lotes', ['producto_id' => $this->yogurt, 'litros' => 100])->assertCreated();

        // merma
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $this->postJson('/api/v1/compras/ajustes', ['insumo_id' => $this->azucar, 'tipo' => 'SALIDA', 'cantidad' => 2, 'motivo' => 'Bolsa mojada'])
            ->assertCreated()->assertJsonPath('stock', 52);
        $this->postJson('/api/v1/compras/ajustes', ['insumo_id' => $this->azucar, 'tipo' => 'SALIDA', 'cantidad' => 100, 'motivo' => 'Error'])
            ->assertStatus(422);

        $k = $this->getJson("/api/v1/compras/kardex?insumo_id={$this->azucar}")->assertOk()->json();
        $this->assertSame(['INICIAL', 'COMPRA', 'PRODUCCION', 'AJUSTE'], array_column($k['filas'], 'tipo'));
        $this->assertEquals(12, $k['filas'][0]['entrada']['cantidad']);
        $this->assertEquals(4, $k['filas'][0]['entrada']['costo']); // sin compra previa: al costo de la primera
        $this->assertEquals(62, $k['filas'][1]['saldo']['cantidad']);
        $this->assertEquals(248, $k['filas'][1]['saldo']['total']);
        $this->assertEquals(8, $k['filas'][2]['salida']['cantidad']);
        $this->assertEquals(32, $k['filas'][2]['salida']['total']);
        $this->assertEquals(52, $k['totales']['saldo']['cantidad']); // cuadra con el stock
        $this->assertEquals(208, $k['totales']['saldo']['total']);
        $this->assertEquals(62, $k['totales']['entradas']['cantidad']);
        $this->assertEquals(10, $k['totales']['salidas']['cantidad']);

        // periodo que empieza mañana: solo el saldo anterior
        $man = now('America/Lima')->addDay()->toDateString();
        $k2 = $this->getJson("/api/v1/compras/kardex?insumo_id={$this->azucar}&desde=$man")->assertOk()->json();
        $this->assertSame(['ANTERIOR'], array_column($k2['filas'], 'tipo'));
        $this->assertEquals(52, $k2['totales']['saldo']['cantidad']);
        $this->assertEquals(0, $k2['totales']['entradas']['cantidad']);
    }

    public function test_saldo_negativo_no_infla_el_costo_promedio(): void
    {
        // stock corregido a mano a 0 y luego se gastan 10 kg antes de la primera compra
        DB::table('insumos')->where('id', $this->azucar)->update(['stock' => 0]);
        $mov = fn ($cant, $tipo, $id, $cuando) => DB::table('movimientos_insumo')->insert([
            'uuid' => (string) Str::uuid(), 'insumo_id' => $this->azucar, 'cantidad' => $cant, 'referencia' => 'x',
            'origen_type' => $tipo, 'origen_id' => $id, 'registrado_por' => $this->usuarios['COMPRAS']->id,
            'created_at' => $cuando, 'updated_at' => $cuando,
        ]);
        $oc = DB::table('ordenes_compra')->insertGetId([
            'uuid' => (string) Str::uuid(), 'codigo' => 'OC-1', 'insumo_id' => $this->azucar, 'cantidad' => 50,
            'proveedor_comercial' => 'X', 'costo_estimado' => 200, 'estado' => 'RECIBIDA',
            'emitida_por' => $this->usuarios['COMPRAS']->id, 'created_at' => now()->subDays(5),
        ]);
        $mov(-10, 'lote', 1, now()->subDays(3));
        $mov(50, 'orden_compra', $oc, now()->subDays(2));
        DB::table('insumos')->where('id', $this->azucar)->update(['stock' => 40]);

        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $k = $this->getJson("/api/v1/compras/kardex?insumo_id={$this->azucar}")->assertOk()->json();
        $this->assertEquals(-10, $k['filas'][0]['saldo']['cantidad']);
        $this->assertEquals(4, $k['totales']['saldo']['costo']);
        $this->assertEquals(160, $k['totales']['saldo']['total']);
    }

    public function test_kardex_de_producto_y_de_leche(): void
    {
        DB::table('parametros')->insert(['clave' => 'precio.leche_litro', 'valor' => '1.80']);
        DB::table('ordenes_compra')->insert([
            'uuid' => (string) Str::uuid(), 'codigo' => 'OC-1', 'insumo_id' => $this->azucar, 'cantidad' => 50,
            'proveedor_comercial' => 'X', 'costo_estimado' => 200, 'estado' => 'RECIBIDA',
            'emitida_por' => $this->usuarios['COMPRAS']->id, 'created_at' => now()->subDays(5),
        ]);
        $this->tanque(100);

        // lote de yogurt: 100 L de leche (S/ 180) + 8 kg de azúcar (S/ 32) + cultivo (sin costo) → 95 L
        Sanctum::actingAs($this->usuarios['OPERARIO']);
        $lote = $this->postJson('/api/v1/planta/lotes', ['producto_id' => $this->yogurt, 'litros' => 100])->assertCreated()->json('id');
        $this->travel(1)->hours();
        $this->postJson("/api/v1/planta/lotes/$lote/cerrar", ['obtenido' => 95])->assertOk();

        $cliente = DB::table('clientes')->insertGetId(['nombre' => 'Comercial Altiplano', 'tipo' => 'MAYORISTA']);
        $this->travel(1)->hours();
        DB::table('ventas')->insert([
            'uuid' => (string) Str::uuid(), 'cliente_id' => $cliente, 'producto_id' => $this->yogurt, 'lote_id' => $lote,
            'cantidad' => 10, 'precio_unitario' => 5, 'total' => 50, 'registrada_por' => $this->usuarios['COMPRAS']->id,
            'vendida_en' => now(),
        ]);

        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $k = $this->getJson("/api/v1/compras/kardex?tipo=producto&id={$this->yogurt}")->assertOk()->json();
        $this->assertSame(['PRODUCCION', 'VENTA'], array_column($k['filas'], 'tipo'));
        $this->assertEquals(95, $k['filas'][0]['entrada']['cantidad']);
        $this->assertEquals(212, $k['filas'][0]['entrada']['total']);
        $this->assertStringContainsString('Comercial Altiplano', $k['filas'][1]['detalle']);
        $this->assertEquals(85, $k['totales']['saldo']['cantidad']);
        $this->assertEquals(85, $k['item']['stock']);
        $this->assertEquals(round(212 / 95 * 85, 2), $k['totales']['saldo']['total']);

        $l = $this->getJson('/api/v1/compras/kardex?tipo=leche')->assertOk()->json();
        $this->assertSame(['ACOPIO', 'PRODUCCION'], array_column($l['filas'], 'tipo'));
        $this->assertEquals(180, $l['filas'][0]['entrada']['total']);
        $this->assertEquals(0, $l['totales']['saldo']['cantidad']);
    }

    public function test_kardex_solo_para_compras_y_admin(): void
    {
        Sanctum::actingAs($this->usuarios['OPERARIO']);
        $this->getJson("/api/v1/compras/kardex?insumo_id={$this->azucar}")->assertForbidden();
        Sanctum::actingAs($this->usuarios['ADMIN']);
        $this->getJson("/api/v1/compras/kardex?insumo_id={$this->azucar}")->assertOk();
    }
}
