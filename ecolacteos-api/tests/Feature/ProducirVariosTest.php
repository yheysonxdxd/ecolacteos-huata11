<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** POST /planta/lotes/varios: queso y yogurt a la vez, con la leche y los insumos compartidos sumados. */
class ProducirVariosTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    private int $queso;
    private int $yogurt;
    private int $cultivo;
    private int $azucar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        $this->queso = DB::table('productos')->insertGetId([
            'nombre' => 'Queso Paria', 'unidad' => 'quesos de 1 kg', 'rendimiento_min' => 0.10, 'rendimiento_max' => 0.12, 'vida_util_dias' => 30,
        ]);
        $this->yogurt = DB::table('productos')->insertGetId([
            'nombre' => 'Yogurt natural', 'unidad' => 'litros', 'rendimiento_min' => 0.95, 'rendimiento_max' => 0.95, 'vida_util_dias' => 21,
        ]);
        $this->azucar = DB::table('insumos')->insertGetId(['nombre' => 'Azúcar blanca', 'unidad' => 'kg', 'stock' => 50, 'stock_minimo' => 5]);
        // el cultivo lo usan los dos: 0,005 kg (queso) + 0,01 kg (yogurt) por 100 L
        $this->cultivo = DB::table('insumos')->insertGetId(['nombre' => 'Cultivo láctico', 'unidad' => 'kg', 'stock' => 0.012, 'stock_minimo' => 0]);
        \App\Services\Formulas::cargarIniciales();
        $this->entrega(150, 2);
        $this->entrega(150, 1);
        Sanctum::actingAs($this->usuarios['OPERARIO']);
    }

    private function entrega(float $litros, int $haceDias): void
    {
        DB::table('entregas')->insert([
            'uuid' => (string) Str::uuid(), 'proveedor_id' => DB::table('proveedores')->insertGetId([
                'uuid' => (string) Str::uuid(), 'nombre' => "Proveedor $haceDias", 'dni' => "4000000$haceDias",
                'comunidad_id' => $this->comunidad['Carata'], 'vehiculo_id' => $this->vehiculo['C-01'], 'estado' => 'ACTIVO',
            ]), 'vehiculo_id' => $this->vehiculo['C-01'], 'registrado_por' => $this->usuarios['ACOPIADOR']->id,
            'fecha' => now('America/Lima')->subDays($haceDias)->toDateString(), 'litros' => $litros, 'ausente' => false,
            'estado_calidad' => 'ACEPTADO', 'registrado_en' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function pedir(float $queso, float $yogurt)
    {
        return $this->postJson('/api/v1/planta/lotes/varios', ['lotes' => [
            ['producto_id' => $this->queso, 'litros' => $queso], ['producto_id' => $this->yogurt, 'litros' => $yogurt],
        ]]);
    }

    public function test_el_insumo_compartido_se_revisa_sumado_y_no_abre_ninguno(): void
    {
        // solos sí alcanzarían (0,005 y 0,01), juntos se necesita 0,015 y hay 0,012
        $this->pedir(100, 100)->assertStatus(422)->assertJsonFragment([
            'message' => 'Falta Cultivo láctico (se necesita 0.015 kg para hacerlos juntos y hay 0.012). Pide a Compras que reponga.',
        ]);
        $this->assertSame(0, DB::table('lotes')->count());
        $this->assertEquals(50, DB::table('insumos')->where('id', $this->azucar)->value('stock'));
    }

    public function test_la_leche_se_revisa_sumada(): void
    {
        DB::table('insumos')->where('id', $this->cultivo)->update(['stock' => 1]);
        $this->pedir(200, 150)->assertStatus(422)->assertJsonFragment([
            'message' => 'Para hacerlos juntos se necesitan 350 L de leche y solo hay 300 L de leche aceptada disponible.',
        ]);
    }

    public function test_abre_los_dos_lotes_y_reparte_la_leche(): void
    {
        DB::table('insumos')->where('id', $this->cultivo)->update(['stock' => 1]);
        $r = $this->pedir(100, 120)->assertCreated()->json('lotes');

        $this->assertSame(['Queso Paria', 'Yogurt natural'], array_column($r, 'producto'));
        $this->assertSame((int) substr($r[0]['codigo'], 2) + 1, (int) substr($r[1]['codigo'], 2));

        // la leche más antigua primero: el queso toma 100 L de la 1.ª entrega; el yogurt los 50 que quedan y 70 de la 2.ª
        $this->assertEquals([100], DB::table('lote_entrega')->where('lote_id', $r[0]['id'])->pluck('litros')->map(fn ($l) => (float) $l)->all());
        $this->assertEquals([50, 70], DB::table('lote_entrega')->where('lote_id', $r[1]['id'])->orderBy('id')->pluck('litros')->map(fn ($l) => (float) $l)->all());

        $this->assertEqualsWithDelta(1 - 0.005 - 0.012, DB::table('insumos')->where('id', $this->cultivo)->value('stock'), 0.0001);
        $this->assertEqualsWithDelta(50 - 9.6, DB::table('insumos')->where('id', $this->azucar)->value('stock'), 0.0001);
        $this->assertSame(2, DB::table('lotes')->where('estado', 'EN_PROCESO')->count());
    }

    public function test_un_producto_repetido_no_se_acepta(): void
    {
        $this->postJson('/api/v1/planta/lotes/varios', ['lotes' => [
            ['producto_id' => $this->queso, 'litros' => 50], ['producto_id' => $this->queso, 'litros' => 50],
        ]])->assertStatus(422);
    }
}
