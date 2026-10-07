<?php

namespace Tests\Feature;

use App\Services\NotaVentaPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** POST /compras/ventas con items: queso y yogurt al mismo cliente, en una sola nota de venta. */
class VentaVariosTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    private int $queso;
    private int $yogurt;
    private int $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        $this->queso = $this->producto('Queso Paria', 'quesos de 1 kg', 20, 21);
        $this->yogurt = $this->producto('Yogurt natural', 'litros', 30, 8);
        $this->cliente = DB::table('clientes')->insertGetId(['nombre' => 'Comercial Altiplano', 'tipo' => 'MAYORISTA', 'activo' => true]);
        Sanctum::actingAs($this->usuarios['COMPRAS']);
    }

    /** Producto con un lote cerrado de [stock] unidades y su precio mayorista. */
    private function producto(string $nombre, string $unidad, float $stock, float $precio): int
    {
        $id = DB::table('productos')->insertGetId([
            'nombre' => $nombre, 'unidad' => $unidad, 'rendimiento_min' => 0.1, 'rendimiento_max' => 0.1, 'vida_util_dias' => 30,
        ]);
        DB::table('lotes')->insert([
            'uuid' => (string) Str::uuid(), 'codigo' => 'L-'.$id, 'producto_id' => $id, 'litros_leche' => 100,
            'esperado_min' => $stock, 'esperado_max' => $stock, 'obtenido' => $stock, 'estado' => 'TERMINADO',
            'vence_el' => now()->addDays(20)->toDateString(), 'operario_id' => $this->usuarios['ADMIN']->id,
            'iniciado_en' => now()->subDay(), 'cerrado_en' => now()->subDay(),
        ]);
        DB::table('precios_venta')->insert([
            'producto_id' => $id, 'tipo_cliente' => 'MAYORISTA', 'precio' => $precio, 'vigente_desde' => now()->subMonth()->toDateString(),
        ]);

        return $id;
    }

    public function test_vende_dos_productos_en_una_sola_nota(): void
    {
        $r = $this->postJson('/api/v1/compras/ventas', ['cliente_id' => $this->cliente, 'items' => [
            ['producto_id' => $this->queso, 'cantidad' => 5], ['producto_id' => $this->yogurt, 'cantidad' => 10],
        ]])->assertCreated();

        $this->assertEquals(185, $r->json('total')); // 5 × 21 + 10 × 8
        $filas = DB::table('ventas')->orderBy('id')->get();
        $this->assertCount(2, $filas);
        $this->assertSame([$filas[0]->id, $filas[0]->id], [$filas[0]->nota_id, $filas[1]->nota_id]);
        $this->assertSame(NotaVentaPdf::numero($filas[0]->id), $r->json('numero'));

        // la nota lleva los dos productos, desde cualquiera de sus filas
        $d = app(NotaVentaPdf::class)->datos($filas[1]->id);
        $this->assertSame(['Queso Paria', 'Yogurt natural'], array_column($d['lineas'], 'producto'));
        $this->assertEquals(185, $d['total']);
        $this->assertStringContainsString('CIENTO OCHENTA Y CINCO', $d['letras']);
        $this->assertSame($r->json('numero'), $this->getJson("/api/v1/compras/ventas/{$filas[1]->id}/nota")->json('numero'));

        // en la lista de ventas, las dos filas dicen la misma nota
        $mov = collect($this->getJson('/api/v1/compras/movimientos?mes=actual')->json('movimientos'));
        $this->assertSame([$r->json('numero')], $mov->pluck('numero')->unique()->values()->all());
    }

    public function test_si_un_producto_no_alcanza_no_se_vende_nada(): void
    {
        $this->postJson('/api/v1/compras/ventas', ['cliente_id' => $this->cliente, 'items' => [
            ['producto_id' => $this->queso, 'cantidad' => 5], ['producto_id' => $this->yogurt, 'cantidad' => 40],
        ]])->assertStatus(422)->assertJsonFragment(['message' => 'Solo hay 30 de Yogurt natural en stock']);
        $this->assertSame(0, DB::table('ventas')->count());
    }

    public function test_un_producto_sigue_como_antes(): void
    {
        $this->postJson('/api/v1/compras/ventas', ['cliente_id' => $this->cliente, 'producto_id' => $this->queso, 'cantidad' => 2])
            ->assertCreated()->assertJson(['total' => 42, 'precio_unitario' => 21]);
        $this->assertNull(DB::table('ventas')->value('nota_id'));
    }
}
