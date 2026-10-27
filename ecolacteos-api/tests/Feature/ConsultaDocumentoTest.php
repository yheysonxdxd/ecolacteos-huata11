<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** GET /compras/documento/{dni|ruc}: el nombre al escribir el DNI o RUC de un cliente (App\Services\ConsultaDocumento). */
class ConsultaDocumentoTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        config(['services.decolecta.token' => null]);
    }

    public function test_primero_busca_en_lo_de_la_planta(): void
    {
        $id = DB::table('clientes')->insertGetId(['nombre' => 'Comercial Altiplano', 'tipo' => 'MAYORISTA', 'ruc' => '20123456789', 'activo' => true]);
        $this->getJson('/api/v1/compras/documento/20123456789')->assertOk()
            ->assertJsonPath('fuente', 'cliente')->assertJsonPath('cliente_id', $id)->assertJsonPath('nombre', 'Comercial Altiplano');

        $dni = DB::table('proveedores')->where('id', $this->proveedorId)->value('dni');
        $this->getJson("/api/v1/compras/documento/$dni")->assertOk()->assertJsonPath('fuente', 'proveedor');
        Http::assertNothingSent();
    }

    public function test_sin_token_avisa_y_no_pregunta_afuera(): void
    {
        Http::fake();
        $this->getJson('/api/v1/compras/documento/46027897')->assertOk()
            ->assertJsonPath('nombre', null)->assertJsonPath('sin_token', true);
        Http::assertNothingSent();
    }

    public function test_con_token_trae_el_nombre_de_reniec_bien_escrito_y_lo_guarda(): void
    {
        config(['services.decolecta.token' => 'clave']);
        Http::fake(['api.decolecta.com/v1/reniec/dni*' => Http::response([
            'first_name' => 'ROXANA KARINA', 'first_last_name' => 'DELGADO', 'second_last_name' => 'HUAMANI',
            'full_name' => 'DELGADO HUAMANI ROXANA KARINA', 'document_number' => '46027897',
        ])]);

        $this->getJson('/api/v1/compras/documento/46027897')->assertOk()
            ->assertJsonPath('nombre', 'Roxana Karina Delgado Huamani')->assertJsonPath('fuente', 'reniec');
        $this->getJson('/api/v1/compras/documento/46027897')->assertOk(); // la segunda vez no se vuelve a preguntar
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer clave') && str_contains($r->url(), 'numero=46027897'));
    }

    public function test_ruc_de_sunat_y_documento_que_no_existe(): void
    {
        config(['services.decolecta.token' => 'clave']);
        Http::fake([
            'api.decolecta.com/v1/sunat/ruc*' => Http::response(['razon_social' => 'REXTIE S.A.C.', 'estado' => 'ACTIVO', 'direccion' => 'LIMA']),
            'api.decolecta.com/v1/reniec/dni*' => Http::response(['error' => 'Invalid request'], 400),
        ]);
        $this->getJson('/api/v1/compras/documento/20601030013')->assertOk()->assertJsonPath('nombre', 'REXTIE S.A.C.')->assertJsonPath('fuente', 'sunat');
        $this->getJson('/api/v1/compras/documento/11111111')->assertOk()->assertJsonPath('nombre', null)
            ->assertJsonPath('error', 'No se encontró ese DNI en RENIEC. Escribe el nombre a mano.');
        $this->getJson('/api/v1/compras/documento/123')->assertNotFound(); // solo 8 u 11 dígitos
    }
}
