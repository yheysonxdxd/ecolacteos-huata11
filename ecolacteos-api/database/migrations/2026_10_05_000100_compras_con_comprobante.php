<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compras de insumos con vendedor y comprobante.
 *
 * - vendedores_insumo: personas (DNI) o empresas (RUC) que venden insumos
 *   (no son los proveedores de leche).
 * - Una orden de compra puede traer varios insumos (orden_compra_items).
 * - Al recibirla se anota el comprobante: factura, boleta o recibo simple,
 *   con serie, número, fecha, lo que de verdad se pagó y una foto opcional.
 *
 * Las órdenes que ya existían pasan a tener una sola línea con su insumo; su
 * vendedor queda solo como texto (proveedor_comercial) porque no tenía DNI/RUC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendedores_insumo', function (Blueprint $t) {
            $t->id();
            $t->enum('tipo_documento', ['DNI', 'RUC']);
            $t->string('documento', 11)->unique();
            $t->string('nombre');                 // nombre o razón social
            $t->string('telefono', 20)->nullable();
            $t->string('direccion')->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });

        Schema::create('orden_compra_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('orden_compra_id')->constrained('ordenes_compra')->cascadeOnDelete();
            $t->foreignId('insumo_id')->constrained();
            $t->decimal('cantidad', 10, 3);
            $t->decimal('costo_estimado', 10, 2);
            $t->decimal('costo_real', 10, 2)->nullable(); // lo pagado por esta línea, al recibir
            $t->timestamps();
        });

        Schema::table('ordenes_compra', function (Blueprint $t) {
            $t->foreignId('vendedor_id')->nullable()->after('codigo')->constrained('vendedores_insumo');
            $t->enum('comprobante_tipo', ['FACTURA', 'BOLETA', 'RECIBO'])->nullable()->after('estado');
            $t->string('comprobante_serie', 4)->nullable()->after('comprobante_tipo');
            $t->string('comprobante_numero', 8)->nullable()->after('comprobante_serie');
            $t->date('comprobante_fecha')->nullable()->after('comprobante_numero');
            $t->decimal('subtotal', 10, 2)->nullable()->after('comprobante_fecha');
            $t->decimal('igv', 10, 2)->nullable()->after('subtotal');
            $t->decimal('total', 10, 2)->nullable()->after('igv');
            $t->string('foto')->nullable()->after('total');
            $t->timestamp('recibida_en')->nullable()->after('foto');
            $t->foreignId('recibida_por')->nullable()->after('recibida_en')->constrained('users');
        });

        // cada orden vieja → una línea con su insumo
        foreach (DB::table('ordenes_compra')->get() as $o) {
            DB::table('orden_compra_items')->insert([
                'orden_compra_id' => $o->id, 'insumo_id' => $o->insumo_id, 'cantidad' => $o->cantidad,
                'costo_estimado' => $o->costo_estimado,
                'costo_real' => $o->estado === 'RECIBIDA' ? $o->costo_estimado : null,
                'created_at' => $o->created_at, 'updated_at' => $o->updated_at,
            ]);
            if ($o->estado === 'RECIBIDA') {
                DB::table('ordenes_compra')->where('id', $o->id)->update(['total' => $o->costo_estimado]);
            }
        }

        Schema::table('ordenes_compra', function (Blueprint $t) {
            $t->dropConstrainedForeignId('insumo_id');
            $t->dropColumn('cantidad');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_compra', function (Blueprint $t) {
            $t->foreignId('insumo_id')->nullable()->after('codigo')->constrained();
            $t->decimal('cantidad', 10, 3)->default(0)->after('insumo_id');
        });
        // vuelve la primera línea de cada orden (las demás se pierden)
        foreach (DB::table('orden_compra_items')->orderBy('id')->get()->groupBy('orden_compra_id') as $id => $items) {
            DB::table('ordenes_compra')->where('id', $id)->update(['insumo_id' => $items[0]->insumo_id, 'cantidad' => $items[0]->cantidad]);
        }
        Schema::table('ordenes_compra', function (Blueprint $t) {
            $t->dropConstrainedForeignId('recibida_por');
            $t->dropConstrainedForeignId('vendedor_id');
            $t->dropColumn(['comprobante_tipo', 'comprobante_serie', 'comprobante_numero', 'comprobante_fecha',
                'subtotal', 'igv', 'total', 'foto', 'recibida_en']);
        });
        Schema::dropIfExists('orden_compra_items');
        Schema::dropIfExists('vendedores_insumo');
    }
};
