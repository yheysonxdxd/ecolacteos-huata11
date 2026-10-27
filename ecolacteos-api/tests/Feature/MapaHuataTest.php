<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Mapa real de Huata en la web de oficina. */
class MapaHuataTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        Sanctum::actingAs($this->usuarios['ADMIN']);
    }

    private function entrega(float $litros, bool $ausente = false, ?string $fecha = null): void
    {
        DB::table('entregas')->insert([
            'uuid' => (string) Str::uuid(), 'proveedor_id' => $this->proveedorId, 'vehiculo_id' => $this->vehiculo['C-01'],
            'fecha' => $fecha ?? now('America/Lima')->toDateString(), 'litros' => $litros, 'ausente' => $ausente,
            'registrado_por' => $this->usuarios['ACOPIADOR']->id, 'registrado_en' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_comunidades_con_avance_del_dia_y_rutas(): void
    {
        $this->entrega(18.5);
        $this->entrega(40, fecha: now('America/Lima')->subDay()->toDateString()); // otro día: no cuenta

        $r = $this->getJson('/api/v1/admin/mapa')->assertOk();
        $huatta = collect($r->json('comunidades'))->firstWhere('id', $this->comunidad['Huatta']);
        $this->assertSame(1, $huatta['proveedores']);
        $this->assertSame(1, $huatta['registrados']);
        $this->assertEquals(18.5, $huatta['litros']);
        $this->assertTrue($huatta['aproximada']);
        // al tocar el pin: quién está ahí y cuánto entregó ese día
        $this->assertCount(1, $huatta['gente']);
        $this->assertSame('Rosa Quispe Mamani', $huatta['gente'][0]['nombre']);
        $this->assertEquals(18.5, $huatta['gente'][0]['entrega']['litros']);

        $c01 = collect($r->json('vehiculos'))->firstWhere('codigo', 'C-01');
        $this->assertSame([$this->comunidad['Huatta'], $this->comunidad['Carata']], $c01['ruta']);
        $this->assertSame('Usuario ACOPIADOR', $c01['acopiador']);
    }

    public function test_no_entrego_cuenta_como_registrado_sin_litros(): void
    {
        $this->entrega(0, ausente: true);
        $huatta = collect($this->getJson('/api/v1/admin/mapa')->json('comunidades'))->firstWhere('id', $this->comunidad['Huatta']);
        $this->assertSame(1, $huatta['registrados']);
        $this->assertEquals(0, $huatta['litros']);
    }

    public function test_corregir_ubicacion_de_una_comunidad(): void
    {
        $id = $this->comunidad['Carata'];
        $this->postJson("/api/v1/admin/comunidades/$id/ubicacion", ['latitud' => -12.05, 'longitud' => -77.04]) // Lima
            ->assertStatus(422)->assertJsonValidationErrors('latitud');
        $this->postJson("/api/v1/admin/comunidades/$id/ubicacion", ['latitud' => -15.6011, 'longitud' => -69.9902])->assertOk();

        $carata = collect($this->getJson('/api/v1/admin/mapa')->json('comunidades'))->firstWhere('id', $id);
        $this->assertFalse($carata['aproximada']);
        $this->assertEquals(-15.6011, $carata['latitud']);
        $this->assertEquals(-69.9902, $carata['longitud']);
    }

    public function test_el_croquis_de_la_app_sale_de_la_ubicacion_real(): void
    {
        $id = $this->comunidad['Carata'];
        // el pueblo de Huata: 400 × 314 de lienzo entre -70.10/-69.85 y -15.53/-15.72
        $this->postJson("/api/v1/admin/comunidades/$id/ubicacion", ['latitud' => -15.6151, 'longitud' => -69.9715])->assertOk();

        Sanctum::actingAs($this->usuarios['ACOPIADOR']);
        $carata = collect($this->getJson('/api/v1/sync/bootstrap')->assertOk()->json('comunidades'))->firstWhere('id', $id);
        $this->assertEqualsWithDelta(205.6, $carata['croquis_x'], 0.2);
        $this->assertEqualsWithDelta(140.5, $carata['croquis_y'], 0.2);

        // fuera del marco: queda en el borde, no fuera del dibujo
        $this->assertSame([10.0, 10.0], \App\Services\Croquis::punto(-15.40, -70.30));
    }

    public function test_solo_admin(): void
    {
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $this->getJson('/api/v1/admin/mapa')->assertForbidden();
        $this->postJson("/api/v1/admin/comunidades/{$this->comunidad['Kapi']}/ubicacion", ['latitud' => -15.6, 'longitud' => -69.9])
            ->assertForbidden();
    }
}
