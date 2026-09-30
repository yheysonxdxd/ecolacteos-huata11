<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();            // idempotencia del outbox
            $t->uuid('reemplaza_uuid')->nullable(); // corrección de otra entrega
            $t->foreignId('proveedor_id')->constrained('proveedores');
            $t->foreignId('vehiculo_id')->constrained();
            $t->date('fecha');
            $t->decimal('litros', 7, 2)->default(0);
            $t->boolean('ausente')->default(false); // "hoy no entregó"
            $t->boolean('confirmada_proveedor')->default(false);
            $t->timestamp('confirmada_en')->nullable();
            $t->enum('estado_calidad', ['PENDIENTE', 'ACEPTADO', 'RECHAZADO'])
              ->default('PENDIENTE');
            $t->decimal('precio_litro', 6, 2)->nullable(); // congelado al aceptar
            $t->decimal('monto', 9, 2)->nullable();
            $t->foreignId('registrado_por')->constrained('users');
            $t->dateTime('registrado_en');        // hora real en el teléfono
            $t->timestamp('recibido_en')->nullable(); // llegada al servidor
            $t->timestamps();
            $t->unique(['proveedor_id', 'fecha'], 'entrega_unica_por_dia');
            $t->index(['fecha', 'vehiculo_id']);
        });

        Schema::create('analisis_calidad', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('entrega_id')->constrained();
            $t->enum('muestra', ['CAMPO', 'PLANTA']); // doble muestreo
            // seis parámetros del lactoscan
            $t->decimal('grasa', 5, 2)->nullable();
            $t->decimal('proteina', 5, 2)->nullable();
            $t->decimal('densidad', 6, 4)->nullable();
            $t->decimal('temperatura', 5, 2)->nullable();
            $t->decimal('agua_anadida', 5, 2)->nullable();
            $t->decimal('ph', 4, 2)->nullable();
            $t->boolean('significada')->default(false); // expuesta al sol
            $t->enum('fuente', ['OCR', 'BLUETOOTH', 'MANUAL'])->default('MANUAL');
            $t->string('lactoscan_serie', 20)->nullable(); // LS-01 .. LS-03
            $t->string('ticket_path')->nullable();          // foto del ticket
            $t->enum('veredicto', ['ACEPTADO', 'RECHAZADO', 'ADULTERADA'])
              ->nullable();
            $t->json('causas')->nullable();       // calculado por el servidor
            $t->text('observaciones')->nullable();
            $t->foreignId('analista_id')->constrained('users');
            $t->dateTime('tomada_en');
            $t->timestamps();
            $t->unique(['entrega_id', 'muestra']);
        });

        Schema::create('sanciones', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('proveedor_id')->constrained('proveedores');
            $t->foreignId('analisis_id')->nullable()->constrained('analisis_calidad');
            $t->enum('tipo', ['ADULTERACION', 'SIGNIFICADA', 'ACIDEZ']);
            $t->unsignedTinyInteger('nivel');     // 1 = primera vez, 2 = baja
            $t->enum('medida', ['PRECIO_REDUCIDO', 'BAJA_DEFINITIVA', 'CAPACITACION']);
            $t->decimal('precio_aplicado', 6, 2)->nullable();
            $t->text('detalle')->nullable();
            $t->foreignId('resuelta_por')->constrained('users');
            $t->timestamps();
            $t->index(['proveedor_id', 'tipo']);
        });

        // Fase 2: caudalímetro en la tina de recepción
        Schema::create('recepciones_planta', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehiculo_id')->constrained();
            $t->date('fecha');
            $t->decimal('litros_campo', 9, 2);    // suma de entregas del día
            $t->decimal('litros_planta', 9, 2);   // tina de recepción
            $t->decimal('diferencia_pct', 6, 2);
            $t->boolean('fuera_tolerancia')->default(false); // |dif| > 1.5 %
            $t->enum('origen', ['MANUAL', 'CAUDALIMETRO'])->default('MANUAL');
            $t->text('observaciones')->nullable();
            $t->timestamps();
            $t->unique(['vehiculo_id', 'fecha']);
        });

        Schema::create('solicitudes_cambio_zona', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('proveedor_id')->constrained('proveedores');
            $t->foreignId('comunidad_origen_id')->constrained('comunidades');
            $t->foreignId('comunidad_destino_id')->constrained('comunidades');
            $t->text('motivo')->nullable();
            $t->enum('estado', ['PENDIENTE', 'APROBADA', 'RECHAZADA'])
              ->default('PENDIENTE');
            $t->date('solicitada_para');          // 2-3 días de anticipación
            $t->date('vigente_desde')->nullable(); // lunes siguiente
            $t->foreignId('resuelta_por')->nullable()->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_cambio_zona');
        Schema::dropIfExists('recepciones_planta');
        Schema::dropIfExists('sanciones');
        Schema::dropIfExists('analisis_calidad');
        Schema::dropIfExists('entregas');
    }
};
