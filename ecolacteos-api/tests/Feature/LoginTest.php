<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** POST /api/v1/login y el límite de intentos (LimitarLogin). */
class LoginTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
    }

    private function login(string $dni, string $clave)
    {
        return $this->postJson('/api/v1/login', ['dni' => $dni, 'password' => $clave, 'dispositivo' => 'prueba']);
    }

    public function test_login_correcto_devuelve_token_y_rol(): void
    {
        $this->login('40000001', 'huata2026')
            ->assertOk()
            ->assertJsonPath('rol', 'ACOPIADOR')
            ->assertJsonStructure(['token', 'usuario' => ['id', 'nombre', 'dni'], 'rol']);
    }

    public function test_clave_incorrecta_da_401(): void
    {
        $this->login('40000001', 'mala')->assertStatus(401);
    }

    public function test_usuario_inactivo_no_entra(): void
    {
        $this->usuarios['ACOPIADOR']->update(['activo' => false]);
        $this->login('40000001', 'huata2026')->assertStatus(401);
    }

    public function test_cinco_fallos_bloquean_el_sexto_intento(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('40000001', 'mala')->assertStatus(401);
        }
        // bloqueado aunque ahora la clave sea correcta
        $r = $this->login('40000001', 'huata2026')->assertStatus(429);
        $this->assertStringContainsString('Demasiados intentos', $r->json('message'));
        $this->assertNotNull($r->headers->get('Retry-After'));
    }

    public function test_el_bloqueo_es_por_dni(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('40000001', 'mala');
        }
        $this->login('40000002', 'huata2026')->assertOk();
    }

    public function test_login_correcto_reinicia_el_contador(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->login('40000001', 'mala');
        }
        $this->login('40000001', 'huata2026')->assertOk();
        for ($i = 0; $i < 4; $i++) {
            $this->login('40000001', 'mala')->assertStatus(401);
        }
    }
}
