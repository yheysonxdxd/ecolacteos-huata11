<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Pestaña Comunidad del productor (ProductorZonaController). */
class ProductorZonaTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        Sanctum::actingAs($this->usuarios['PRODUCTOR']);
    }

    private function solicitar(int $destino)
    {
        return $this->postJson('/api/v1/productor/solicitud-zona', ['comunidad_destino_id' => $destino]);
    }

    public function test_muestra_asignacion_y_destinos(): void
    {
        $d = $this->getJson('/api/v1/productor/comunidad')->assertOk();

        $d->assertJsonPath('asignacion.comunidad', 'Huatta')
            ->assertJsonPath('asignacion.vehiculo', 'C-01')
            ->assertJsonPath('asignacion.acopiador', 'Usuario ACOPIADOR');
        // destinos: todas menos la actual, con el vehículo cuya ruta pasa por ahí
        $destinos = collect($d->json('destinos'))->pluck('vehiculo', 'comunidad')->all();
        $this->assertSame(['Carata' => 'C-01', 'Kapi' => 'M-01'], $destinos);
    }

    public function test_crea_la_solicitud_para_su_propio_proveedor(): void
    {
        $this->solicitar($this->comunidad['Kapi'])->assertCreated()->assertJsonPath('estado', 'PENDIENTE');

        $s = DB::table('solicitudes_cambio_zona')->sole();
        $this->assertSame($this->proveedorId, (int) $s->proveedor_id);
        $this->assertSame($this->comunidad['Huatta'], (int) $s->comunidad_origen_id);
        $this->assertSame(now('America/Lima')->addDays(3)->toDateString(), substr($s->solicitada_para, 0, 10));
    }

    public function test_no_permite_dos_solicitudes_pendientes(): void
    {
        $this->solicitar($this->comunidad['Kapi'])->assertCreated();
        $this->solicitar($this->comunidad['Carata'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Ya tienes una solicitud esperando aprobación.']);
    }

    public function test_no_permite_pedir_su_misma_comunidad(): void
    {
        $this->solicitar($this->comunidad['Huatta'])->assertStatus(422);
    }

    public function test_el_admin_aprueba_y_cambia_la_asignacion(): void
    {
        $id = $this->solicitar($this->comunidad['Kapi'])->json('id');

        Sanctum::actingAs($this->usuarios['ADMIN']);
        $this->postJson("/api/v1/admin/solicitudes/$id/resolver", ['aprobar' => true])
            ->assertOk()->assertJsonPath('vehiculo', 'M-01');

        Sanctum::actingAs($this->usuarios['PRODUCTOR']);
        $this->getJson('/api/v1/productor/comunidad')
            ->assertJsonPath('asignacion.comunidad', 'Kapi')
            ->assertJsonPath('solicitudes.0.estado', 'APROBADA');
    }

    public function test_usuario_sin_proveedor_da_404(): void
    {
        DB::table('proveedores')->update(['user_id' => null]);
        $this->getJson('/api/v1/productor/comunidad')->assertNotFound();
    }
}
