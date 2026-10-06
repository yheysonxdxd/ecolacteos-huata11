<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Compras de insumos con vendedor (DNI/RUC), varios insumos y comprobante con foto. */
class ComprasComprobanteTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    /** PNG de 1×1 en base64. */
    private const FOTO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private int $sal;
    private int $cuajo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        $this->sal = DB::table('insumos')->insertGetId(['nombre' => 'Sal industrial', 'unidad' => 'kg', 'stock' => 5, 'stock_minimo' => 15]);
        $this->cuajo = DB::table('insumos')->insertGetId(['nombre' => 'Cuajo líquido', 'unidad' => 'L', 'stock' => 0.2, 'stock_minimo' => 0.5]);
        Sanctum::actingAs($this->usuarios['COMPRAS']);
    }

    private function vendedor(string $tipo, string $doc, string $nombre): int
    {
        return $this->postJson('/api/v1/compras/vendedores', ['tipo_documento' => $tipo, 'documento' => $doc, 'nombre' => $nombre])
            ->assertCreated()->json('id');
    }

    /** Orden con sal (50 kg) y cuajo (1 L); devuelve [id, ids de sus líneas por insumo]. */
    private function orden(int $vendedor): array
    {
        $id = $this->postJson('/api/v1/compras/ordenes', ['vendedor_id' => $vendedor, 'items' => [
            ['insumo_id' => $this->sal, 'cantidad' => 50], ['insumo_id' => $this->cuajo, 'cantidad' => 1],
        ]])->assertCreated()->json('id');

        return [$id, DB::table('orden_compra_items')->where('orden_compra_id', $id)->pluck('id', 'insumo_id')];
    }

    private function recibir(int $id, $items, array $comp, float $sal = 100, float $cuajo = 136)
    {
        return $this->postJson("/api/v1/compras/ordenes/$id/recibir", $comp + [
            'comprobante_fecha' => now('America/Lima')->toDateString(),
            'items' => [['id' => $items[$this->sal], 'costo_real' => $sal], ['id' => $items[$this->cuajo], 'costo_real' => $cuajo]],
        ]);
    }

    public function test_vendedores_con_dni_o_ruc_validos(): void
    {
        $this->vendedor('RUC', '20448811223', 'Comercial Puno SAC');
        $this->vendedor('DNI', '41234567', 'Rosa Mamani');

        $this->postJson('/api/v1/compras/vendedores', ['tipo_documento' => 'RUC', 'documento' => '12345678901', 'nombre' => 'Mal RUC'])
            ->assertStatus(422)->assertJsonPath('message', 'El RUC debe tener 11 dígitos y empezar en 10, 15, 16, 17 o 20.');
        $this->postJson('/api/v1/compras/vendedores', ['tipo_documento' => 'DNI', 'documento' => '4123', 'nombre' => 'Corto'])
            ->assertStatus(422);
        $this->postJson('/api/v1/compras/vendedores', ['tipo_documento' => 'RUC', 'documento' => '20448811223', 'nombre' => 'Repetido'])
            ->assertStatus(422)->assertJsonValidationErrors('documento');

        $this->assertCount(2, $this->getJson('/api/v1/compras/vendedores')->assertOk()->json('vendedores'));
    }

    public function test_factura_con_varios_insumos_sube_stock_y_separa_igv(): void
    {
        [$id, $items] = $this->orden($this->vendedor('RUC', '20512233445', 'Lácteos Insumos Arequipa SRL'));

        $this->recibir($id, $items, ['comprobante_tipo' => 'FACTURA', 'comprobante_serie' => 'f001', 'comprobante_numero' => '000123'])
            ->assertOk()->assertJsonPath('total', 236)->assertJsonPath('comprobante', 'Factura F001-123');

        $o = DB::table('ordenes_compra')->find($id);
        $this->assertSame('RECIBIDA', $o->estado);
        $this->assertEquals(200, $o->subtotal);
        $this->assertEquals(36, $o->igv);
        $this->assertEquals(55, DB::table('insumos')->where('id', $this->sal)->value('stock'));
        $this->assertEquals(1.2, DB::table('insumos')->where('id', $this->cuajo)->value('stock'));

        // el costo del insumo sale de lo pagado, no de lo estimado
        $inv = collect($this->getJson('/api/v1/compras/inventario')->json('insumos'))->keyBy('id');
        $this->assertEquals(2, $inv[$this->sal]['costo']);

        // el kardex dice de quién y con qué comprobante
        $k = $this->getJson("/api/v1/compras/kardex?tipo=insumo&id={$this->sal}")->assertOk()->json('filas');
        $compra = collect($k)->firstWhere('tipo', 'COMPRA');
        $this->assertStringContainsString('Lácteos Insumos Arequipa SRL (Factura F001-123)', $compra['texto']);
        $this->assertEquals(2, $compra['entrada']['costo']);

        // la lista muestra el comprobante
        $lista = collect($this->getJson('/api/v1/compras/ordenes')->json('ordenes'))->firstWhere('id', $id);
        $this->assertCount(2, $lista['items']);
        $this->assertSame('Factura F001-123', $lista['comprobante']);
    }

    public function test_factura_solo_con_ruc_y_sin_repetir_comprobante(): void
    {
        $persona = $this->vendedor('DNI', '41234567', 'Rosa Mamani');
        [$id, $items] = $this->orden($persona);
        $this->recibir($id, $items, ['comprobante_tipo' => 'FACTURA', 'comprobante_serie' => 'F001', 'comprobante_numero' => '5'])
            ->assertStatus(422);
        $this->assertSame('EMITIDA', DB::table('ordenes_compra')->where('id', $id)->value('estado'));

        // boleta sin número: no
        $this->recibir($id, $items, ['comprobante_tipo' => 'BOLETA', 'comprobante_serie' => 'B001'])
            ->assertStatus(422)->assertJsonValidationErrors('comprobante_numero');
        $this->recibir($id, $items, ['comprobante_tipo' => 'BOLETA', 'comprobante_serie' => 'B001', 'comprobante_numero' => '44'])->assertOk();

        // la misma boleta del mismo vendedor en otra orden: no
        [$id2, $items2] = $this->orden($persona);
        $this->recibir($id2, $items2, ['comprobante_tipo' => 'BOLETA', 'comprobante_serie' => 'b001', 'comprobante_numero' => '0044'])
            ->assertStatus(422);
        // recibo simple (compra chica en el mercado): sin serie ni número
        $this->recibir($id2, $items2, ['comprobante_tipo' => 'RECIBO'])->assertOk()->assertJsonPath('comprobante', 'Recibo');

        // ya recibida: no se recibe dos veces
        $this->recibir($id2, $items2, ['comprobante_tipo' => 'RECIBO'])->assertStatus(422);
        $this->assertEquals(105, DB::table('insumos')->where('id', $this->sal)->value('stock'));
    }

    public function test_fecha_futura_y_lineas_incompletas(): void
    {
        [$id, $items] = $this->orden($this->vendedor('RUC', '20448811223', 'Comercial Puno SAC'));
        $this->postJson("/api/v1/compras/ordenes/$id/recibir", [
            'comprobante_tipo' => 'RECIBO', 'comprobante_fecha' => now('America/Lima')->addDays(2)->toDateString(),
            'items' => [['id' => $items[$this->sal], 'costo_real' => 100], ['id' => $items[$this->cuajo], 'costo_real' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('comprobante_fecha');
        $this->postJson("/api/v1/compras/ordenes/$id/recibir", [
            'comprobante_tipo' => 'RECIBO', 'comprobante_fecha' => now('America/Lima')->toDateString(),
            'items' => [['id' => $items[$this->sal], 'costo_real' => 100]],
        ])->assertStatus(422)->assertJsonPath('message', 'Falta lo pagado de algún insumo de la orden.');
    }

    public function test_foto_del_comprobante_con_enlace_firmado(): void
    {
        Storage::fake('local');
        [$id, $items] = $this->orden($this->vendedor('RUC', '20448811223', 'Comercial Puno SAC'));

        $this->getJson("/api/v1/compras/ordenes/$id/foto")->assertNotFound();
        $this->recibir($id, $items, ['comprobante_tipo' => 'FACTURA', 'comprobante_serie' => 'F001', 'comprobante_numero' => '9', 'foto' => self::FOTO])
            ->assertOk();
        $this->assertTrue(collect($this->getJson('/api/v1/compras/ordenes')->json('ordenes'))->firstWhere('id', $id)['tiene_foto']);

        $url = $this->getJson("/api/v1/compras/ordenes/$id/foto")->assertOk()->json('url');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(strtok($url, '?'))->assertForbidden(); // sin firma no

        // cambiar la foto después; algo que no es imagen no se acepta
        $this->postJson("/api/v1/compras/ordenes/$id/foto", ['foto' => base64_encode('no soy una imagen')])
            ->assertStatus(422)->assertJsonValidationErrors('foto');
        $this->postJson("/api/v1/compras/ordenes/$id/foto", ['foto' => self::FOTO])->assertOk();
        $this->assertCount(1, Storage::disk('local')->allFiles('comprobantes'));
    }

    public function test_solo_compras_y_admin(): void
    {
        Sanctum::actingAs($this->usuarios['OPERARIO']);
        $this->getJson('/api/v1/compras/vendedores')->assertForbidden();
        $this->getJson('/api/v1/compras/ordenes')->assertForbidden();
        Sanctum::actingAs($this->usuarios['ADMIN']);
        $this->getJson('/api/v1/compras/ordenes')->assertOk();
    }
}
