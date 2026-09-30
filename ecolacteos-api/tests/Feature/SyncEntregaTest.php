<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** POST /sync/push con ENTREGA: idempotencia y corrección (CorreccionEntrega). */
class SyncEntregaTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    private const U1 = '11111111-0000-4000-8000-000000000001';
    private const U2 = '11111111-0000-4000-8000-000000000002';
    private const U3 = '11111111-0000-4000-8000-000000000003';

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        Sanctum::actingAs($this->usuarios['ACOPIADOR']);
    }

    private function push(array ...$ops): array
    {
        return $this->postJson('/api/v1/sync/push', ['operaciones' => $ops])
            ->assertOk()->json('resultados');
    }

    private function entrega(): object
    {
        return DB::table('entregas')->where('proveedor_id', $this->proveedorId)->sole();
    }

    public function test_entrega_nueva_se_aplica(): void
    {
        $r = $this->push($this->opEntrega(self::U1, 18.5));

        $this->assertSame('APLICADO', $r[0]['estado']);
        $this->assertEquals(18.5, $this->entrega()->litros);
        $this->assertSame(self::U1, $this->entrega()->uuid);
    }

    public function test_reenviar_el_mismo_uuid_no_duplica(): void
    {
        $this->push($this->opEntrega(self::U1, 18.5));
        $r = $this->push($this->opEntrega(self::U1, 18.5));

        $this->assertSame('DUPLICADO', $r[0]['estado']);
        $this->assertSame(1, DB::table('entregas')->count());
    }

    public function test_otro_uuid_el_mismo_dia_corrige_la_entrega(): void
    {
        $this->push($this->opEntrega(self::U1, 18.5));
        $r = $this->push($this->opEntrega(self::U2, 20));

        $this->assertSame('APLICADO', $r[0]['estado']);
        $this->assertTrue($r[0]['corregida']);
        $e = $this->entrega();
        $this->assertEquals(20, $e->litros);
        $this->assertSame(self::U2, $e->uuid);
        $this->assertSame(self::U1, $e->reemplaza_uuid);

        $c = DB::table('entrega_correcciones')->sole();
        $this->assertEquals(18.5, $c->litros_antes);
        $this->assertEquals(20, $c->litros_despues);
        $this->assertSame($this->usuarios['ACOPIADOR']->id, (int) $c->corregido_por);
    }

    public function test_reintentos_viejos_no_deshacen_la_correccion(): void
    {
        $this->push($this->opEntrega(self::U1, 18.5));
        $this->push($this->opEntrega(self::U2, 20));
        $this->push($this->opEntrega(self::U3, 0, ausente: true));

        // llegan tarde reintentos de las versiones anteriores
        $r = $this->push($this->opEntrega(self::U1, 18.5), $this->opEntrega(self::U2, 20));

        $this->assertSame(['DUPLICADO', 'DUPLICADO'], array_column($r, 'estado'));
        $e = $this->entrega();
        $this->assertEquals(0, $e->litros);
        $this->assertEquals(1, $e->ausente);
        $this->assertSame(2, DB::table('entrega_correcciones')->count());
    }

    public function test_correccion_obliga_al_productor_a_confirmar_de_nuevo(): void
    {
        $this->push($this->opEntrega(self::U1, 18.5));
        DB::table('entregas')->update(['confirmada_proveedor' => true, 'confirmada_en' => now()]);

        $this->push($this->opEntrega(self::U2, 20));

        $this->assertEquals(0, $this->entrega()->confirmada_proveedor);
        $this->assertNull($this->entrega()->confirmada_en);
    }

    public function test_no_se_corrige_lo_que_calidad_ya_analizo(): void
    {
        $this->push($this->opEntrega(self::U1, 18.5));
        DB::table('entregas')->update(['estado_calidad' => 'ACEPTADO']);

        $r = $this->push($this->opEntrega(self::U2, 30));

        $this->assertSame('ERROR', $r[0]['estado']);
        $this->assertStringContainsString('ya fue analizada', $r[0]['error']);
        $this->assertEquals(18.5, $this->entrega()->litros);
    }

    public function test_otro_dia_es_otra_entrega(): void
    {
        $ayer = now('America/Lima')->subDay()->toDateString();
        $this->push($this->opEntrega(self::U1, 18.5, fecha: $ayer));
        $r = $this->push($this->opEntrega(self::U2, 20));

        $this->assertSame('APLICADO', $r[0]['estado']);
        $this->assertArrayNotHasKey('corregida', $r[0]);
        $this->assertSame(2, DB::table('entregas')->count());
    }

    public function test_una_operacion_mala_no_frena_el_lote(): void
    {
        $mala = array_merge($this->opEntrega(self::U1, 10), ['proveedor_id' => 999999]);
        $r = $this->push($mala, $this->opEntrega(self::U2, 20));

        $this->assertSame(['ERROR', 'APLICADO'], array_column($r, 'estado'));
    }
}
