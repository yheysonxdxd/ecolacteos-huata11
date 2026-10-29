<?php

namespace Tests\Feature;

use App\Models\Parametro;
use App\Services\Comprobantes;
use App\Services\NotaVentaPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Boleta (B001) y factura (F001) además de la nota de venta: lo que pide SUNAT, numeración e IGV. */
class BoletaFacturaTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    private const RUC_PLANTA = '20600000005';
    private const RUC_CLIENTE = '20100047218';

    private int $queso;
    private int $mayorista;
    private int $vecino;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        Parametro::updateOrCreate(['clave' => 'empresa.ruc'], ['valor' => self::RUC_PLANTA]);
        $this->queso = DB::table('productos')->insertGetId([
            'nombre' => 'Queso Paria', 'unidad' => 'quesos de 1 kg', 'rendimiento_min' => 0.1, 'rendimiento_max' => 0.1, 'vida_util_dias' => 30,
        ]);
        DB::table('lotes')->insert([
            'uuid' => (string) Str::uuid(), 'codigo' => 'L-1', 'producto_id' => $this->queso, 'litros_leche' => 400,
            'esperado_min' => 60, 'esperado_max' => 60, 'obtenido' => 60, 'estado' => 'TERMINADO',
            'vence_el' => now()->addDays(20)->toDateString(), 'operario_id' => $this->usuarios['ADMIN']->id,
            'iniciado_en' => now()->subDay(), 'cerrado_en' => now()->subDay(),
        ]);
        foreach (['MAYORISTA' => 21, 'DIRECTO' => 23.6] as $tipo => $precio) {
            DB::table('precios_venta')->insert([
                'producto_id' => $this->queso, 'tipo_cliente' => $tipo, 'precio' => $precio, 'vigente_desde' => now()->subMonth()->toDateString(),
            ]);
        }
        $this->mayorista = DB::table('clientes')->insertGetId(['nombre' => 'Comercial Altiplano SAC', 'tipo' => 'MAYORISTA', 'ruc' => self::RUC_CLIENTE, 'activo' => true]);
        $this->vecino = DB::table('clientes')->insertGetId(['nombre' => 'Cliente sin registrar', 'tipo' => 'DIRECTO', 'activo' => true]);
        Sanctum::actingAs($this->usuarios['COMPRAS']);
    }

    private function vender(array $extra, int $cantidad = 5, ?int $cliente = null)
    {
        return $this->postJson('/api/v1/compras/ventas', [
            'cliente_id' => $cliente ?? $this->mayorista, 'items' => [['producto_id' => $this->queso, 'cantidad' => $cantidad]],
        ] + $extra);
    }

    public function test_sin_decir_nada_sigue_siendo_nota_de_venta(): void
    {
        $r = $this->vender([])->assertCreated();
        $this->assertStringStartsWith('NV-', $r->json('numero'));
        $this->assertSame('NOTA', DB::table('ventas')->value('comprobante_tipo'));
    }

    public function test_factura_al_ruc_del_mayorista_con_su_numero_y_el_igv(): void
    {
        $r = $this->vender(['comprobante' => 'FACTURA'])->assertCreated();
        $this->assertSame('F001-00000001', $r->json('numero'));
        $this->assertSame('F001-00000002', $this->vender(['comprobante' => 'FACTURA'])->json('numero')); // correlativo

        $d = app(NotaVentaPdf::class)->datos($r->json('id'));
        $this->assertSame('FACTURA ELECTRÓNICA', $d['titulo']);
        $this->assertSame(['documento' => self::RUC_CLIENTE, 'tipo_doc' => 'RUC', 'nombre' => 'Comercial Altiplano SAC', 'direccion' => null], $d['adquirente']);
        // 5 × 21 = 105 con IGV: 88,98 + 16,02
        $this->assertEquals([105, 88.98, 16.02], [$d['total'], $d['gravada'], $d['igv']]);
        $this->assertStringStartsWith('%PDF', app(NotaVentaPdf::class)->pdf($d)->output());
    }

    public function test_la_factura_pide_un_ruc_valido(): void
    {
        $this->vender(['comprobante' => 'FACTURA'], 5, $this->vecino)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'RUC'));
        $this->vender(['comprobante' => 'FACTURA', 'adquirente' => ['documento' => '20100047219', 'nombre' => 'Otra SAC']], 5, $this->vecino)
            ->assertStatus(422)->assertJsonPath('message', 'Ese RUC no es válido: revisa los 11 dígitos.');
        $this->vender(['comprobante' => 'FACTURA', 'adquirente' => ['documento' => '45678912', 'nombre' => 'Juan Quispe']], 5, $this->vecino)
            ->assertStatus(422);
        $this->assertSame(0, DB::table('ventas')->count()); // nada a medias
    }

    public function test_boleta_chica_sin_datos_y_series_separadas(): void
    {
        $this->assertSame('B001-00000001', $this->vender(['comprobante' => 'BOLETA'], 2, $this->vecino)->assertCreated()->json('numero'));
        $this->vender(['comprobante' => 'FACTURA']);
        $r = $this->vender(['comprobante' => 'BOLETA', 'adquirente' => ['documento' => '45678912', 'nombre' => 'Juan Quispe']], 2, $this->vecino);
        $this->assertSame('B001-00000002', $r->json('numero')); // la factura no corre el número de la boleta

        $d = app(NotaVentaPdf::class)->datos($r->json('id'));
        $this->assertSame('DNI', $d['adquirente']['tipo_doc']);
        $this->assertSame('Juan Quispe', $d['adquirente']['nombre']);
    }

    public function test_boleta_de_mas_de_700_pide_dni_y_nombre(): void
    {
        // 30 × 23,60 = 708 > 700
        $this->vender(['comprobante' => 'BOLETA'], 30, $this->vecino)->assertStatus(422)
            ->assertJsonPath('message', 'La boleta pasa de S/ 700: SUNAT pide el DNI (o RUC) y el nombre de quien compra.');
        $this->vender(['comprobante' => 'BOLETA', 'adquirente' => ['documento' => '45678912', 'nombre' => 'Juan Quispe']], 30, $this->vecino)->assertCreated();
    }

    public function test_sin_ruc_de_la_planta_no_hay_boletas_ni_facturas(): void
    {
        Parametro::updateOrCreate(['clave' => 'empresa.ruc'], ['valor' => '']);
        $this->vender(['comprobante' => 'BOLETA'], 2)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'RUC de la planta'));
        $this->vender([], 2)->assertCreated(); // la nota de venta sí
    }

    public function test_ruc_con_digito_verificador(): void
    {
        $this->assertTrue(Comprobantes::rucValido('20131312955')); // SUNAT
        $this->assertTrue(Comprobantes::rucValido('20100047218'));
        $this->assertFalse(Comprobantes::rucValido('20131312956'));
        $this->assertFalse(Comprobantes::rucValido('30131312955')); // no empieza en 10, 15, 16, 17 o 20
        $this->assertFalse(Comprobantes::rucValido('2013131295'));
    }
}
