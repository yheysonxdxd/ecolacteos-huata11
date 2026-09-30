<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Altas del Padrón desde la app del admin (AdminPadronController). */
class AdminPadronTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
        Sanctum::actingAs($this->usuarios['ADMIN']);
    }

    private function proveedor(array $extra = [])
    {
        return $this->postJson('/api/v1/admin/proveedores', array_merge([
            'nombre' => 'Juan Pérez Mamani', 'dni' => '41234567', 'comunidad_id' => $this->comunidad['Kapi'],
        ], $extra));
    }

    public function test_crea_proveedor_con_el_vehiculo_de_su_comunidad(): void
    {
        $id = $this->proveedor(['promedio_litros' => 15])->assertCreated()->json('id');

        $p = DB::table('proveedores')->find($id);
        $this->assertSame($this->vehiculo['M-01'], (int) $p->vehiculo_id); // M-01 pasa por Kapi
        $this->assertEquals(15, $p->promedio_litros);
        $this->assertNull($p->user_id);
        $this->assertSame($this->usuarios['ADMIN']->id, (int) $p->registrado_por);
    }

    public function test_proveedor_con_acceso_crea_su_usuario_productor(): void
    {
        $id = $this->proveedor(['con_acceso' => true, 'clave' => 'leche123'])->assertCreated()->json('id');

        $u = User::where('dni', '41234567')->sole();
        $this->assertSame('PRODUCTOR', $u->rol);
        $this->assertSame($u->id, (int) DB::table('proveedores')->find($id)->user_id);

        // y puede entrar a la app con esa clave
        $this->postJson('/api/v1/login', ['dni' => '41234567', 'password' => 'leche123'])
            ->assertOk()->assertJsonPath('rol', 'PRODUCTOR');
    }

    public function test_proveedor_con_acceso_pide_clave(): void
    {
        $this->proveedor(['con_acceso' => true])->assertStatus(422)
            ->assertJsonPath('message', 'Falta la clave para que el productor entre a la app.');
    }

    public function test_no_repite_dni_de_proveedor(): void
    {
        $this->proveedor(['dni' => '40112233'])->assertStatus(422)
            ->assertJsonPath('message', 'Ya hay alguien registrado con ese DNI.');
    }

    public function test_crea_trabajador_que_puede_entrar(): void
    {
        $this->postJson('/api/v1/admin/trabajadores', [
            'nombre' => 'Nélida Pari Quispe', 'dni' => '42345678', 'rol' => 'ACOPIADOR', 'clave' => 'acopio2026',
        ])->assertCreated();

        $this->postJson('/api/v1/login', ['dni' => '42345678', 'password' => 'acopio2026'])
            ->assertOk()->assertJsonPath('rol', 'ACOPIADOR');
    }

    public function test_trabajador_no_se_crea_como_productor(): void
    {
        $this->postJson('/api/v1/admin/trabajadores', [
            'nombre' => 'Alguien Más', 'dni' => '42345678', 'rol' => 'PRODUCTOR', 'clave' => 'clave123',
        ])->assertStatus(422);
    }

    public function test_desactivar_saca_al_trabajador_de_la_app(): void
    {
        $acop = $this->usuarios['ACOPIADOR'];
        $token = $acop->createToken('app')->plainTextToken;

        $this->postJson("/api/v1/admin/trabajadores/{$acop->id}/activo", ['activo' => false])
            ->assertOk()->assertJsonPath('activo', false);

        $this->assertSame(0, $acop->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/yo')->assertStatus(401);
        $this->postJson('/api/v1/login', ['dni' => $acop->dni, 'password' => 'huata2026'])->assertStatus(401);
    }

    public function test_reactivar_le_deja_entrar_de_nuevo(): void
    {
        $acop = $this->usuarios['ACOPIADOR'];
        $acop->update(['activo' => false]);

        $this->postJson("/api/v1/admin/trabajadores/{$acop->id}/activo", ['activo' => true])->assertOk();
        $this->postJson('/api/v1/login', ['dni' => $acop->dni, 'password' => 'huata2026'])->assertOk();
    }

    public function test_el_admin_no_se_puede_desactivar_a_si_mismo(): void
    {
        $this->postJson("/api/v1/admin/trabajadores/{$this->usuarios['ADMIN']->id}/activo", ['activo' => false])
            ->assertStatus(422);
    }

    public function test_solo_el_admin_hace_altas(): void
    {
        Sanctum::actingAs($this->usuarios['ACOPIADOR']);
        $this->proveedor()->assertForbidden();
        $this->postJson('/api/v1/admin/trabajadores', [])->assertForbidden();
    }
}
