<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');                 // Queso Paria, Yogurt
            $t->string('unidad', 20);             // quesos de 1 kg, balde 4 L
            $t->decimal('rendimiento_min', 6, 4); // 0.12 quesos por litro
            $t->decimal('rendimiento_max', 6, 4); // 0.13
            $t->unsignedSmallInteger('vida_util_dias');
            $t->timestamps();
        });

        Schema::create('insumos', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->string('unidad', 12);             // kg, L, un
            $t->decimal('stock', 10, 3)->default(0);
            $t->decimal('stock_minimo', 10, 3)->default(0);
            $t->timestamps();
        });

        Schema::create('recetas_insumo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('producto_id')->constrained();
            $t->foreignId('insumo_id')->constrained();
            $t->decimal('cantidad_por_100l', 10, 4);
            $t->timestamps();
        });

        Schema::create('lotes', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('codigo', 16)->unique();   // L-2419
            $t->foreignId('producto_id')->constrained();
            $t->decimal('litros_leche', 9, 2);
            $t->decimal('esperado_min', 9, 2);    // litros * rendimiento_min
            $t->decimal('esperado_max', 9, 2);
            $t->decimal('obtenido', 9, 2)->nullable();
            $t->decimal('desvio_pct', 6, 2)->nullable();
            $t->boolean('fuera_referencia')->default(false);
            $t->enum('estado', ['EN_PROCESO', 'TERMINADO', 'ANULADO'])
              ->default('EN_PROCESO');
            $t->date('vence_el');
            $t->foreignId('operario_id')->constrained('users');
            $t->dateTime('iniciado_en');
            $t->timestamp('cerrado_en')->nullable();
            $t->timestamps();
        });

        // trazabilidad: qué entregas entraron a qué lote
        Schema::create('lote_entrega', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lote_id')->constrained()->cascadeOnDelete();
            $t->foreignId('entrega_id')->constrained();
            $t->decimal('litros', 7, 2);
            $t->timestamps();
        });

        Schema::create('movimientos_insumo', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('insumo_id')->constrained();
            $t->decimal('cantidad', 10, 3);       // negativo = salida
            $t->string('referencia');             // "L-2419 · inicio de lote"
            $t->nullableMorphs('origen');         // lote / orden de compra / venta
            $t->foreignId('registrado_por')->constrained('users');
            $t->timestamps();
        });

        Schema::create('ordenes_compra', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('codigo', 16)->unique();   // OC-119
            $t->foreignId('insumo_id')->constrained();
            $t->decimal('cantidad', 10, 3);
            $t->string('proveedor_comercial');
            $t->decimal('costo_estimado', 10, 2);
            $t->enum('estado', ['EMITIDA', 'RECIBIDA', 'ANULADA'])->default('EMITIDA');
            $t->foreignId('emitida_por')->constrained('users');
            $t->timestamps();
        });

        Schema::create('clientes', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->enum('tipo', ['MAYORISTA', 'DIRECTO', 'PLANTA']);
            $t->string('ruc', 11)->nullable();
            $t->string('telefono', 20)->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });

        Schema::create('precios_venta', function (Blueprint $t) {
            $t->id();
            $t->foreignId('producto_id')->constrained();
            $t->enum('tipo_cliente', ['MAYORISTA', 'DIRECTO', 'PLANTA']);
            $t->decimal('precio', 8, 2);          // 19.00 / 20.00 / 21.00
            $t->date('vigente_desde');
            $t->date('vigente_hasta')->nullable();
            $t->timestamps();
        });

        Schema::create('ventas', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('cliente_id')->constrained();
            $t->foreignId('producto_id')->constrained();
            $t->foreignId('lote_id')->nullable()->constrained();
            $t->decimal('cantidad', 9, 2);
            $t->decimal('precio_unitario', 8, 2);
            $t->decimal('total', 10, 2);
            $t->foreignId('registrada_por')->constrained('users');
            $t->dateTime('vendida_en');
            $t->timestamps();
        });

        Schema::create('pagos_semanales', function (Blueprint $t) {
            $t->id();
            $t->foreignId('proveedor_id')->constrained('proveedores');
            $t->string('semana_iso', 10);         // 2026-W38
            $t->date('inicio');
            $t->date('fin');
            $t->decimal('litros_aceptados', 9, 2);
            $t->decimal('precio_litro', 6, 2);
            $t->decimal('monto', 10, 2);
            $t->json('detalle_dias');             // [{fecha, litros, monto}]
            $t->enum('estado', ['PENDIENTE', 'PAGADO'])->default('PENDIENTE');
            $t->date('pagado_el')->nullable();    // viernes
            $t->timestamps();
            $t->unique(['proveedor_id', 'semana_iso']);
        });

        Schema::create('parametros', function (Blueprint $t) {
            $t->string('clave', 60)->primary();
            $t->string('valor');
            $t->string('descripcion')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'parametros', 'pagos_semanales', 'ventas', 'precios_venta', 'clientes',
            'ordenes_compra', 'movimientos_insumo', 'lote_entrega', 'lotes',
            'recetas_insumo', 'insumos', 'productos',
        ] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};
