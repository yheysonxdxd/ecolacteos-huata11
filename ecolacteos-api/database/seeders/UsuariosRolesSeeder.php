<?php

namespace Database\Seeders;

use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Usuarios de prueba de los roles que faltaban (contraseña: huata2026).
 *   php artisan db:seed --class=UsuariosRolesSeeder
 * Se puede correr varias veces: busca por DNI y no duplica.
 */
class UsuariosRolesSeeder extends Seeder
{
    public function run(): void
    {
        $usuarios = [
            ['40000004', 'Productor de prueba', 'PRODUCTOR', 'productor@huata.local'],
            ['40000005', 'Operario de planta',  'OPERARIO',  'operario@huata.local'],
            ['40000006', 'Encargado de compras', 'COMPRAS',  'compras@huata.local'],
        ];

        $creados = [];
        foreach ($usuarios as [$dni, $nombre, $rol, $email]) {
            $creados[$rol] = User::updateOrCreate(
                ['dni' => $dni],
                ['name' => $nombre, 'rol' => $rol, 'email' => $email,
                 'password' => 'huata2026', 'activo' => true],
            );
        }

        // El productor de prueba es Rosa Quispe (si ya corrió ProveedoresPruebaSeeder).
        Proveedor::where('dni', '40112233')
            ->update(['user_id' => $creados['PRODUCTOR']->id]);
    }
}
