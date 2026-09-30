<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** POST /sync/push con PROVEEDOR: alta desde el campo. */
class SyncProveedorTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        Sanctum::actingAs($this->usuarios['ACOPIADOR']);
    }

    private function op(string $uuid, string $dni, string $nombre = 'Nuevo Proveedor Prueba'): array
    {
        return [
            'uuid' => $uuid, 'tipo' => 'PROVEEDOR', 'nombre' => $nombre, 'dni' => $dni,
            'comunidad_id' => $this->comunidad['Carata'], 'vehiculo_id' => $this->vehiculo['C-01'],
        ];
    }

    private function push(array $op): array
    {
        return $this->postJson('/api/v1/sync/push', ['operaciones' => [$op]])->assertOk()->json('resultados.0');
    }

    public function test_proveedor_nuevo_se_crea(): void
    {
        $r = $this->push($this->op('22222222-0000-4000-8000-000000000001', '49999991'));

        $this->assertSame('APLICADO', $r['estado']);
        $p = DB::table('proveedores')->find($r['id']);
        $this->assertSame('49999991', $p->dni);
        $this->assertSame($this->usuarios['ACOPIADOR']->id, (int) $p->registrado_por);
    }

    public function test_reenviar_el_mismo_uuid_no_duplica(): void
    {
        $op = $this->op('22222222-0000-4000-8000-000000000001', '49999991');
        $id = $this->push($op)['id'];
        $r = $this->push($op);

        $this->assertSame('DUPLICADO', $r['estado']);
        $this->assertSame($id, $r['id']);
    }

    public function test_dni_existente_devuelve_el_proveedor_que_ya_existe(): void
    {
        // otro acopiador ya registró a Rosa (DNI 40112233)
        $r = $this->push($this->op('22222222-0000-4000-8000-000000000002', '40112233', 'Rosa Q.'));

        $this->assertSame('DUPLICADO', $r['estado']);
        $this->assertSame($this->proveedorId, $r['id']);
        $this->assertSame('Rosa Quispe Mamani', $r['nombre']);
        $this->assertSame(1, DB::table('proveedores')->count());
    }
}
