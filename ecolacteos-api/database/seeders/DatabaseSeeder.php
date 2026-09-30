<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Datos de la planta: parámetros, zonas, comunidades, vehículos,
        // productos, precios e insumos.
        $this->call(DatosHuataSeeder::class);

        // Usuarios de prueba (contraseña de todos: huata2026).
        // El cast 'hashed' del modelo User cifra la contraseña al guardar.
        $usuarios = [
            ['40000001', 'Acopiador de prueba', 'ACOPIADOR', 'acopiador@huata.local'],
            ['40000002', 'Analista de calidad', 'CALIDAD',   'calidad@huata.local'],
            ['40000003', 'Administrador',       'ADMIN',     'admin@huata.local'],
        ];

        $creados = [];
        foreach ($usuarios as [$dni, $nombre, $rol, $email]) {
            $creados[$rol] = User::updateOrCreate(
                ['dni' => $dni],
                ['name' => $nombre, 'rol' => $rol, 'email' => $email,
                 'password' => 'huata2026', 'activo' => true],
            );
        }

        // El acopiador de prueba maneja el carro C-01 (Zona 1).
        Vehiculo::where('codigo', 'C-01')
            ->update(['acopiador_id' => $creados['ACOPIADOR']->id]);
    }
}
