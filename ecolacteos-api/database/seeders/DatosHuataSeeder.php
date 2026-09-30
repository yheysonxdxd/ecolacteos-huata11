<?php

namespace Database\Seeders;

use App\Models\Comunidad;
use App\Models\Insumo;
use App\Models\Parametro;
use App\Models\PrecioVenta;
use App\Models\Producto;
use App\Models\Vehiculo;
use App\Models\Zona;
use Illuminate\Database\Seeder;

class DatosHuataSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'precio.leche_litro'            => ['1.80', 'Soles por litro al proveedor'],
            'precio.factor_adulteracion'    => ['0.667', 'Factor de castigo en 1.ª adulteración'],
            'calidad.grasa_min'             => ['3.0', '% mínimo de grasa'],
            'calidad.proteina_min'          => ['2.9', '% mínimo de proteína'],
            'calidad.densidad_min'          => ['1.028', 'g/mL'],
            'calidad.densidad_max'          => ['1.034', 'g/mL'],
            'calidad.temperatura_max'       => ['6.0', '°C al recibir'],
            'calidad.ph_min'                => ['6.6', 'pH mínimo'],
            'calidad.ph_max'                => ['6.8', 'pH máximo'],
            'calidad.agua_adulteracion'     => ['5.0', '% de agua añadida = adulteración'],
            'calidad.brecha_agua_tolerancia'=> ['2.0', 'Puntos de brecha ruta/planta que exigen revisión'],
            'acopio.desvio_promedio_pct'    => ['20', '% de desvío que dispara aviso'],
            'conciliacion.tolerancia_pct'   => ['1.5', '% campo vs. planta'],
        ] as $clave => [$valor, $desc]) {
            Parametro::updateOrCreate(['clave' => $clave], ['valor' => $valor, 'descripcion' => $desc]);
        }

        // 4 zonas de acopio, cada una con su ruta y vehículo
        $zonas = collect(['Zona 1', 'Zona 2', 'Zona 3', 'Zona 4'])
            ->mapWithKeys(fn ($n) => [$n => Zona::create(['nombre' => $n])]);

        // Comunidades reales del distrito de Huata (mapa vial MTC).
        // croquis_x / croquis_y son coordenadas del dibujo, NO coordenadas GPS.
        $comunidades = [
            ['Zona 1', 'Huatta', 104, 266, 'PE-3S · PU-118'],
            ['Zona 1', 'Santa Bárbara de Moro', 178, 300, 'PE-3S'],
            ['Zona 1', 'Canchi', 262, 322, 'PE-3S'],
            ['Zona 2', 'Micaela Bastidas de Yanamocco', 44, 196, 'PU-118'],
            ['Zona 2', 'Carata', 128, 228, 'PU-118'],
            ['Zona 2', 'Lluco', 214, 240, 'PU-931'],
            ['Zona 3', 'San Francisco de Buena Vista', 48, 112, 'PU-933'],
            ['Zona 3', 'Llachahui', 126, 140, 'PU-933'],
            ['Zona 3', 'Uquisilla', 190, 158, 'PU-931'],
            ['Zona 4', 'Collana Primero', 246, 96, 'PU-947'],
            ['Zona 4', 'Collana Segundo', 290, 112, 'PU-949'],
            ['Zona 4', 'Faon', 302, 70, 'PU-950'],
            ['Zona 4', 'Yasin', 262, 46, 'PU-949'],
            ['Zona 4', 'Kapi', 226, 62, 'PU-947'],
        ];
        $creadas = [];
        foreach ($comunidades as [$zona, $nombre, $x, $y, $vias]) {
            $creadas[$nombre] = Comunidad::create([
                'zona_id' => $zonas[$zona]->id, 'nombre' => $nombre,
                'croquis_x' => $x, 'croquis_y' => $y, 'vias' => $vias,
            ]);
        }

        $rutas = [
            ['C-01', 'CARRO',   'Zona 1', false, ['Huatta', 'Santa Bárbara de Moro', 'Canchi']],
            ['C-02', 'CARRO',   'Zona 2', false, ['Micaela Bastidas de Yanamocco', 'Carata', 'Lluco']],
            ['C-03', 'CARRO',   'Zona 3', false, ['San Francisco de Buena Vista', 'Llachahui', 'Uquisilla']],
            // el motocar no hace ruta lineal: cubre puntos cercanos en circuito
            ['M-01', 'MOTOCAR', 'Zona 4', true,  ['Kapi', 'Yasin', 'Faon', 'Collana Segundo', 'Collana Primero']],
        ];
        foreach ($rutas as [$codigo, $tipo, $zona, $circular, $orden]) {
            Vehiculo::create([
                'codigo' => $codigo, 'tipo' => $tipo,
                'zona_id' => $zonas[$zona]->id, 'ruta_circular' => $circular,
                'orden_ruta' => collect($orden)->map(fn ($n) => $creadas[$n]->id)->all(),
            ]);
        }

        // 12-13 quesos de 1 kg por cada 100 litros
        $paria = Producto::create([
            'nombre' => 'Queso Paria', 'unidad' => 'quesos de 1 kg',
            'rendimiento_min' => 0.12, 'rendimiento_max' => 0.13, 'vida_util_dias' => 45,
        ]);
        $yogurt = Producto::create([
            // se mide y se vende por litro (0,95 L de yogurt por litro de leche)
            'nombre' => 'Yogurt natural', 'unidad' => 'litros',
            'rendimiento_min' => 0.95, 'rendimiento_max' => 0.95, 'vida_util_dias' => 21,
        ]);

        foreach ([
            // queso: mayoristas 21, vecinos de Huata (DIRECTO) 20, proveedores de leche (PLANTA) 19
            // yogurt: S/ 5 el litro para todos
            [$paria,  'MAYORISTA', 21.00], [$paria,  'DIRECTO', 20.00], [$paria,  'PLANTA', 19.00],
            [$yogurt, 'MAYORISTA', 5.00], [$yogurt, 'DIRECTO', 5.00], [$yogurt, 'PLANTA', 5.00],
        ] as [$producto, $tipo, $precio]) {
            PrecioVenta::create([
                'producto_id' => $producto->id, 'tipo_cliente' => $tipo,
                'precio' => $precio, 'vigente_desde' => now()->toDateString(),
            ]);
        }

        foreach ([
            ['Cultivo láctico', 'kg', 1.2, 0.5], ['Cuajo líquido', 'L', 0.8, 0.5],
            ['Sal industrial', 'kg', 40, 15],    ['Cloruro de calcio', 'kg', 0.3, 0.4],
            ['Azúcar blanca', 'kg', 60, 25],     ['Saborizante fresa', 'L', 4, 3],
            ['Envase 1 L', 'un', 380, 200],      ['Film termoencogible', 'un', 90, 120],
        ] as [$nombre, $unidad, $stock, $min]) {
            Insumo::create([
                'nombre' => $nombre, 'unidad' => $unidad,
                'stock' => $stock, 'stock_minimo' => $min,
            ]);
        }
    }
}
