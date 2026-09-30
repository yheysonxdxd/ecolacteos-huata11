<?php

namespace Tests;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos mínimos para las pruebas (en SQLite en memoria):
 * 3 comunidades, 2 vehículos, un usuario por rol y un proveedor enlazado
 * al usuario PRODUCTOR. Contraseña de todos: huata2026.
 */
trait CreaDatosHuata
{
    protected array $comunidad = [];
    protected array $vehiculo = [];
    protected array $usuarios = [];
    protected int $proveedorId;

    protected function crearDatosHuata(): void
    {
        $roles = ['ACOPIADOR', 'CALIDAD', 'ADMIN', 'PRODUCTOR', 'OPERARIO', 'COMPRAS'];
        foreach ($roles as $i => $rol) {
            $this->usuarios[$rol] = User::create([
                'name' => "Usuario $rol", 'email' => strtolower($rol).'@prueba.local',
                'password' => 'huata2026', 'dni' => '4000000'.($i + 1), 'rol' => $rol, 'activo' => true,
            ]);
        }

        $zona = DB::table('zonas')->insertGetId(['nombre' => 'Zona 1']);
        foreach (['Huatta', 'Carata', 'Kapi'] as $i => $nombre) {
            $this->comunidad[$nombre] = DB::table('comunidades')->insertGetId([
                'zona_id' => $zona, 'nombre' => $nombre, 'croquis_x' => 10 * $i, 'croquis_y' => 10 * $i,
            ]);
        }
        $this->vehiculo['C-01'] = DB::table('vehiculos')->insertGetId([
            'codigo' => 'C-01', 'tipo' => 'CARRO', 'zona_id' => $zona,
            'acopiador_id' => $this->usuarios['ACOPIADOR']->id,
            'orden_ruta' => json_encode([$this->comunidad['Huatta'], $this->comunidad['Carata']]),
        ]);
        $this->vehiculo['M-01'] = DB::table('vehiculos')->insertGetId([
            'codigo' => 'M-01', 'tipo' => 'MOTOCAR', 'zona_id' => $zona,
            'orden_ruta' => json_encode([$this->comunidad['Kapi']]),
        ]);

        $this->proveedorId = DB::table('proveedores')->insertGetId([
            'uuid' => (string) Str::uuid(), 'nombre' => 'Rosa Quispe Mamani', 'dni' => '40112233',
            'comunidad_id' => $this->comunidad['Huatta'], 'vehiculo_id' => $this->vehiculo['C-01'],
            'user_id' => $this->usuarios['PRODUCTOR']->id, 'estado' => 'ACTIVO',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Operación ENTREGA como la manda la app. */
    protected function opEntrega(string $uuid, float $litros, bool $ausente = false, ?string $fecha = null): array
    {
        $fecha ??= now('America/Lima')->toDateString();

        return [
            'uuid' => $uuid, 'tipo' => 'ENTREGA', 'proveedor_id' => $this->proveedorId,
            'fecha' => $fecha, 'litros' => $litros, 'ausente' => $ausente,
            'registrado_en' => "$fecha 06:05:00",
        ];
    }
}
