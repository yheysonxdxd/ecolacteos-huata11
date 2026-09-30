<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Vencimiento de sesiones, usuarios desactivados y cierre de sesiones por el admin. */
class SesionesTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
    }

    private function token(string $rol): string
    {
        return $this->usuarios[$rol]->createToken('app-ecolacteos')->plainTextToken;
    }

    private function yo(string $token)
    {
        $this->app['auth']->forgetGuards(); // cada petición resuelve el token de nuevo
        return $this->withToken($token)->getJson('/api/v1/yo');
    }

    public function test_token_de_menos_de_30_dias_sirve(): void
    {
        $t = $this->token('ACOPIADOR');
        DB::table('personal_access_tokens')->update(['created_at' => now()->subDays(29)]);

        $this->yo($t)->assertOk();
    }

    public function test_token_de_mas_de_30_dias_vence(): void
    {
        $t = $this->token('ACOPIADOR');
        DB::table('personal_access_tokens')->update(['created_at' => now()->subDays(31)]);

        $this->yo($t)->assertStatus(401);
    }

    public function test_usuario_desactivado_queda_fuera_aunque_tenga_token(): void
    {
        $t = $this->token('ACOPIADOR');
        $this->yo($t)->assertOk();

        $this->usuarios['ACOPIADOR']->update(['activo' => false]);

        $this->yo($t)->assertStatus(401)->assertJsonFragment(['message' => 'Tu usuario está desactivado. Habla con el administrador.']);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_admin_ve_las_sesiones_abiertas(): void
    {
        $this->token('ACOPIADOR');
        $this->token('ACOPIADOR');
        $admin = $this->token('ADMIN');

        $usuarios = collect($this->withToken($admin)->getJson('/api/v1/admin/sesiones')->assertOk()->json('usuarios'))
            ->pluck('sesiones', 'rol');
        $this->assertEquals(2, $usuarios['ACOPIADOR']);
        $this->assertEquals(1, $usuarios['ADMIN']);
    }

    public function test_admin_cierra_las_sesiones_de_un_trabajador(): void
    {
        $t1 = $this->token('ACOPIADOR');
        $t2 = $this->token('ACOPIADOR');
        $admin = $this->token('ADMIN');

        $this->withToken($admin)
            ->postJson("/api/v1/admin/usuarios/{$this->usuarios['ACOPIADOR']->id}/cerrar-sesiones")
            ->assertOk()->assertJson(['cerradas' => 2, 'propia' => false]);

        $this->yo($t1)->assertStatus(401);
        $this->yo($t2)->assertStatus(401);
        $this->yo($admin)->assertOk();
    }

    public function test_solo_el_admin_puede_cerrar_sesiones(): void
    {
        $acop = $this->token('ACOPIADOR');
        $this->withToken($acop)
            ->postJson("/api/v1/admin/usuarios/{$this->usuarios['ADMIN']->id}/cerrar-sesiones")
            ->assertForbidden();
        $this->withToken($acop)->getJson('/api/v1/admin/sesiones')->assertForbidden();
    }
}
