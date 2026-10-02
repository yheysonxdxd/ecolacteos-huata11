<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Padrón: corregir un proveedor, eliminarlo (sin historia) o darlo de baja, y cuánto entrega. */
class AdminProveedorEditarTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        Sanctum::actingAs($this->usuarios['ADMIN']);
    }

    private function nuevo(string $dni = '41234567'): int
    {
        return DB::table('proveedores')->insertGetId([
            'uuid' => (string) Str::uuid(), 'nombre' => 'Juan Mamani Condori', 'dni' => $dni,
            'comunidad_id' => $this->comunidad['Carata'], 'vehiculo_id' => $this->vehiculo['C-01'], 'estado' => 'ACTIVO',
        ]);
    }

    private function entrega(int $proveedor, float $litros, int $haceDias): void
    {
        DB::table('entregas')->insert([
            'uuid' => (string) Str::uuid(), 'proveedor_id' => $proveedor, 'vehiculo_id' => $this->vehiculo['C-01'],
            'registrado_por' => $this->usuarios['ACOPIADOR']->id, 'fecha' => now('America/Lima')->subDays($haceDias)->toDateString(),
            'litros' => $litros, 'ausente' => false, 'estado_calidad' => 'ACEPTADO', 'registrado_en' => now(),
        ]);
    }

    public function test_corrige_nombre_y_dni_tambien_en_su_usuario_de_la_app(): void
    {
        $this->postJson("/api/v1/admin/proveedores/{$this->proveedorId}", [
            'nombre' => '  Rosa   Quispe Mamani de Apaza ', 'dni' => '40112299', 'promedio_litros' => 15, 'vacas_ordeno' => 3,
        ])->assertOk();

        $p = DB::table('proveedores')->find($this->proveedorId);
        $this->assertSame('Rosa Quispe Mamani de Apaza', $p->nombre);
        $this->assertSame('40112299', $p->dni);
        $this->assertEquals(15, $p->promedio_litros);
        $this->assertSame('40112299', $this->usuarios['PRODUCTOR']->fresh()->dni);

        $otro = $this->nuevo();
        $this->postJson("/api/v1/admin/proveedores/$otro", ['nombre' => 'Juan Mamani Condori', 'dni' => '40112299'])
            ->assertStatus(422)->assertJsonValidationErrors('dni');
    }

    public function test_elimina_al_que_se_registro_por_error(): void
    {
        $id = $this->nuevo();
        $this->postJson("/api/v1/admin/proveedores/$id/eliminar")->assertOk();
        $this->assertNull(DB::table('proveedores')->find($id));
    }

    public function test_si_ya_entrego_leche_no_se_elimina_se_da_de_baja(): void
    {
        $this->entrega($this->proveedorId, 12, 1);
        $this->postJson("/api/v1/admin/proveedores/{$this->proveedorId}/eliminar")
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '1 entregas'));

        $this->postJson("/api/v1/admin/proveedores/{$this->proveedorId}/baja", ['motivo' => 'Vendió sus vacas'])->assertOk();
        $p = DB::table('proveedores')->find($this->proveedorId);
        $this->assertSame('BAJA', $p->estado);
        $this->assertFalse((bool) $this->usuarios['PRODUCTOR']->fresh()->activo);
        $this->assertSame(1, DB::table('entregas')->where('proveedor_id', $this->proveedorId)->count()); // su historia se queda

        // ya no sale en la ruta del acopiador
        Sanctum::actingAs($this->usuarios['ACOPIADOR']);
        $this->assertNull(collect($this->getJson('/api/v1/sync/bootstrap')->json('proveedores'))->firstWhere('id', $this->proveedorId));
    }

    private function editar(int $comunidadId)
    {
        return $this->postJson("/api/v1/admin/proveedores/{$this->proveedorId}", [
            'nombre' => 'Rosa Quispe Mamani', 'dni' => '40112233', 'comunidad_id' => $comunidadId,
        ]);
    }

    private function trasladar(string $comunidad, ?string $desde = null)
    {
        return $this->postJson("/api/v1/admin/proveedores/{$this->proveedorId}/cambiar-comunidad", array_filter([
            'comunidad_id' => $this->comunidad[$comunidad], 'canal' => 'LLAMADA', 'desde' => $desde,
        ]))->assertCreated();
    }

    public function test_corrige_la_comunidad_del_alta_sin_tocar_entregas_pasadas(): void
    {
        $this->entrega($this->proveedorId, 12, 1); // recogida por C-01
        $this->editar($this->comunidad['Kapi'])->assertOk()->assertJsonPath('comunidad_corregida', 'ALTA');

        $p = DB::table('proveedores')->find($this->proveedorId);
        $this->assertEquals($this->comunidad['Kapi'], $p->comunidad_id);
        $this->assertEquals($this->vehiculo['M-01'], $p->vehiculo_id);
        $this->assertEquals($this->vehiculo['C-01'], DB::table('entregas')->where('proveedor_id', $this->proveedorId)->value('vehiculo_id'));
    }

    public function test_se_equivocaron_de_destino_en_el_traslado(): void
    {
        $this->trasladar('Carata');
        $this->editar($this->comunidad['Kapi'])->assertOk()->assertJsonPath('comunidad_corregida', 'TRASLADO');

        // un solo traslado en el historial, ya con el destino bien
        $h = $this->getJson("/api/v1/admin/proveedores/{$this->proveedorId}/historial-comunidad")->json('pasos');
        $this->assertSame(['Huatta', 'Kapi'], array_column($h, 'comunidad'));
        $this->assertEquals($this->comunidad['Kapi'], DB::table('proveedores')->find($this->proveedorId)->comunidad_id);
    }

    public function test_el_traslado_no_iba_se_anula(): void
    {
        $this->trasladar('Carata');
        $this->editar($this->comunidad['Huatta'])->assertOk()->assertJsonPath('comunidad_corregida', 'TRASLADO_ANULADO');

        $h = $this->getJson("/api/v1/admin/proveedores/{$this->proveedorId}/historial-comunidad")->json('pasos');
        $this->assertSame(['Huatta'], array_column($h, 'comunidad'));
        $this->assertSame(0, DB::table('proveedor_ubicaciones')->where('proveedor_id', $this->proveedorId)->count());
    }

    public function test_corrige_el_destino_de_un_traslado_programado_sin_moverlo_antes(): void
    {
        $en3 = now('America/Lima')->addDays(3)->toDateString();
        $this->trasladar('Carata', $en3);
        $this->editar($this->comunidad['Kapi'])->assertOk()->assertJsonPath('comunidad_corregida', 'PROGRAMADO');

        $ficha = $this->getJson("/api/v1/admin/proveedores/{$this->proveedorId}/ficha")->assertOk()->json('proveedor');
        $this->assertSame('Huatta', $ficha['comunidad']);
        $this->assertSame('Kapi', $ficha['programado']['comunidad']);
        // sin cambiar la comunidad no se toca el traslado
        $this->editar($this->comunidad['Kapi'])->assertOk()->assertJsonPath('comunidad_corregida', null);
    }

    public function test_ficha_con_leche_y_pagos(): void
    {
        $this->entrega($this->proveedorId, 10, 0);
        $this->entrega($this->proveedorId, 14, 7);  // siempre la semana anterior, ya cerrada
        $this->entrega($this->proveedorId, 20, 70); // hace 10 semanas: fuera de las 8 que se muestran
        DB::table('entregas')->update(['monto' => DB::raw('litros * 1.8'), 'precio_litro' => 1.8]);

        $f = $this->getJson("/api/v1/admin/proveedores/{$this->proveedorId}/ficha")->assertOk()->json();
        $this->assertEquals(12, $f['leche']['por_dia']);
        // una fila por semana: la en curso (hoy) y la anterior, que ya cerró y se armó su boleta
        $this->assertSame(['EN_CURSO', 'PENDIENTE'], array_column($f['semanas'], 'estado'));
        $this->assertEquals(14, $f['semanas'][1]['litros']);
        $this->assertEquals(25.2, $f['semanas'][1]['monto']);
        $this->assertCount(1, $f['semanas'][1]['detalle']);
        $this->assertTrue($f['hay_mas']);
        $this->assertEquals(25.2, $f['pagos']['por_pagar']);

        // "ver semanas anteriores"
        $f = $this->getJson("/api/v1/admin/proveedores/{$this->proveedorId}/ficha?semanas=16")->json();
        $this->assertCount(3, $f['semanas']);
        $this->assertFalse($f['hay_mas']);
        $this->assertSame(1, $f['historia']['entregas'] > 0 ? 1 : 0);
        $this->assertSame('Inscripción', $f['historial'][0]['como']);
    }

    public function test_padron_muestra_cuanto_entrega(): void
    {
        $this->entrega($this->proveedorId, 10, 0);
        $this->entrega($this->proveedorId, 14, 3);
        $this->entrega($this->proveedorId, 12, 20);
        $this->entrega($this->proveedorId, 50, 40); // fuera de los 30 días

        $p = collect($this->getJson('/api/v1/admin/padron')->json('proveedores'))->firstWhere('id', $this->proveedorId);
        $this->assertEquals(12, $p['leche']['por_dia']);
        $this->assertEquals(36, $p['leche']['litros_30']);
        $this->assertEquals(24, $p['leche']['litros_7']);
        $this->assertSame(now('America/Lima')->toDateString(), $p['leche']['ultima']);
        $this->assertSame(4, $p['leche']['entregas']);
    }
}
