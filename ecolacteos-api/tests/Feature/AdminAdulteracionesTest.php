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

/** GET /admin/adulteraciones: el admin ve a quienes Calidad sancionó por adulterar la leche. */
class AdminAdulteracionesTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        Sanctum::actingAs($this->usuarios['ADMIN']);
    }

    private function otroProveedor(string $nombre, string $dni): int
    {
        return DB::table('proveedores')->insertGetId([
            'uuid' => (string) Str::uuid(), 'nombre' => $nombre, 'dni' => $dni,
            'comunidad_id' => $this->comunidad['Carata'], 'vehiculo_id' => $this->vehiculo['C-01'], 'estado' => 'ACTIVO',
        ]);
    }

    /** Entrega analizada por Calidad y resuelta con el servicio real (aplica la sanción). */
    private function analizar(int $proveedor, string $fecha, float $agua): void
    {
        $entrega = DB::table('entregas')->insertGetId([
            'uuid' => (string) Str::uuid(), 'proveedor_id' => $proveedor, 'vehiculo_id' => $this->vehiculo['C-01'],
            'fecha' => $fecha, 'litros' => 12, 'registrado_por' => $this->usuarios['ACOPIADOR']->id,
            'registrado_en' => "$fecha 06:00:00",
        ]);
        $analisis = AnalisisCalidad::create([
            'uuid' => (string) Str::uuid(), 'entrega_id' => $entrega, 'muestra' => 'CAMPO',
            'grasa' => 3.4, 'proteina' => 3.1, 'densidad' => 1.030, 'temperatura' => 8, 'ph' => 6.7,
            'agua_anadida' => $agua, 'analista_id' => $this->usuarios['CALIDAD']->id, 'tomada_en' => "$fecha 07:00:00",
        ]);
        app(CalidadService::class)->resolver($analisis, false, $this->usuarios['CALIDAD']->id);
    }

    public function test_lista_dados_de_baja_primero_con_cada_vez_que_adultero(): void
    {
        $julian = $this->otroProveedor('Julián Apaza', '40223344');
        $this->analizar($julian, '2026-09-20', 7);          // 1.ª vez: advertencia
        $this->analizar($this->proveedorId, '2026-09-10', 6);
        $this->analizar($this->proveedorId, '2026-09-18', 9); // 2.ª vez: baja

        $d = $this->getJson('/api/v1/admin/adulteraciones')->assertOk();

        $this->assertSame(1, $d->json('de_baja'));
        $this->assertSame(1, $d->json('advertidos'));
        $this->assertSame(['Rosa Quispe Mamani', 'Julián Apaza'], array_column($d->json('proveedores'), 'nombre'));

        $rosa = $d->json('proveedores.0');
        $this->assertTrue($rosa['de_baja']);
        $this->assertStringContainsString('Segunda adulteración', $rosa['motivo_baja']);
        $this->assertSame(['PRECIO_REDUCIDO', 'BAJA_DEFINITIVA'], array_column($rosa['veces'], 'medida'));
        $this->assertSame(['2026-09-10', '2026-09-18'], array_column($rosa['veces'], 'fecha'));
        $this->assertEquals(9, $rosa['veces'][1]['agua_anadida']);
        $this->assertSame('Usuario CALIDAD', $rosa['veces'][1]['analista']);

        $this->assertFalse($d->json('proveedores.1.de_baja'));
    }

    public function test_no_incluye_otras_sanciones_ni_proveedores_limpios(): void
    {
        DB::table('sanciones')->insert([
            'uuid' => (string) Str::uuid(), 'proveedor_id' => $this->proveedorId, 'tipo' => 'ACIDEZ',
            'nivel' => 1, 'medida' => 'CAPACITACION', 'resuelta_por' => $this->usuarios['CALIDAD']->id,
        ]);

        $d = $this->getJson('/api/v1/admin/adulteraciones')->assertOk();
        $this->assertSame([], $d->json('proveedores'));
    }

    public function test_solo_admin(): void
    {
        Sanctum::actingAs($this->usuarios['CALIDAD']);
        $this->getJson('/api/v1/admin/adulteraciones')->assertForbidden();
    }
}
