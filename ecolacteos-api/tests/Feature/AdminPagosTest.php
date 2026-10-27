<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Pagos a proveedores desde la app del admin (AdminPagosController). */
class AdminPagosTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    private Carbon $lunesPasado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        Sanctum::actingAs($this->usuarios['ADMIN']);
        $this->lunesPasado = Carbon::today('America/Lima')->startOfWeek(Carbon::MONDAY)->subWeek();

        // semana pasada: lunes 20 L aceptados, martes 10 L rechazados, miércoles 15 L aceptados
        $this->entrega(0, 20, 'ACEPTADO', 36);
        $this->entrega(1, 10, 'RECHAZADO', 0);
        $this->entrega(2, 15, 'ACEPTADO', 27);
    }

    private function entrega(int $dia, float $litros, string $estado, float $monto): void
    {
        $fecha = $this->lunesPasado->copy()->addDays($dia)->toDateString();
        DB::table('entregas')->insert([
            'uuid' => (string) Str::uuid(), 'proveedor_id' => $this->proveedorId, 'vehiculo_id' => $this->vehiculo['C-01'],
            'fecha' => $fecha, 'litros' => $litros, 'estado_calidad' => $estado, 'precio_litro' => 1.80, 'monto' => $monto,
            'registrado_por' => $this->usuarios['ACOPIADOR']->id, 'registrado_en' => "$fecha 06:00:00",
        ]);
    }

    public function test_genera_la_boleta_de_la_semana_cerrada(): void
    {
        $d = $this->getJson('/api/v1/admin/pagos')->assertOk();

        $d->assertJsonPath('inicio', $this->lunesPasado->toDateString())
            ->assertJsonPath('pendientes', 1);
        $this->assertEquals(63, $d->json('total'));             // 36 + 27: lo rechazado no se paga
        $this->assertEquals(35, $d->json('boletas.0.litros'));
        $this->assertSame('Rosa Quispe Mamani', $d->json('boletas.0.proveedor'));
        $this->assertCount(6, $d->json('semanas'));
    }

    public function test_detalle_dia_por_dia(): void
    {
        $id = $this->getJson('/api/v1/admin/pagos')->json('boletas.0.id');
        $dias = $this->getJson("/api/v1/admin/pagos/$id")->assertOk()->json('dias');

        $this->assertCount(7, $dias);
        $this->assertSame('rechazado en calidad', $dias[1]['nota']);
        $this->assertSame('sin registro', $dias[3]['nota']);
    }

    public function test_pagar_con_yape(): void
    {
        $id = $this->getJson('/api/v1/admin/pagos')->json('boletas.0.id');

        $this->postJson('/api/v1/admin/pagos/pagar', ['ids' => [$id], 'metodo' => 'YAPE'])
            ->assertOk()->assertJson(['pagadas' => 1, 'total' => 63]);

        $b = DB::table('pagos_semanales')->find($id);
        $this->assertSame('PAGADO', $b->estado);
        $this->assertSame('YAPE', $b->metodo);
        $this->assertSame($this->usuarios['ADMIN']->id, (int) $b->pagado_por);
        $this->assertSame(Carbon::today('America/Lima')->toDateString(), substr($b->pagado_el, 0, 10));
    }

    public function test_el_productor_ve_su_pago(): void
    {
        $id = $this->getJson('/api/v1/admin/pagos')->json('boletas.0.id');
        $this->postJson('/api/v1/admin/pagos/pagar', ['ids' => [$id], 'metodo' => 'EFECTIVO']);

        Sanctum::actingAs($this->usuarios['PRODUCTOR']);
        $pago = collect($this->getJson('/api/v1/productor/resumen')->json('pagos'))->firstWhere('estado', 'PAGADO');
        $this->assertSame('EFECTIVO', $pago['metodo']);
        $this->assertEquals(63, $pago['monto']);
    }

    public function test_una_boleta_pagada_no_se_recalcula_ni_se_paga_dos_veces(): void
    {
        $id = $this->getJson('/api/v1/admin/pagos')->json('boletas.0.id');
        $this->postJson('/api/v1/admin/pagos/pagar', ['ids' => [$id], 'metodo' => 'EFECTIVO']);

        // llega tarde otra entrega de esa semana: la boleta ya pagada no cambia
        $this->entrega(4, 50, 'ACEPTADO', 90);
        $this->assertEquals(63, $this->getJson('/api/v1/admin/pagos')->json('total'));

        $this->postJson('/api/v1/admin/pagos/pagar', ['ids' => [$id], 'metodo' => 'YAPE'])
            ->assertJson(['pagadas' => 0]);
        $this->assertSame('EFECTIVO', DB::table('pagos_semanales')->find($id)->metodo);
    }

    public function test_la_semana_en_curso_no_se_paga(): void
    {
        $this->getJson('/api/v1/admin/pagos?semana=' . Carbon::today('America/Lima')->toDateString())
            ->assertStatus(422);
    }

    public function test_metodo_obligatorio(): void
    {
        $id = $this->getJson('/api/v1/admin/pagos')->json('boletas.0.id');
        $this->postJson('/api/v1/admin/pagos/pagar', ['ids' => [$id], 'metodo' => 'TARJETA'])
            ->assertStatus(422)->assertJsonPath('message', 'Elige efectivo o Yape.');
    }

    public function test_solo_el_admin(): void
    {
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $this->getJson('/api/v1/admin/pagos')->assertForbidden();
    }

    public function test_deudas_junta_lo_pendiente_de_varias_semanas(): void
    {
        // otra semana sin pagar, dos semanas antes
        $this->lunesPasado->subWeeks(2);
        $this->entrega(0, 10, 'ACEPTADO', 18);
        $this->lunesPasado->addWeeks(2);

        $d = $this->getJson('/api/v1/admin/pagos/deudas')->assertOk();
        $this->assertEquals(81, $d->json('total'));              // 63 + 18
        $this->assertSame(2, $d->json('cuantas'));
        $this->assertCount(1, $d->json('proveedores'));
        $this->assertCount(2, $d->json('proveedores.0.semanas'));
        $this->assertSame($this->lunesPasado->copy()->subWeeks(2)->toDateString(), $d->json('proveedores.0.desde'));

        // pagar una semana desde la lista la quita de las deudas
        $this->postJson('/api/v1/admin/pagos/pagar', ['ids' => [$d->json('proveedores.0.semanas.0.id')], 'metodo' => 'EFECTIVO'])->assertOk();
        $this->assertEquals(63, $this->getJson('/api/v1/admin/pagos/deudas')->json('total'));
    }

    public function test_calendario_y_totales_de_un_dia(): void
    {
        $mes = $this->lunesPasado->format('Y-m');
        $c = $this->getJson('/api/v1/admin/acopio/calendario?mes=' . $mes)->assertOk();
        $c->assertJsonPath('mes', $mes);
        $dia = collect($c->json('dias'))->firstWhere('fecha', $this->lunesPasado->toDateString());
        $this->assertEquals(20, $dia['litros']);

        $t = $this->getJson('/api/v1/admin/totales?fecha=' . $this->lunesPasado->toDateString())->assertOk();
        $t->assertJsonPath('desde', $this->lunesPasado->toDateString())->assertJsonPath('hasta', $this->lunesPasado->toDateString());
        $this->assertEquals(20, $t->json('litros'));
    }

    public function test_la_ficha_del_padron_trae_lo_que_se_debe_para_pagarlo(): void
    {
        $this->getJson('/api/v1/admin/pagos')->assertOk(); // genera la boleta
        $f = $this->getJson("/api/v1/admin/proveedores/{$this->proveedorId}/ficha")->assertOk();
        $this->assertCount(1, $f->json('pagos.pendientes'));
        $this->assertEquals(63, $f->json('pagos.pendientes.0.monto'));
        $semana = collect($f->json('semanas'))->firstWhere('estado', 'PENDIENTE');
        $this->assertSame($f->json('pagos.pendientes.0.id'), $semana['boleta_id']);

        $this->postJson('/api/v1/admin/pagos/pagar', ['ids' => [$semana['boleta_id']], 'metodo' => 'YAPE'])->assertOk();
        $this->assertCount(0, $this->getJson("/api/v1/admin/proveedores/{$this->proveedorId}/ficha")->json('pagos.pendientes'));
    }

    public function test_inicio_de_la_app_junta_las_cifras_del_dia(): void
    {
        $this->getJson('/api/v1/admin/pagos')->assertOk(); // genera la boleta de la semana pasada
        $d = $this->getJson('/api/v1/admin/inicio')->assertOk();
        $this->assertSame($this->lunesPasado->copy()->addDays(2)->toDateString(), $d->json('leche.fecha')); // último día con acopio
        $this->assertEquals(15, $d->json('leche.litros'));
        $this->assertEquals(63, $d->json('se_debe.total'));
        $this->assertSame(1, $d->json('se_debe.proveedores'));
        $this->assertSame(0, $d->json('lotes_en_proceso'));
    }
}
