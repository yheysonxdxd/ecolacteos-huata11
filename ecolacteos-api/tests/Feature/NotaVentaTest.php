<?php

namespace Tests\Feature;

use App\Support\NumeroALetras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Nota de venta en PDF: enlace firmado para Compras/Admin y el PDF. */
class NotaVentaTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    private int $venta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        $queso = DB::table('productos')->insertGetId([
            'nombre' => 'Queso Paria', 'unidad' => 'quesos de 1 kg', 'rendimiento_min' => 0.12, 'rendimiento_max' => 0.13, 'vida_util_dias' => 45,
        ]);
        $cliente = DB::table('clientes')->insertGetId(['nombre' => 'Comercial Altiplano', 'tipo' => 'MAYORISTA', 'ruc' => '20601122334']);
        $this->venta = DB::table('ventas')->insertGetId([
            'uuid' => (string) Str::uuid(), 'cliente_id' => $cliente, 'producto_id' => $queso,
            'cantidad' => 5, 'precio_unitario' => 21, 'total' => 105,
            'registrada_por' => $this->usuarios['COMPRAS']->id, 'vendida_en' => now(),
        ]);
    }

    private function enlace(): string
    {
        return $this->getJson("/api/v1/compras/ventas/{$this->venta}/nota")->assertOk()->json('url');
    }

    public function test_compras_obtiene_el_enlace_y_el_pdf(): void
    {
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $url = $this->enlace();

        $this->assertStringStartsWith("/api/v1/notas-venta/{$this->venta}.pdf?", $url); // relativo: el celular pone su IP
        $r = $this->get($url)->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
    }

    public function test_el_admin_tambien_puede_imprimirla(): void
    {
        Sanctum::actingAs($this->usuarios['ADMIN']);
        $this->get($this->enlace())->assertOk();
    }

    public function test_sin_firma_o_con_firma_alterada_no_se_ve(): void
    {
        $this->get("/api/v1/notas-venta/{$this->venta}.pdf")->assertForbidden();

        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $url = $this->enlace();
        $otra = str_replace("/{$this->venta}.pdf", '/999.pdf', $url); // no sirve para otra venta
        $this->get($otra)->assertForbidden();
    }

    public function test_el_enlace_vence_a_los_30_minutos(): void
    {
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $url = $this->enlace();
        $this->travel(31)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_otros_roles_no_piden_notas(): void
    {
        Sanctum::actingAs($this->usuarios['ACOPIADOR']);
        $this->getJson("/api/v1/compras/ventas/{$this->venta}/nota")->assertForbidden();
    }

    public function test_numero_correlativo(): void
    {
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $this->getJson("/api/v1/compras/ventas/{$this->venta}/nota")
            ->assertJsonPath('numero', 'NV-' . str_pad((string) $this->venta, 6, '0', STR_PAD_LEFT));
    }

    // --- envío por correo (opcional) ---

    public function test_manda_la_nota_por_correo_con_el_pdf(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        Sanctum::actingAs($this->usuarios['COMPRAS']);

        $this->postJson("/api/v1/compras/ventas/{$this->venta}/enviar-nota", ['correo' => 'compras@altiplano.pe'])
            ->assertOk()->assertJson(['enviado' => true]);

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\NotaVentaMail::class, function ($m) {
            return $m->hasTo('compras@altiplano.pe')
                && str_contains($m->envelope()->subject, 'NV-')
                && count($m->attachments()) === 1;
        });
        // es mayorista: se le guarda el correo para la próxima venta
        $this->assertSame('compras@altiplano.pe', DB::table('clientes')->where('nombre', 'Comercial Altiplano')->value('email'));
    }

    public function test_al_cliente_generico_no_se_le_guarda_el_correo(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $vecino = DB::table('clientes')->where('nombre', 'Vecino de Huata')->value('id'); // lo crea la migración
        DB::table('ventas')->where('id', $this->venta)->update(['cliente_id' => $vecino]);

        $this->postJson("/api/v1/compras/ventas/{$this->venta}/enviar-nota", ['correo' => 'vecino@gmail.com'])->assertOk();
        $this->assertNull(DB::table('clientes')->where('id', $vecino)->value('email'));
    }

    public function test_correo_invalido(): void
    {
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $this->postJson("/api/v1/compras/ventas/{$this->venta}/enviar-nota", ['correo' => 'no-es-correo'])
            ->assertStatus(422)->assertJsonPath('message', 'Ese correo no es válido.');
    }

    private function prepararVenta(): array
    {
        $queso = DB::table('productos')->value('id');
        DB::table('precios_venta')->insert(['producto_id' => $queso, 'tipo_cliente' => 'MAYORISTA', 'precio' => 21, 'vigente_desde' => now()->toDateString()]);
        DB::table('lotes')->insert([
            'uuid' => (string) Str::uuid(), 'codigo' => 'L-9', 'producto_id' => $queso, 'litros_leche' => 100,
            'esperado_min' => 12, 'esperado_max' => 13, 'obtenido' => 50, 'estado' => 'TERMINADO',
            'vence_el' => now()->addDays(30)->toDateString(), 'operario_id' => $this->usuarios['OPERARIO']->id, 'iniciado_en' => now(),
        ]);

        $mayorista = DB::table('clientes')->where('nombre', 'Comercial Altiplano')->value('id');

        return ['cliente_id' => $mayorista, 'producto_id' => $queso, 'cantidad' => 2];
    }

    public function test_vender_con_correo_manda_la_nota(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        Sanctum::actingAs($this->usuarios['COMPRAS']);

        $this->postJson('/api/v1/compras/ventas', $this->prepararVenta() + ['correo' => 'compras@altiplano.pe'])
            ->assertCreated()->assertJson(['correo_enviado' => true, 'correo_error' => null]);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\NotaVentaMail::class);
    }

    public function test_vender_sin_correo_no_manda_nada(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        Sanctum::actingAs($this->usuarios['COMPRAS']);

        $this->postJson('/api/v1/compras/ventas', $this->prepararVenta())->assertCreated()->assertJson(['correo_enviado' => false]);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function test_si_el_correo_falla_la_venta_igual_se_guarda(): void
    {
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]); // nadie escucha

        $r = $this->postJson('/api/v1/compras/ventas', $this->prepararVenta() + ['correo' => 'compras@altiplano.pe'])
            ->assertCreated()->assertJson(['correo_enviado' => false]);
        $this->assertStringContainsString('No se pudo enviar el correo', $r->json('correo_error'));
        $this->assertSame(2, DB::table('ventas')->count()); // la de setUp + esta
    }

    public function test_total_en_letras(): void
    {
        $this->assertSame('CIENTO CINCO CON 00/100 SOLES', NumeroALetras::soles(105));
        $this->assertSame('CIEN CON 50/100 SOLES', NumeroALetras::soles(100.5));
        $this->assertSame('VEINTIUNO CON 00/100 SOLES', NumeroALetras::soles(21));
        $this->assertSame('SETECIENTOS TRES CON 00/100 SOLES', NumeroALetras::soles(703));
        $this->assertSame('MIL CIENTO SESENTA Y SIETE CON 00/100 SOLES', NumeroALetras::soles(1167));
        $this->assertSame('VEINTIÚN MIL CON 00/100 SOLES', NumeroALetras::soles(21000));
        $this->assertSame('CERO CON 90/100 SOLES', NumeroALetras::soles(0.9));
    }
}
