<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Boleta y factura (SUNAT) además de la nota de venta. Van en la fila de la
 * venta que lleva la nota (la primera): tipo, serie y número correlativo, y a
 * quién se emitió. Las ventas de antes quedan como nota de venta.
 *
 * Series según SUNAT: 4 caracteres, la boleta empieza con B y la factura con F
 * (R.S. 123-2022/SUNAT, anexo 1); número de hasta 8 cifras desde el 1.
 */
return new class extends Migration
{
    private const DATOS = [
        'comprobante.serie_boleta'  => ['B001', 'Serie de las boletas (4 caracteres, empieza con B)'],
        'comprobante.serie_factura' => ['F001', 'Serie de las facturas (4 caracteres, empieza con F)'],
        'impuesto.igv_pct'          => ['18', 'IGV en % (16 % IGV + 2 % IPM). Los precios de venta ya lo incluyen'],
    ];

    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $t) {
            $t->string('comprobante_tipo', 8)->default('NOTA')->after('nota_id'); // NOTA, BOLETA o FACTURA
            $t->string('comprobante_serie', 4)->nullable()->after('comprobante_tipo');
            $t->unsignedInteger('comprobante_numero')->nullable()->after('comprobante_serie');
            $t->string('adquirente_documento', 11)->nullable()->after('comprobante_numero');
            $t->string('adquirente_nombre', 150)->nullable()->after('adquirente_documento');
            $t->string('adquirente_direccion', 200)->nullable()->after('adquirente_nombre');
            $t->unique(['comprobante_serie', 'comprobante_numero']);
        });
        foreach (self::DATOS as $clave => [$valor, $descripcion]) {
            DB::table('parametros')->insertOrIgnore([
                'clave' => $clave, 'valor' => $valor, 'descripcion' => $descripcion,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $t) {
            $t->dropUnique(['comprobante_serie', 'comprobante_numero']);
            $t->dropColumn(['comprobante_tipo', 'comprobante_serie', 'comprobante_numero',
                'adquirente_documento', 'adquirente_nombre', 'adquirente_direccion']);
        });
        DB::table('parametros')->whereIn('clave', array_keys(self::DATOS))->delete();
    }
};
