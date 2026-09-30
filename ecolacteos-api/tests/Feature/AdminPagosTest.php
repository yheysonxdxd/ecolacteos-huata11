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
}
