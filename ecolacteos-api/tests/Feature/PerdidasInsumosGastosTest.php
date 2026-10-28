<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Pérdidas de insumos (con su valor en soles), gastos de planta y equipos con su mantenimiento. */
class PerdidasInsumosGastosTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    private const FOTO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private int $azucar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $this->azucar = DB::table('insumos')->insertGetId(['nombre' => 'Azúcar blanca', 'unidad' => 'kg', 'stock' => 50, 'stock_minimo' => 10]);
        // se compró a S/ 3.80 el kg: con eso se valoriza lo perdido
        $v = $this->postJson('/api/v1/compras/vendedores', ['tipo_documento' => 'DNI', 'documento' => '41234567', 'nombre' => 'Rosa Mamani'])->json('id');
        $this->postJson('/api/v1/compras/ordenes/directa', ['vendedor_id' => $v, 'comprobante_tipo' => 'RECIBO',
            'comprobante_fecha' => now('America/Lima')->toDateString(),
            'items' => [['insumo_id' => $this->azucar, 'cantidad' => 10, 'costo_real' => 38]]])->assertCreated();
    }

    public function test_un_saco_roto_baja_el_stock_sale_en_el_kardex_y_tiene_su_valor(): void
    {
        Storage::fake('local');
        $r = $this->postJson('/api/v1/compras/mermas', ['tipo' => 'INSUMO', 'insumo_id' => $this->azucar, 'cantidad' => 25,
            'motivo' => 'ROTO', 'detalle' => 'se cayó del estante', 'foto' => self::FOTO])->assertCreated();
        $this->assertEquals(35, $r->json('stock'));          // 60 − 25
        $this->assertEquals(95, $r->json('valor'));          // 25 kg × S/ 3.80
        $this->assertEquals(35, DB::table('insumos')->where('id', $this->azucar)->value('stock'));
        $m = DB::table('mermas')->find($r->json('id'));
        $this->assertSame('INSUMO', $m->tipo);
        $this->assertNotNull($m->foto);
        Storage::disk('local')->assertExists($m->foto);

        // en el kardex del insumo: una salida "Se perdió: Se rompió el saco o la bolsa · se cayó del estante"
        $k = $this->getJson('/api/v1/compras/kardex?tipo=insumo&id='.$this->azucar)->assertOk()->json();
        $this->assertStringContainsString('Se rompió el saco', json_encode($k, JSON_UNESCAPED_UNICODE));

        // en Pérdidas: la lista con su valor y lo perdido del mes
        $d = $this->getJson('/api/v1/compras/mermas')->assertOk();
        $d->assertJsonPath('mermas.0.que', 'Azúcar blanca')->assertJsonPath('mermas.0.unidad_corta', 'kg')->assertJsonPath('mermas.0.tiene_foto', true);
        $this->assertEquals(95, $d->json('mes.por_tipo.INSUMO.valor'));

        // la foto se ve con enlace firmado
        $url = $this->getJson('/api/v1/compras/fotos/perdida/'.$r->json('id'))->assertOk()->json('url');
        $this->get($url)->assertOk();

        // no se pierde más de lo que hay
        $this->postJson('/api/v1/compras/mermas', ['tipo' => 'INSUMO', 'insumo_id' => $this->azucar, 'cantidad' => 99, 'motivo' => 'MOJADO'])
            ->assertStatus(422);
    }

    public function test_avisa_cuando_una_perdida_se_repite(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->postJson('/api/v1/compras/mermas', ['tipo' => 'INSUMO', 'insumo_id' => $this->azucar, 'cantidad' => 1, 'motivo' => 'ROTO'])->assertCreated();
        }
        $rep = $this->getJson('/api/v1/compras/mermas')->json('repetidas');
        $this->assertCount(1, $rep);
        $this->assertSame('Azúcar blanca', $rep[0]['que']);
        $this->assertSame(3, $rep[0]['veces']);
    }

    public function test_gastos_de_planta_y_mantenimiento_de_equipos(): void
    {
        $tina = $this->postJson('/api/v1/compras/equipos', ['nombre' => 'Tina quesera', 'mantenimiento_dias' => 90])->assertCreated()->json('id');
        $this->postJson('/api/v1/compras/equipos', ['nombre' => 'Tina quesera'])->assertStatus(422); // no se repite

        // una reparación es de un equipo
        $hoy = now('America/Lima')->toDateString();
        $this->postJson('/api/v1/compras/gastos', ['fecha' => $hoy, 'categoria' => 'REPARACION', 'monto' => 180])->assertStatus(422);
        $this->postJson('/api/v1/compras/gastos', ['fecha' => $hoy, 'categoria' => 'REPARACION', 'equipo_id' => $tina, 'monto' => 180,
            'detalle' => 'se cambió la válvula'])->assertCreated();
        $this->postJson('/api/v1/compras/gastos', ['fecha' => $hoy, 'categoria' => 'COMBUSTIBLE', 'monto' => 60])->assertCreated();
        $this->postJson('/api/v1/compras/gastos', ['fecha' => now()->addDay()->toDateString(), 'categoria' => 'OTRO', 'monto' => 5, 'detalle' => 'x'])
            ->assertStatus(422); // no se anota el futuro

        $g = $this->getJson('/api/v1/compras/gastos?mes=actual')->assertOk();
        $this->assertEquals(240, $g->json('periodo.gastado'));
        $this->assertSame('REPARACION', $g->json('periodo.por_categoria.0.categoria'));
        $e = collect($g->json('equipos'))->firstWhere('id', $tina);
        $this->assertEquals(180, $e['gastado_anio']);
        $this->assertSame(1, $e['reparaciones_anio']);
        $this->assertSame(90, $e['mantenimiento']['dias']);        // la reparación de hoy cuenta: le toca en 90 días
        $this->assertFalse($e['avisar']);

        // los carros ya vienen como equipos
        $this->assertTrue(collect($g->json('equipos'))->contains('tipo', 'VEHICULO'));

        // en Costos: los gastos y las pérdidas entran al costo por litro
        Sanctum::actingAs($this->usuarios['ADMIN']);
        $c = $this->getJson('/api/v1/admin/costos')->assertOk();
        $this->assertEquals(240, $c->json('gastos_planta'));
        $this->assertArrayHasKey('costo_perdidas_litro', $c->json());
    }

    public function test_avisa_el_mantenimiento_que_ya_toca_y_solo_el_admin_borra_gastos_ajenos(): void
    {
        $prensa = $this->postJson('/api/v1/compras/equipos', ['nombre' => 'Prensa', 'mantenimiento_dias' => 30])->json('id');
        DB::table('equipos')->where('id', $prensa)->update(['created_at' => now()->subDays(40)]); // nunca se le hizo nada en 40 días
        $e = collect($this->getJson('/api/v1/compras/gastos')->json('equipos'))->firstWhere('id', $prensa);
        $this->assertTrue($e['avisar']);
        $this->assertSame(-10, $e['mantenimiento']['dias']);

        $id = DB::table('gastos_planta')->insertGetId(['uuid' => (string) Str::uuid(), 'fecha' => now()->subDays(3)->toDateString(),
            'categoria' => 'SERVICIOS', 'monto' => 120, 'registrado_por' => $this->usuarios['ADMIN']->id, 'created_at' => now()->subDays(3), 'updated_at' => now()]);
        $this->postJson("/api/v1/compras/gastos/$id/borrar")->assertForbidden();
        Sanctum::actingAs($this->usuarios['ADMIN']);
        $this->postJson("/api/v1/compras/gastos/$id/borrar")->assertOk();
        $this->assertNull(DB::table('gastos_planta')->find($id));
    }
}
