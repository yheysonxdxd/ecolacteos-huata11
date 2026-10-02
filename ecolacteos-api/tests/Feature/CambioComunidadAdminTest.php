<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** El productor llama o va a Huata y el administrador registra su cambio de comunidad. */
class CambioComunidadAdminTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
    }

    public function test_admin_registra_el_cambio_por_llamada_y_queda_aplicado(): void
    {
        Sanctum::actingAs($this->usuarios['ADMIN']);
        $this->postJson("/api/v1/admin/proveedores/{$this->proveedorId}/cambiar-comunidad", [
            'comunidad_id' => $this->comunidad['Kapi'], 'canal' => 'LLAMADA', 'motivo' => 'Se mudó con su hija',
        ])->assertCreated()->assertJsonPath('vehiculo', 'M-01')->assertJsonPath('comunidad', 'Kapi');

        $p = DB::table('proveedores')->find($this->proveedorId);
        $this->assertEquals($this->comunidad['Kapi'], $p->comunidad_id);
        $this->assertEquals($this->vehiculo['M-01'], $p->vehiculo_id);
        $this->assertEquals(1, DB::table('proveedor_ubicaciones')->where('proveedor_id', $this->proveedorId)->count());

        $s = $this->getJson('/api/v1/admin/solicitudes')->assertOk()->json();
        $this->assertSame('LLAMADA', $s['solicitudes'][0]['canal']);
        $this->assertSame('APROBADA', $s['solicitudes'][0]['estado']);
        $this->assertSame('Usuario ADMIN', $s['solicitudes'][0]['registrada_por']);
        $this->assertSame('M-01', collect($s['comunidades'])->firstWhere('nombre', 'Kapi')['vehiculo']);
    }

    public function test_resuelve_el_pedido_que_habia_hecho_por_la_app(): void
    {
        Sanctum::actingAs($this->usuarios['PRODUCTOR']);
        $this->postJson('/api/v1/productor/solicitud-zona', ['comunidad_destino_id' => $this->comunidad['Carata']])->assertCreated();

        Sanctum::actingAs($this->usuarios['ADMIN']);
        $this->postJson("/api/v1/admin/proveedores/{$this->proveedorId}/cambiar-comunidad", [
            'comunidad_id' => $this->comunidad['Carata'], 'canal' => 'OFICINA',
        ])->assertCreated();

        $this->assertSame(0, DB::table('solicitudes_cambio_zona')->where('estado', 'PENDIENTE')->count());
        $this->assertSame(2, DB::table('solicitudes_cambio_zona')->where('estado', 'APROBADA')->count());
    }

    public function test_cambio_con_fecha_futura_queda_programado_y_se_aplica_ese_dia(): void
    {
        Sanctum::actingAs($this->usuarios['ADMIN']);
        $en3 = now('America/Lima')->addDays(3)->toDateString();
        $this->postJson("/api/v1/admin/proveedores/{$this->proveedorId}/cambiar-comunidad", [
            'comunidad_id' => $this->comunidad['Kapi'], 'canal' => 'OFICINA', 'desde' => $en3,
        ])->assertCreated();

        // todavía en Huatta con C-01, con el aviso "pasa a Kapi"
        $p = collect($this->getJson('/api/v1/admin/padron')->json('proveedores'))->firstWhere('id', $this->proveedorId);
        $this->assertSame('Huatta', $p['comunidad']);
        $this->assertSame(['desde' => $en3, 'comunidad' => 'Kapi', 'vehiculo' => 'M-01'], $p['programado']);
        $this->assertNull($p['cambio_reciente']);
        // otro cambio mientras hay uno programado: no
        $this->postJson("/api/v1/admin/proveedores/{$this->proveedorId}/cambiar-comunidad", [
            'comunidad_id' => $this->comunidad['Carata'], 'canal' => 'LLAMADA',
        ])->assertStatus(422);

        $this->travel(3)->days();
        $p = collect($this->getJson('/api/v1/admin/padron')->json('proveedores'))->firstWhere('id', $this->proveedorId);
        $this->assertSame('Kapi', $p['comunidad']);
        $this->assertSame('M-01', $p['vehiculo']);
        $this->assertNull($p['programado']);
        $this->assertSame('Huatta', $p['cambio_reciente']['antes']);

        // a los 16 días ya no es "reciente"
        $this->travel(16)->days();
        $p = collect($this->getJson('/api/v1/admin/padron')->json('proveedores'))->firstWhere('id', $this->proveedorId);
        $this->assertNull($p['cambio_reciente']);
    }

    public function test_historial_de_comunidades(): void
    {
        Sanctum::actingAs($this->usuarios['ADMIN']);
        $url = "/api/v1/admin/proveedores/{$this->proveedorId}/cambiar-comunidad";
        $this->postJson($url, ['comunidad_id' => $this->comunidad['Carata'], 'canal' => 'LLAMADA', 'motivo' => 'Alquiló pastos'])->assertCreated();
        $this->travel(1)->days();
        $this->postJson($url, ['comunidad_id' => $this->comunidad['Kapi'], 'canal' => 'OFICINA'])->assertCreated();

        $h = $this->getJson("/api/v1/admin/proveedores/{$this->proveedorId}/historial-comunidad")->assertOk()->json('pasos');
        $this->assertSame(['Huatta', 'Carata', 'Kapi'], array_column($h, 'comunidad'));
        $this->assertSame(['Inscripción', 'Llamó por teléfono', 'Vino a Huata'], array_column($h, 'como'));
        $this->assertSame('Alquiló pastos', $h[1]['motivo']);
    }

    public function test_el_acopiador_ve_quien_llego_y_quien_se_fue_de_su_ruta(): void
    {
        Sanctum::actingAs($this->usuarios['ADMIN']);
        $this->postJson("/api/v1/admin/proveedores/{$this->proveedorId}/cambiar-comunidad", [
            'comunidad_id' => $this->comunidad['Kapi'], 'canal' => 'LLAMADA',
        ])->assertCreated();

        Sanctum::actingAs($this->usuarios['ACOPIADOR']);
        $b = $this->getJson('/api/v1/sync/bootstrap')->assertOk()->json();
        $p = collect($b['proveedores'])->firstWhere('id', $this->proveedorId);
        $this->assertSame('Huatta', $p['cambio_reciente']['antes']);
        $this->assertEquals($this->vehiculo['C-01'], $p['cambio_reciente']['antes_vehiculo_id']);
        $this->assertCount(1, $b['se_fueron']);
        $this->assertEquals($this->vehiculo['C-01'], $b['se_fueron'][0]['de_vehiculo_id']);
        $this->assertSame('Kapi', $b['se_fueron'][0]['ahora']);
    }

    private function registrar(string $comunidad, ?string $desde = null, string $canal = 'LLAMADA')
    {
        return $this->postJson("/api/v1/admin/proveedores/{$this->proveedorId}/cambiar-comunidad", array_filter([
            'comunidad_id' => $this->comunidad[$comunidad], 'canal' => $canal, 'desde' => $desde,
        ]))->assertCreated();
    }

    private function comunidadActual(): string
    {
        return DB::table('proveedores as p')->join('comunidades as c', 'c.id', '=', 'p.comunidad_id')
            ->where('p.id', $this->proveedorId)->value('c.nombre');
    }

    public function test_corrige_lugar_fecha_y_motivo_de_un_traslado_programado(): void
    {
        Sanctum::actingAs($this->usuarios['ADMIN']);
        $en3 = now('America/Lima')->addDays(3)->toDateString();
        $en5 = now('America/Lima')->addDays(5)->toDateString();
        $this->registrar('Carata', $en3);
        $url = "/api/v1/admin/proveedores/{$this->proveedorId}/traslado";

        $this->getJson($url)->assertOk()->assertJsonPath('traslado.destino', 'Carata')->assertJsonPath('traslado.programado', true);
        $this->postJson($url, ['comunidad_id' => $this->comunidad['Kapi'], 'desde' => $en5, 'canal' => 'OFICINA', 'motivo' => 'Se confundió de lugar'])
            ->assertOk()->assertJsonPath('comunidad', 'Kapi')->assertJsonPath('desde', $en5);

        $t = $this->getJson($url)->json('traslado');
        $this->assertSame(['Kapi', $en5, 'OFICINA', 'Se confundió de lugar'], [$t['destino'], $t['desde'], $t['canal'], $t['motivo']]);
        $this->assertSame('Huatta', $this->comunidadActual()); // no se movió antes de tiempo

        // si se adelanta a hoy, se aplica ya
        $this->postJson($url, ['comunidad_id' => $this->comunidad['Kapi'], 'desde' => now('America/Lima')->toDateString()])->assertOk();
        $this->assertSame('Kapi', $this->comunidadActual());
    }

    public function test_un_traslado_ya_hecho_se_corrige_de_lugar_pero_no_de_fecha(): void
    {
        Sanctum::actingAs($this->usuarios['ADMIN']);
        $this->registrar('Carata');
        $url = "/api/v1/admin/proveedores/{$this->proveedorId}/traslado";

        $this->postJson($url, ['comunidad_id' => $this->comunidad['Kapi']])->assertOk();
        $this->assertSame('Kapi', $this->comunidadActual());
        $this->postJson($url, ['comunidad_id' => $this->comunidad['Kapi'], 'desde' => now('America/Lima')->addDays(2)->toDateString()])
            ->assertStatus(422);
        $this->postJson($url, ['comunidad_id' => $this->comunidad['Huatta']])->assertStatus(422); // eso es anular
    }

    public function test_anular_traslado(): void
    {
        Sanctum::actingAs($this->usuarios['ADMIN']);
        // ya hecho: vuelve a donde estaba y el traslado desaparece
        $this->registrar('Carata');
        $this->postJson("/api/v1/admin/proveedores/{$this->proveedorId}/traslado/anular")->assertOk()->assertJsonPath('se_queda_en', 'Huatta');
        $this->assertSame('Huatta', $this->comunidadActual());
        $this->assertSame(0, DB::table('solicitudes_cambio_zona')->count());

        // programado y pedido por la app: no se mueve y queda como rechazado
        Sanctum::actingAs($this->usuarios['PRODUCTOR']);
        $id = $this->postJson('/api/v1/productor/solicitud-zona', ['comunidad_destino_id' => $this->comunidad['Kapi']])->json('id');
        Sanctum::actingAs($this->usuarios['ADMIN']);
        $this->postJson("/api/v1/admin/solicitudes/$id/resolver", ['aprobar' => true])->assertOk();
        $this->postJson("/api/v1/admin/proveedores/{$this->proveedorId}/traslado/anular")->assertOk();
        $this->assertSame('RECHAZADA', DB::table('solicitudes_cambio_zona')->find($id)->estado);
        $this->travel(4)->days();
        $this->getJson('/api/v1/admin/padron')->assertOk();
        $this->assertSame('Huatta', $this->comunidadActual());
        $this->getJson("/api/v1/admin/proveedores/{$this->proveedorId}/traslado")->assertNotFound();
    }

    public function test_validaciones(): void
    {
        Sanctum::actingAs($this->usuarios['ADMIN']);
        $url = "/api/v1/admin/proveedores/{$this->proveedorId}/cambiar-comunidad";
        $this->postJson($url, ['comunidad_id' => $this->comunidad['Huatta'], 'canal' => 'LLAMADA'])
            ->assertStatus(422)->assertJsonPath('message', 'El proveedor ya está en esa comunidad.');
        $this->postJson($url, ['comunidad_id' => $this->comunidad['Kapi']])->assertStatus(422)->assertJsonValidationErrors('canal');
        $this->postJson($url, ['comunidad_id' => $this->comunidad['Kapi'], 'canal' => 'LLAMADA', 'desde' => now()->subDays(3)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('desde');

        Sanctum::actingAs($this->usuarios['ACOPIADOR']);
        $this->postJson($url, ['comunidad_id' => $this->comunidad['Kapi'], 'canal' => 'LLAMADA'])->assertForbidden();
    }
}
