<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** VerificarRol: cada rol solo entra a su sección; ADMIN a todas. */
class PermisosPorRolTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    /** sección => ruta GET de esa sección */
    private const RUTAS = [
        'admin'     => '/api/v1/admin/totales',
        'planta'    => '/api/v1/planta/recetas',
        'compras'   => '/api/v1/compras/inventario',
        'calidad'   => '/api/v1/calidad/pendientes',
        'acopio'    => '/api/v1/acopio/semana',
        'productor' => '/api/v1/productor/comunidad',
    ];

    /** rol => secciones a las que puede entrar */
    private const PERMITIDO = [
        'ACOPIADOR' => ['acopio'],
        'CALIDAD'   => ['calidad'],
        'OPERARIO'  => ['planta'],
        'COMPRAS'   => ['compras'],
        'PRODUCTOR' => ['productor'],
        'ADMIN'     => ['admin', 'planta', 'compras', 'calidad', 'acopio', 'productor'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
    }

    public static function casos(): array
    {
        $casos = [];
        foreach (self::PERMITIDO as $rol => $permitidas) {
            foreach (array_keys(self::RUTAS) as $seccion) {
                $casos["$rol en $seccion"] = [$rol, $seccion, in_array($seccion, $permitidas)];
            }
        }

        return $casos;
    }

    #[DataProvider('casos')]
    public function test_permiso(string $rol, string $seccion, bool $puede): void
    {
        Sanctum::actingAs($this->usuarios[$rol]);
        $status = $this->getJson(self::RUTAS[$seccion])->status();

        if ($puede) {
            // 404 vale: el ADMIN no es productor; lo importante es que no es 403
            $this->assertNotSame(403, $status, "$rol debería poder entrar a $seccion");
        } else {
            $this->assertSame(403, $status, "$rol no debería poder entrar a $seccion");
        }
    }

    public function test_sync_y_yo_abiertos_a_todos_los_roles(): void
    {
        foreach ($this->usuarios as $rol => $u) {
            Sanctum::actingAs($u);
            $this->getJson('/api/v1/yo')->assertOk()->assertJsonPath('rol', $rol);
            $this->getJson('/api/v1/sync/bootstrap')->assertOk();
        }
    }

    public function test_sin_sesion_da_401_en_json(): void
    {
        // sin cabecera Accept: antes esto daba 500 "Route [login] not defined"
        $this->get('/api/v1/admin/totales')
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_mensaje_de_403_en_espanol(): void
    {
        Sanctum::actingAs($this->usuarios['ACOPIADOR']);
        $this->getJson('/api/v1/admin/totales')
            ->assertForbidden()
            ->assertJson(['message' => 'Tu rol no tiene permiso para esta sección.']);
    }
}
