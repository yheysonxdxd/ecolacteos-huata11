<?php

namespace Database\Seeders;

use App\Models\Comunidad;
use App\Models\Proveedor;
use App\Models\Vehiculo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Proveedores de prueba repartidos en las rutas de los 4 vehículos.
 *   php artisan db:seed --class=ProveedoresPruebaSeeder
 * Se puede correr varias veces: busca por DNI y no duplica.
 */
class ProveedoresPruebaSeeder extends Seeder
{
    public function run(): void
    {
        $proveedores = [
            // [DNI, nombre, comunidad, vehículo, promedio de litros]
            ['40112233', 'Rosa Quispe Mamani',      'Huatta',                        'C-01', 18],
            ['40112234', 'Hilda Mamani Coaquira',   'Santa Bárbara de Moro',         'C-01', 14],
            ['40112235', 'Teodoro Pari Flores',     'Canchi',                        'C-01', 11],
            ['40223344', 'Julián Apaza Condori',    'Micaela Bastidas de Yanamocco', 'C-02', 12],
            ['40556677', 'Feliciana Cutipa Luque',  'Carata',                        'C-02', 10],
            ['40223346', 'Nicolás Ticona Ramos',    'Lluco',                         'C-02', 15],
            ['40334455', 'Elena Choque Tito',       'San Francisco de Buena Vista',  'C-03', 16],
            ['40445566', 'Mario Huanca Paredes',    'Llachahui',                     'C-03', null],
            ['40334457', 'Justina Calla Mayta',     'Uquisilla',                     'C-03', 13],
            ['40667788', 'Gregorio Yana Ccama',     'Collana Primero',               'M-01', 9],
            ['40667789', 'Sabina Larico Huarsaya',  'Kapi',                          'M-01', 8],
            ['40667790', 'Eusebio Chambi Nina',     'Yasin',                         'M-01', 7],

            // --- C-01 · Zona 1 ---
            ['41010001', 'Agustina Condori Huaman',  'Huatta',                        'C-01', 20],
            ['41010002', 'Braulio Mamani Chura',     'Huatta',                        'C-01', 15],
            ['41010003', 'Celestina Apaza Quispe',   'Santa Bárbara de Moro',         'C-01', 12],
            ['41010004', 'Dionisio Coila Vilca',     'Santa Bárbara de Moro',         'C-01', null],
            ['41010005', 'Encarnación Pari Luque',   'Canchi',                        'C-01', 17],
            ['41010006', 'Faustino Quispe Ccallo',   'Canchi',                        'C-01', 9],

            // --- C-02 · Zona 2 ---
            ['41020001', 'Gumercinda Tito Mamani',   'Micaela Bastidas de Yanamocco', 'C-02', 14],
            ['41020002', 'Hipólito Huanca Apaza',    'Micaela Bastidas de Yanamocco', 'C-02', 11],
            ['41020003', 'Isidora Chambi Condori',   'Carata',                        'C-02', 16],
            ['41020004', 'Jacinto Larico Pari',      'Carata',                        'C-02', 8],
            ['41020005', 'Leandra Ccama Yupanqui',   'Lluco',                         'C-02', 13],
            ['41020006', 'Marcelino Choque Arias',   'Lluco',                         'C-02', null],

            // --- C-03 · Zona 3 ---
            ['41030001', 'Natividad Quispe Coaquira', 'San Francisco de Buena Vista', 'C-03', 18],
            ['41030002', 'Octavio Mayta Huarsaya',   'San Francisco de Buena Vista',  'C-03', 10],
            ['41030003', 'Paulina Nina Calla',       'Llachahui',                     'C-03', 12],
            ['41030004', 'Remigio Vilca Ticona',     'Llachahui',                     'C-03', 15],
            ['41030005', 'Saturnina Flores Mamani',  'Uquisilla',                     'C-03', 9],
            ['41030006', 'Timoteo Cutipa Ramos',     'Uquisilla',                     'C-03', 14],

            // --- M-01 · Zona 4 (motocar, circuito) ---
            ['41040001', 'Úrsula Huaman Condori',    'Kapi',                          'M-01', 6],
            ['41040002', 'Valentín Apaza Yana',      'Yasin',                         'M-01', 8],
            ['41040003', 'Wenceslao Chura Quispe',   'Faon',                          'M-01', 7],
            ['41040004', 'Ximena Pari Chambi',       'Faon',                          'M-01', null],
            ['41040005', 'Yolanda Larico Tito',      'Collana Segundo',               'M-01', 9],
            ['41040006', 'Zenón Mamani Coila',       'Collana Segundo',               'M-01', 10],
        ];

        foreach ($proveedores as [$dni, $nombre, $comunidad, $vehiculo, $promedio]) {
            $existente = Proveedor::where('dni', $dni)->first();
            Proveedor::updateOrCreate(['dni' => $dni], [
                'uuid'            => $existente->uuid ?? (string) Str::uuid(),
                'nombre'          => $nombre,
                'comunidad_id'    => Comunidad::where('nombre', $comunidad)->value('id'),
                'vehiculo_id'     => Vehiculo::where('codigo', $vehiculo)->value('id'),
                'promedio_litros' => $promedio,
                'estado'          => 'ACTIVO',
            ]);
        }
    }
}
