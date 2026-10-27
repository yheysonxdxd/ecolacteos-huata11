<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** GET /inicio: cada rol recibe sus cifras para la pantalla de Inicio de la app. */
class InicioRolesTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    public function test_cada_rol_trae_sus_cifras(): void
    {
        $this->crearDatosHuata();
        $claves = [
            'OPERARIO' => ['leche_tanque', 'lotes_en_proceso', 'cerrados_hoy', 'insumos_bajos'],
            'COMPRAS' => ['insumos_bajos', 'por_llegar', 'vendido_hoy', 'notas_hoy'],
            'CALIDAD' => ['por_analizar', 'analizados_hoy', 'rechazados_hoy', 'por_capacitar'],
        ];
        foreach ($claves as $rol => $campos) {
            Sanctum::actingAs($this->usuarios[$rol]);
            $this->getJson('/api/v1/inicio')->assertOk()->assertJsonPath('rol', $rol)->assertJsonStructure(array_merge(['nombre'], $campos));
        }
    }
}
