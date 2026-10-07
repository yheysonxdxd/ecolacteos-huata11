<?php

namespace Tests\Feature;

use App\Models\Parametro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Configuración desde la web: valores con límites, precio de la leche con fecha y precios de venta, todo con historial. */
class ConfiguracionTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        Sanctum::actingAs($this->usuarios['ADMIN']);
    }

    private function hoy(int $dias = 0): string
    {
        return now('America/Lima')->addDays($dias)->toDateString();
    }

    public function test_solo_el_admin(): void
    {
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $this->getJson('/api/v1/admin/configuracion')->assertForbidden();
        $this->postJson('/api/v1/admin/configuracion', ['valores' => ['calidad.grasa_min' => 3.2]])->assertForbidden();
    }

    public function test_cambiar_valores_con_limites_y_historial(): void
    {
        $campos = collect($this->getJson('/api/v1/admin/configuracion')->assertOk()->json('campos'))->keyBy('clave');
        $this->assertSame('Grasa mínima', $campos['calidad.grasa_min']['etiqueta']);
        $this->assertEquals(66.7, $campos['precio.factor_adulteracion']['valor']); // se muestra en %

        $this->postJson('/api/v1/admin/configuracion', ['valores' => [
            'calidad.grasa_min' => '3,2', 'precio.factor_adulteracion' => 50, 'empresa.ruc' => '20601234567', 'empresa.nombre' => 'Planta de Lácteos Ecolácteos Huata',
        ]])->assertOk()->assertJsonPath('cambios', 3);

        $this->assertEquals(3.2, Parametro::valor('calidad.grasa_min'));
        $this->assertEquals(0.5, Parametro::valor('precio.factor_adulteracion'));
        $this->assertSame('20601234567', Parametro::valor('empresa.ruc'));
        // Calidad ya evalúa con el valor nuevo
        $this->assertEquals(3.2, $this->getJson('/api/v1/calidad/parametros')->json('grasa_min'));

        $h = collect($this->getJson('/api/v1/admin/configuracion')->json('historial'));
        $grasa = $h->firstWhere('que', 'Grasa mínima');
        $this->assertSame('3 %', $grasa['antes']);
        $this->assertSame('3,2 %', $grasa['despues']);
        $this->assertSame($this->usuarios['ADMIN']->name, $grasa['usuario']);

        // fuera de rango, mínimo sobre el máximo y RUC mal: no se guarda nada
        $this->postJson('/api/v1/admin/configuracion', ['valores' => ['calidad.grasa_min' => 20]])
            ->assertStatus(422)->assertJsonPath('message', 'Grasa mínima: tiene que estar entre 1 y 8 %.');
        $this->postJson('/api/v1/admin/configuracion', ['valores' => ['calidad.ph_min' => 7, 'calidad.ph_max' => 6.9]])
            ->assertStatus(422)->assertJsonPath('message', 'El pH mínimo tiene que ser menor que el máximo.');
        $this->postJson('/api/v1/admin/configuracion', ['valores' => ['empresa.ruc' => '123']])->assertStatus(422);
        $this->postJson('/api/v1/admin/configuracion', ['valores' => ['usuarios.clave' => 'x']])->assertStatus(422);
        $this->assertEquals(3.2, Parametro::valor('calidad.grasa_min'));
    }

    public function test_precio_de_la_leche_rige_desde_su_fecha(): void
    {
        Sanctum::actingAs($this->usuarios['ACOPIADOR']);
        $push = fn ($uuid, $litros, $fecha) => $this->postJson('/api/v1/sync/push', ['operaciones' => [
            $this->opEntrega($uuid, $litros, false, $fecha),
        ]])->assertOk();
        $push('22222222-0000-4000-8000-000000000001', 10, $this->hoy(-1)); // ayer, con el precio de siempre

        Sanctum::actingAs($this->usuarios['ADMIN']);
        // no se puede cambiar el precio de días que ya pasaron
        $this->postJson('/api/v1/admin/configuracion/precio-leche', ['precio' => 2, 'desde' => $this->hoy(-1)])->assertStatus(422);
        $this->postJson('/api/v1/admin/configuracion/precio-leche', ['precio' => 2, 'desde' => $this->hoy()])
            ->assertOk()->assertJsonPath('hoy', 2);
        // uno para más adelante: se ve como programado y se puede quitar
        $this->postJson('/api/v1/admin/configuracion/precio-leche', ['precio' => 2.2, 'desde' => $this->hoy(7)])->assertOk();
        $prog = $this->getJson('/api/v1/admin/configuracion')->json('precio_leche');
        $this->assertEquals(2, $prog['hoy']);
        $this->assertCount(1, $prog['programados']);

        Sanctum::actingAs($this->usuarios['ACOPIADOR']);
        $push('22222222-0000-4000-8000-000000000002', 10, $this->hoy());
        $montos = DB::table('entregas')->orderBy('fecha')->pluck('monto')->map(fn ($m) => (float) $m)->all();
        $this->assertSame([18.0, 20.0], $montos); // ayer a 1,80 · hoy a 2,00
        // la app recibe el precio de hoy
        $this->assertEquals(2, $this->getJson('/api/v1/acopio/semana')->json('precio_litro'));

        Sanctum::actingAs($this->usuarios['ADMIN']);
        $this->postJson("/api/v1/admin/configuracion/precio-leche/{$prog['programados'][0]['id']}/quitar")->assertOk();
        $this->assertCount(0, $this->getJson('/api/v1/admin/configuracion')->json('precio_leche.programados'));
        $vigente = DB::table('precios_leche')->where('vigente_desde', $this->hoy())->value('id');
        $this->postJson("/api/v1/admin/configuracion/precio-leche/$vigente/quitar")->assertStatus(422); // ya rige
    }

    public function test_precio_de_venta_nuevo_desde_hoy(): void
    {
        $queso = DB::table('productos')->insertGetId(['nombre' => 'Queso Paria', 'unidad' => 'quesos de 1 kg',
            'rendimiento_min' => 0.13, 'rendimiento_max' => 0.133, 'vida_util_dias' => 30]);
        DB::table('precios_venta')->insert(['producto_id' => $queso, 'tipo_cliente' => 'MAYORISTA', 'precio' => 21,
            'vigente_desde' => $this->hoy(-10), 'vigente_hasta' => null]);

        $this->postJson('/api/v1/admin/configuracion/precio-venta', ['producto_id' => $queso, 'tipo_cliente' => 'MAYORISTA', 'precio' => 22])
            ->assertOk()->assertJsonPath('antes', 21);
        $this->postJson('/api/v1/admin/configuracion/precio-venta', ['producto_id' => $queso, 'tipo_cliente' => 'DIRECTO', 'precio' => 23])->assertOk();

        $this->assertSame(1, DB::table('precios_venta')->where('producto_id', $queso)->where('tipo_cliente', 'MAYORISTA')->whereNull('vigente_hasta')->count());
        $p = collect($this->getJson('/api/v1/admin/configuracion')->json('precios_venta'))->firstWhere('producto_id', $queso);
        $this->assertEquals(22, $p['precios']['MAYORISTA']);
        $this->assertEquals(23, $p['precios']['DIRECTO']);
        $this->assertNull($p['precios']['PLANTA']);
        // Vender ya usa el precio nuevo
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $prod = collect($this->getJson('/api/v1/compras/ventas-datos')->json('productos'))->firstWhere('id', $queso);
        $this->assertEquals(22, $prod['precios']['MAYORISTA']);
    }
}
