<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zonas', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');                 // Zona 1 .. Zona 4
            $t->text('descripcion')->nullable();
            $t->timestamps();
        });

        Schema::create('comunidades', function (Blueprint $t) {
            $t->id();
            $t->foreignId('zona_id')->constrained();
            $t->string('nombre');                 // Huatta, Carata, Kapi...
            $t->unsignedSmallInteger('croquis_x'); // coords relativas del croquis
            $t->unsignedSmallInteger('croquis_y'); // NO son GPS
            $t->string('vias')->nullable();        // "PU-118", "PU-947/949/950"
            $t->timestamps();
        });

        Schema::create('vehiculos', function (Blueprint $t) {
            $t->id();
            $t->string('codigo', 12)->unique();   // C-01, C-02, C-03, M-01
            $t->enum('tipo', ['CARRO', 'MOTOCAR']);
            $t->foreignId('zona_id')->constrained();
            $t->foreignId('acopiador_id')->nullable()->constrained('users');
            $t->boolean('ruta_circular')->default(false); // motocar = circuito
            $t->json('orden_ruta')->nullable();   // [comunidad_id, ...] en orden
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });

        // roles: ACOPIADOR, CALIDAD, PRODUCTOR, OPERARIO, COMPRAS, ADMIN
        Schema::table('users', function (Blueprint $t) {
            $t->string('dni', 8)->nullable()->unique()->after('id');
            $t->string('rol', 20)->default('ACOPIADOR')->after('name');
            $t->string('telefono', 20)->nullable();
            $t->boolean('activo')->default(true);
        });

        Schema::create('proveedores', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();           // generado en el teléfono
            $t->string('nombre');
            $t->string('dni', 8)->unique();
            $t->foreignId('comunidad_id')->constrained('comunidades');
            $t->foreignId('vehiculo_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained(); // si usa la app
            // opcionales a propósito: en el alta de campo no se conocen
            $t->decimal('promedio_litros', 6, 2)->nullable();
            $t->unsignedSmallInteger('vacas_ordeno')->nullable();
            $t->enum('estado', ['ACTIVO', 'SUSPENDIDO', 'BAJA'])->default('ACTIVO');
            $t->text('motivo_baja')->nullable();
            $t->date('ubicacion_actualizada_en')->nullable();
            $t->foreignId('registrado_por')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['vehiculo_id', 'estado']);
        });

        // la ubicación del establo cambia por rotación de pastos
        Schema::create('proveedor_ubicaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            $t->foreignId('comunidad_id')->constrained('comunidades');
            $t->foreignId('vehiculo_id')->constrained();
            $t->date('vigente_desde');
            $t->foreignId('registrado_por')->nullable()->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedor_ubicaciones');
        Schema::dropIfExists('proveedores');
        Schema::dropIfExists('vehiculos');
        Schema::dropIfExists('comunidades');
        Schema::dropIfExists('zonas');
    }
};
