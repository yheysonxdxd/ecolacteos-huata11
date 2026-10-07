<?php

namespace Tests\Feature;

use App\Models\AnalisisCalidad;
use App\Services\CalidadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** GET /admin/rechazos y "Ya se le capacitó": rechazos de Calidad que no son adulteración. */
class AdminRechazosTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        Sanctum::actingAs($this->usuarios['ADMIN']);
    }

    /** Entrega analizada y resuelta con el servicio real; devuelve el id de su sanción (si hubo). */
    private function analizar(string $fecha, array $m, bool $aceptar = false, ?int $proveedor = null): ?int
    {
        $entrega = DB::table('entregas')->insertGetId([
            'uuid' => (string) Str::uuid(), 'proveedor_id' => $proveedor ?? $this->proveedorId, 'vehiculo_id' => $this->vehiculo['C-01'],
            'fecha' => $fecha, 'litros' => 10, 'registrado_por' => $this->usuarios['ACOPIADOR']->id, 'registrado_en' => "$fecha 06:00:00",
        ]);
        $analisis = AnalisisCalidad::create($m + [
            'uuid' => (string) Str::uuid(), 'entrega_id' => $entrega, 'muestra' => 'CAMPO',
            'grasa' => 3.4, 'proteina' => 3.1, 'densidad' => 1.030, 'temperatura' => 5, 'ph' => 6.7, 'agua_anadida' => 0,
            'analista_id' => $this->usuarios['CALIDAD']->id, 'tomada_en' => "$fecha 07:00:00",
        ]);
        $r = app(CalidadService::class)->resolver($analisis, $aceptar, $this->usuarios['CALIDAD']->id);

        return $r['sancion']?->id;
    }

    public function test_solo_el_admin(): void
    {
        Sanctum::actingAs($this->usuarios['CALIDAD']);
        $this->getJson('/api/v1/admin/rechazos')->assertForbidden();
    }

    public function test_lista_la_semana_con_causas_y_resumen(): void
    {
        $this->analizar('2026-09-21', ['temperatura' => 8.4]);
        $this->analizar('2026-09-23', ['grasa' => 2.5, 'ph' => 6.3]);
        $this->analizar('2026-09-24', ['agua_anadida' => 7]);    // adulteración: no va aquí
        $this->analizar('2026-09-15', ['temperatura' => 9]);     // otra semana

        $d = $this->getJson('/api/v1/admin/rechazos?semana=2026-09-24')->assertOk();

        $this->assertSame('2026-09-21', $d->json('inicio'));
        $this->assertSame('2026-09-27', $d->json('fin'));
        $this->assertSame(['2026-09-23', '2026-09-21'], array_column($d->json('filas'), 'fecha'));
        $this->assertSame(['Grasa baja', 'pH (acidez)'], $d->json('filas.0.grupos'));
        $this->assertTrue($d->json('filas.0.rechazada'));
        $this->assertSame(2, $d->json('resumen.rechazos'));
        $this->assertEquals(20, $d->json('resumen.litros_rechazados'));
        $this->assertSame(3, $d->json('filas.0.veces_30_dias')); // 15, 21 y 23 de setiembre
        $this->assertTrue($d->json('filas.0.repite'));
        $this->assertSame(1, $d->json('por_capacitar'));
    }

    public function test_capacitado_marca_sus_rechazos_hasta_ese_dia(): void
    {
        $a = $this->analizar('2026-09-15', ['temperatura' => 9]);
        $b = $this->analizar('2026-09-21', ['temperatura' => 8.4]);
        $c = $this->analizar('2026-09-28', ['ph' => 6.3]);

        $this->postJson("/api/v1/admin/rechazos/$b/capacitado", ['nota' => 'Que enfríe el porongo en el agua del puquio'])
            ->assertOk()->assertJson(['marcados' => 2]);

        $this->assertNotNull(DB::table('sanciones')->where('id', $a)->value('capacitado_en'));
        $this->assertNull(DB::table('sanciones')->where('id', $c)->value('capacitado_en'));

        $fila = $this->getJson('/api/v1/admin/rechazos?semana=2026-09-21')->json('filas.0');
        $this->assertSame('Usuario ADMIN', $fila['capacitado_por']);
        $this->assertSame('Que enfríe el porongo en el agua del puquio', $fila['nota']);

        $this->postJson('/api/v1/admin/rechazos/999999/capacitado')->assertNotFound();
    }
}
