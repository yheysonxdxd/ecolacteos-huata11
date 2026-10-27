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
}
