<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** GET /calidad/muestreo: el analista elige a cualquiera entre lo que aún no analizó. */
class CalidadMuestreoTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        Sanctum::actingAs($this->usuarios['CALIDAD']);
    }

    private function entrega(int $proveedor, string $fecha, float $litros = 15, bool $ausente = false): int
    {
        return DB::table('entregas')->insertGetId([
            'uuid' => (string) Str::uuid(), 'proveedor_id' => $proveedor, 'vehiculo_id' => $this->vehiculo['C-01'],
            'fecha' => $fecha, 'litros' => $litros, 'ausente' => $ausente, 'estado_calidad' => 'ACEPTADO',
            'registrado_por' => $this->usuarios['ACOPIADOR']->id, 'registrado_en' => "$fecha 06:00:00",
        ]);
    }

    private function otroProveedor(string $nombre, string $dni): int
    {
        return DB::table('proveedores')->insertGetId([
            'uuid' => (string) Str::uuid(), 'nombre' => $nombre, 'dni' => $dni,
            'comunidad_id' => $this->comunidad['Carata'], 'vehiculo_id' => $this->vehiculo['C-01'], 'estado' => 'ACTIVO',
        ]);
    }

    private function nombres(): array
    {
        return array_column($this->getJson('/api/v1/calidad/muestreo')->assertOk()->json('entregas'), 'proveedor');
    }

    public function test_muestra_lo_de_hoy_y_ayer_sin_analizar(): void
    {
        $hoy = now('America/Lima');
        $this->entrega($this->proveedorId, $hoy->toDateString());
        $this->entrega($this->otroProveedor('Julián Apaza', '40223344'), $hoy->copy()->subDay()->toDateString());
        $this->entrega($this->otroProveedor('Elena Choque', '40334455'), $hoy->copy()->subDays(3)->toDateString()); // muy vieja

        $this->assertEqualsCanonicalizing(['Rosa Quispe Mamani', 'Julián Apaza'], $this->nombres());
    }

    public function test_no_muestra_no_entrego_ni_lo_ya_analizado(): void
    {
        $hoy = now('America/Lima')->toDateString();
        $this->entrega($this->otroProveedor('No Salió Hoy', '40223344'), $hoy, 0, ausente: true);
        $analizada = $this->entrega($this->proveedorId, $hoy);
        DB::table('analisis_calidad')->insert([
            'uuid' => (string) Str::uuid(), 'entrega_id' => $analizada, 'muestra' => 'CAMPO', 'veredicto' => 'ACEPTADO',
            'analista_id' => $this->usuarios['CALIDAD']->id, 'tomada_en' => now('America/Lima'),
        ]);

        $d = $this->getJson('/api/v1/calidad/muestreo')->assertOk();
        $this->assertSame([], $d->json('entregas'));
        $this->assertSame(1, $d->json('analizadas_hoy'));
    }

    public function test_marca_cuales_son_de_hoy(): void
    {
        $hoy = now('America/Lima');
        $this->entrega($this->proveedorId, $hoy->toDateString());
        $this->entrega($this->otroProveedor('Julián Apaza', '40223344'), $hoy->copy()->subDay()->toDateString());

        $porNombre = collect($this->getJson('/api/v1/calidad/muestreo')->json('entregas'))->pluck('es_hoy', 'proveedor');
        $this->assertTrue($porNombre['Rosa Quispe Mamani']);
        $this->assertFalse($porNombre['Julián Apaza']);
    }

    public function test_solo_calidad_y_admin(): void
    {
        Sanctum::actingAs($this->usuarios['ACOPIADOR']);
        $this->getJson('/api/v1/calidad/muestreo')->assertForbidden();
    }
}
