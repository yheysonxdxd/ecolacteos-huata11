<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreaDatosHuata;
use Tests\TestCase;

/** Descargas en Excel de la web de oficina: archivo .xlsx de verdad, con sus datos y solo para quien corresponde. */
class ExcelTest extends TestCase
{
    use RefreshDatabase, CreaDatosHuata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearDatosHuata();
    }

    /** Texto de la hoja (celdas en línea) para buscar valores sin abrir Excel. */
    private function hoja(\Illuminate\Testing\TestResponse $r): string
    {
        $r->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $archivo = tempnam(sys_get_temp_dir(), 'prueba');
        file_put_contents($archivo, file_get_contents($r->baseResponse->getFile()->getPathname()));
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($archivo) === true, 'no es un .xlsx');
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml') . $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        unlink($archivo);

        return $xml;
    }

    public function test_entregas_del_mes_con_sus_litros_y_total(): void
    {
        $mes = now('America/Lima')->format('Y-m');
        foreach ([[$mes . '-01', 40.5], [$mes . '-02', 30]] as [$fecha, $litros]) {
            DB::table('entregas')->insert([
                'uuid' => (string) Str::uuid(), 'proveedor_id' => $this->proveedorId,
                'vehiculo_id' => DB::table('proveedores')->where('id', $this->proveedorId)->value('vehiculo_id'),
                'fecha' => $fecha, 'litros' => $litros, 'registrado_por' => $this->usuarios['ACOPIADOR']->id,
                'registrado_en' => $fecha . ' 07:30:00', 'estado_calidad' => 'ACEPTADO', 'precio_litro' => 1.8, 'monto' => $litros * 1.8,
            ]);
        }
        Sanctum::actingAs($this->usuarios['ADMIN']);

        $r = $this->get("/api/v1/admin/excel/entregas?mes=$mes");
        $xml = $this->hoja($r);

        $this->assertStringContainsString("entregas_$mes.xlsx", $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('<v>40.5</v>', $xml);  // número, no texto: se puede sumar
        $this->assertStringContainsString('<v>70.5</v>', $xml);  // fila de totales
        $this->assertStringContainsString('TOTAL', $xml);
    }

    public function test_cada_descarga_responde_un_excel(): void
    {
        Sanctum::actingAs($this->usuarios['ADMIN']);
        foreach (['admin/excel/padron', 'admin/excel/pagos', 'calidad/excel/analisis?mes=todo',
            'compras/excel/ventas?mes=todo', 'compras/excel/compras?mes=todo'] as $ruta) {
            $this->assertStringContainsString('<sheetData>', $this->hoja($this->get("/api/v1/$ruta")), $ruta);
        }
    }

    public function test_compras_no_baja_los_pagos_a_productores(): void
    {
        Sanctum::actingAs($this->usuarios['COMPRAS']);
        $this->getJson('/api/v1/admin/excel/pagos')->assertForbidden();
        $this->assertStringContainsString('Ventas', $this->hoja($this->get('/api/v1/compras/excel/ventas')));
    }
}
