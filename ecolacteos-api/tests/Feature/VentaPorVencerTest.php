<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Al vender sale primero el lote que vence primero; lo vencido no se vende. */
class VentaPorVencerTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    private int $queso;
    private int $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        $this->queso = DB::table('productos')->insertGetId([
            'nombre' => 'Queso Paria', 'unidad' => 'quesos de 1 kg', 'rendimiento_min' => 0.1, 'rendimiento_max' => 0.1, 'vida_util_dias' => 30,
        ]);
        DB::table('precios_venta')->insert([
            'producto_id' => $this->queso, 'tipo_cliente' => 'MAYORISTA', 'precio' => 20, 'vigente_desde' => now()->subMonth()->toDateString(),
        ]);
        $this->cliente = DB::table('clientes')->insertGetId(['nombre' => 'Comercial Altiplano', 'tipo' => 'MAYORISTA', 'activo' => true]);
        Sanctum::actingAs($this->usuarios['COMPRAS']);
    }

    /** Lote cerrado de [cuantos] quesos que vence en [dias] (negativo: ya venció). */
    private function lote(string $codigo, float $cuantos, int $dias, int $hechoHace): int
    {
        return DB::table('lotes')->insertGetId([
            'uuid' => (string) Str::uuid(), 'codigo' => $codigo, 'producto_id' => $this->queso, 'litros_leche' => 100,
            'esperado_min' => $cuantos, 'esperado_max' => $cuantos, 'obtenido' => $cuantos, 'estado' => 'TERMINADO',
            'vence_el' => now('America/Lima')->addDays($dias)->toDateString(), 'operario_id' => $this->usuarios['ADMIN']->id,
            'iniciado_en' => now()->subDays($hechoHace), 'cerrado_en' => now()->subDays($hechoHace),
        ]);
    }

    private function vender(float $cantidad)
    {
        return $this->postJson('/api/v1/compras/ventas', ['cliente_id' => $this->cliente, 'producto_id' => $this->queso, 'cantidad' => $cantidad]);
    }

    public function test_sale_primero_el_que_vence_primero_aunque_se_hizo_despues(): void
    {
        $tarde = $this->lote('L-1', 10, 20, 5);   // hecho antes, vence más tarde
        $pronto = $this->lote('L-2', 10, 3, 1);   // hecho después, vence en 3 días

        $this->vender(4)->assertCreated();
        $this->assertSame([$pronto], DB::table('ventas')->pluck('lote_id')->all());
        $this->assertNotSame($tarde, $pronto);
    }

    public function test_si_un_lote_no_alcanza_sigue_con_el_siguiente_en_la_misma_nota(): void
    {
        $pronto = $this->lote('L-2', 10, 3, 1);
        $tarde = $this->lote('L-1', 10, 20, 5);

        $r = $this->vender(13)->assertCreated()->assertJson(['total' => 260]);
        $filas = DB::table('ventas')->orderBy('id')->get();
        $this->assertSame([[$pronto, 10.0], [$tarde, 3.0]], $filas->map(fn ($f) => [(int) $f->lote_id, (float) $f->cantidad])->all());
        $this->assertSame([$filas[0]->id, $filas[0]->id], [(int) $filas[0]->nota_id, (int) $filas[1]->nota_id]);

        // la siguiente venta sigue con lo que quedó del L-1
        $this->vender(2)->assertCreated();
        $this->assertSame($tarde, (int) DB::table('ventas')->orderByDesc('id')->value('lote_id'));
        $this->assertNotNull($r->json('numero'));
    }

    public function test_lo_vencido_no_se_vende_ni_se_ofrece(): void
    {
        $this->lote('L-0', 5, -2, 40);
        $this->lote('L-1', 10, 20, 5);

        $p = collect($this->getJson('/api/v1/compras/ventas-datos')->json('productos'))->firstWhere('id', $this->queso);
        $this->assertEquals(10, $p['stock']);
        $this->assertEquals(5, $p['vencido']);
        $this->assertSame(['L-1'], array_column($p['lotes'], 'codigo'));
        $this->assertSame(20, $p['lotes'][0]['dias']);

        $this->vender(12)->assertStatus(422)
            ->assertJsonFragment(['message' => 'Solo hay 10 en stock (otros 5 ya vencieron: regístralos en Pérdidas)']);
        $this->assertSame(0, DB::table('ventas')->count());
    }

    public function test_ventas_viejas_sin_lote_se_descuentan_del_que_vence_primero(): void
    {
        $pronto = $this->lote('L-2', 10, 3, 1);
        $this->lote('L-1', 10, 20, 5);
        DB::table('ventas')->insert([
            'uuid' => (string) Str::uuid(), 'cliente_id' => $this->cliente, 'producto_id' => $this->queso, 'lote_id' => null,
            'cantidad' => 10, 'precio_unitario' => 20, 'total' => 200, 'registrada_por' => $this->usuarios['COMPRAS']->id, 'vendida_en' => now(),
        ]);

        $p = collect($this->getJson('/api/v1/compras/ventas-datos')->json('productos'))->firstWhere('id', $this->queso);
        $this->assertSame(['L-1'], array_column($p['lotes'], 'codigo')); // el L-2 ya se vendió entero
        $this->vender(1)->assertCreated();
        $this->assertNotSame($pronto, (int) DB::table('ventas')->orderByDesc('id')->value('lote_id'));
    }

    public function test_sin_stock_se_vende_si_se_pide_y_lo_cubre_el_proximo_lote(): void
    {
        $this->lote('L-1', 3, 20, 5);

        $this->postJson('/api/v1/compras/ventas', ['cliente_id' => $this->cliente, 'producto_id' => $this->queso, 'cantidad' => 5, 'sin_stock' => true])
            ->assertCreated()->assertJson(['total' => 100]);
        $this->assertSame([[3.0, true], [2.0, false]], DB::table('ventas')->orderBy('id')->get()
            ->map(fn ($v) => [(float) $v->cantidad, $v->lote_id !== null])->all());
        $this->assertEquals(-2, \App\Services\Mermas::stockProducto($this->queso));

        // al cerrar el siguiente lote, los 2 que faltaban salen de ahí
        $this->lote('L-2', 10, 30, 0);
        $p = collect($this->getJson('/api/v1/compras/ventas-datos')->json('productos'))->firstWhere('id', $this->queso);
        $this->assertEquals(8, $p['stock']);
    }

    public function test_kardex_de_un_solo_producto_o_de_toda_su_familia(): void
    {
        DB::table('productos')->where('id', $this->queso)->update(['tipo' => 'QUESO']);
        $andino = DB::table('productos')->insertGetId([
            'nombre' => 'Queso andino', 'tipo' => 'QUESO', 'unidad' => 'quesos de 1 kg', 'rendimiento_min' => 0.1, 'rendimiento_max' => 0.1, 'vida_util_dias' => 30,
        ]);
        $this->lote('L-1', 10, 20, 5);
        DB::table('lotes')->insert([
            'uuid' => (string) Str::uuid(), 'codigo' => 'L-2', 'producto_id' => $andino, 'litros_leche' => 100,
            'esperado_min' => 7, 'esperado_max' => 7, 'obtenido' => 7, 'estado' => 'TERMINADO',
            'vence_el' => now()->addDays(20)->toDateString(), 'operario_id' => $this->usuarios['ADMIN']->id,
            'iniciado_en' => now()->subDays(2), 'cerrado_en' => now()->subDays(2),
        ]);

        // todos los quesos juntos (como antes)
        $todos = $this->getJson("/api/v1/compras/kardex?tipo=producto&id={$this->queso}")->assertOk()->json();
        $this->assertSame('Queso', $todos['item']['nombre']);
        $this->assertEquals(17, $todos['totales']['saldo']['cantidad']);

        // solo el Queso Paria
        $solo = $this->getJson("/api/v1/compras/kardex?tipo=producto&id={$this->queso}&solo=1")->assertOk()->json();
        $this->assertSame('Queso Paria', $solo['item']['nombre']);
        $this->assertEquals(10, $solo['totales']['saldo']['cantidad']);
        $this->assertCount(1, $solo['filas']);
    }

    public function test_kardex_con_fechas_al_reves_avisa_en_castellano(): void
    {
        $this->lote('L-1', 10, 20, 5);
        $this->getJson("/api/v1/compras/kardex?tipo=producto&id={$this->queso}&desde=2026-10-20&hasta=2026-10-10")
            ->assertStatus(422)->assertJsonFragment(['message' => 'La fecha "hasta" no puede ser antes de la fecha "desde".']);
    }

    public function test_inventario_dice_en_que_productos_se_usa_cada_insumo(): void
    {
        DB::table('productos')->where('id', $this->queso)->update(['tipo' => 'QUESO', 'activo' => true]);
        $yogurt = DB::table('productos')->insertGetId([
            'nombre' => 'Yogurt natural', 'tipo' => 'YOGURT', 'unidad' => 'litros', 'rendimiento_min' => 0.95, 'rendimiento_max' => 0.95, 'vida_util_dias' => 21,
        ]);
        $cuajo = DB::table('insumos')->insertGetId(['nombre' => 'Cuajo de prueba', 'unidad' => 'L', 'stock' => 1, 'stock_minimo' => 0.5]);
        $cultivo = DB::table('insumos')->insertGetId(['nombre' => 'Cultivo de prueba', 'unidad' => 'kg', 'stock' => 1, 'stock_minimo' => 0.5]);
        $suelto = DB::table('insumos')->insertGetId(['nombre' => 'Insumo sin fórmula', 'unidad' => 'kg', 'stock' => 1, 'stock_minimo' => 0]);
        DB::table('recetas_insumo')->insert([
            ['producto_id' => $this->queso, 'insumo_id' => $cuajo, 'cantidad_por_100l' => 0.02],
            ['producto_id' => $this->queso, 'insumo_id' => $cultivo, 'cantidad_por_100l' => 0.005],
            ['producto_id' => $yogurt, 'insumo_id' => $cultivo, 'cantidad_por_100l' => 0.01],
        ]);

        $insumos = collect($this->getJson('/api/v1/compras/inventario')->assertOk()->json('insumos'))->keyBy('id');
        $this->assertSame(['QUESO'], $insumos[$cuajo]['usado_en']);
        $this->assertEqualsCanonicalizing(['QUESO', 'YOGURT'], $insumos[$cultivo]['usado_en']);
        $this->assertSame([], $insumos[$suelto]['usado_en']);
    }
}
