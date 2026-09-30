<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Historial de prueba de las últimas 3 semanas (hoy queda libre para la app):
 * entregas, análisis de calidad, sanciones, recepción en planta, lotes,
 * insumos, compras, clientes, ventas, pagos semanales y solicitudes de zona.
 *
 *   php artisan db:seed --class=ProveedoresPruebaSeeder   (primero)
 *   php artisan db:seed --class=UsuariosRolesSeeder
 *   php artisan db:seed --class=HistorialPruebaSeeder
 *
 * Si ya se cargó (existe el lote L-2401) no hace nada.
 */
class HistorialPruebaSeeder extends Seeder
{
    private float $precio;

    public function run(): void
    {
        if (DB::table('lotes')->where('codigo', 'L-2401')->exists()) {
            $this->command?->warn('El historial de prueba ya estaba cargado.');
            return;
        }
        mt_srand(2026); // mismos datos cada vez

        $u = fn ($dni) => DB::table('users')->where('dni', $dni)->value('id');
        $acopiador = $u('40000001');
        $analista  = $u('40000002');
        $admin     = $u('40000003');
        $operario  = $u('40000005') ?? $admin;
        $compras   = $u('40000006') ?? $admin;
        $this->precio = (float) (DB::table('parametros')->where('clave', 'precio.leche_litro')->value('valor') ?? 1.80);

        $proveedores = DB::table('proveedores')->where('estado', 'ACTIVO')->get();
        if ($proveedores->isEmpty()) {
            $this->command?->error('No hay proveedores: corre antes ProveedoresPruebaSeeder.');
            return;
        }

        $hoy    = Carbon::today('America/Lima');
        $inicio = $hoy->copy()->startOfWeek()->subWeeks(3);   // lunes de hace 3 semanas
        $fin    = $hoy->copy()->subDay();                     // ayer

        DB::transaction(function () use ($proveedores, $inicio, $fin, $acopiador, $analista, $admin, $operario, $compras) {
            $this->recetas();
            $clientes = $this->clientes();
            $this->ordenesCompra($compras, $inicio);

            // proveedores "problema": uno adultera una vez, otro deja la leche al sol
            $adultera   = $proveedores->firstWhere('dni', '40445566') ?? $proveedores[3];
            $significa  = $proveedores->firstWhere('dni', '41020004') ?? $proveedores[5];
            $nLote = 2401;

            for ($d = $inicio->copy(); $d->lte($fin); $d->addDay()) {
                $esDomingo = $d->isSunday();
                $entregasDia = [];

                foreach ($proveedores as $p) {
                    $ausente = mt_rand(1, 100) <= ($esDomingo ? 65 : 6);
                    $prom    = (float) ($p->promedio_litros ?: 10);
                    $litros  = $ausente ? 0 : round($prom * mt_rand(85, 115) / 100, 1);

                    // veredicto del día
                    $veredicto = 'ACEPTADO';
                    if (! $ausente) {
                        if ($p->id == $adultera->id && $d->diffInDays($fin) == 9) $veredicto = 'ADULTERADA';
                        elseif ($p->id == $significa->id && $d->diffInDays($fin) == 4) $veredicto = 'SIGNIFICADA';
                        elseif (mt_rand(1, 100) <= 5) $veredicto = 'RECHAZADO';
                    }
                    $aceptado = ! $ausente && $veredicto === 'ACEPTADO';

                    $hora = $d->copy()->setTime(mt_rand(6, 9), mt_rand(0, 59));
                    $entregaId = DB::table('entregas')->insertGetId([
                        'uuid'                 => (string) Str::uuid(),
                        'proveedor_id'         => $p->id,
                        'vehiculo_id'          => $p->vehiculo_id,
                        'fecha'                => $d->toDateString(),
                        'litros'               => $litros,
                        'ausente'              => $ausente,
                        'confirmada_proveedor' => ! $ausente,
                        'confirmada_en'        => $ausente ? null : $hora->copy()->addHours(3),
                        'estado_calidad'       => $ausente ? 'PENDIENTE' : ($aceptado ? 'ACEPTADO' : 'RECHAZADO'),
                        'precio_litro'         => $aceptado ? $this->precio : null,
                        'monto'                => $aceptado ? round($litros * $this->precio, 2) : null,
                        'registrado_por'       => $acopiador,
                        'registrado_en'        => $hora,
                        'recibido_en'          => $hora->copy()->addMinutes(mt_rand(5, 180)),
                        'created_at'           => $hora, 'updated_at' => $hora,
                    ]);

                    if ($ausente) continue;
                    $entregasDia[] = ['id' => $entregaId, 'vehiculo_id' => $p->vehiculo_id, 'litros' => $litros, 'aceptado' => $aceptado];

                    $analisisId = $this->analisis($entregaId, $veredicto, $hora->copy()->addHours(4), $analista);
                    if ($veredicto === 'ADULTERADA') {
                        $this->sancion($p->id, $analisisId, 'ADULTERACION', 'PRECIO_REDUCIDO',
                            round($this->precio * 0.667, 2), 'Primera adulteración: agua añadida sobre 5 %', $admin, $hora);
                    } elseif ($veredicto === 'SIGNIFICADA') {
                        $this->sancion($p->id, $analisisId, 'SIGNIFICADA', 'CAPACITACION',
                            null, 'Leche expuesta al sol durante el acopio', $admin, $hora);
                    }
                }

                $this->recepcion($entregasDia, $d);
                $nLote = $this->lotes($entregasDia, $d, $nLote, $operario, $fin, $clientes, $compras);
            }

            $this->pagos($inicio, $fin);
            $this->solicitudes($proveedores, $admin);
        });

        $this->command?->info('Historial de prueba cargado desde '.$inicio->toDateString().' hasta '.$fin->toDateString().'.');
    }

    // ---------------------------------------------------------------- calidad

    private function analisis(int $entregaId, string $veredicto, Carbon $cuando, int $analista): int
    {
        $r = fn ($a, $b, $dec = 2) => round($a + mt_rand() / mt_getrandmax() * ($b - $a), $dec);
        $m = [
            'grasa' => $r(3.1, 4.0), 'proteina' => $r(2.95, 3.4), 'densidad' => $r(1.029, 1.033, 4),
            'temperatura' => $r(3.0, 5.8), 'agua_anadida' => $r(0, 1.8), 'ph' => $r(6.62, 6.78), 'significada' => false,
        ];
        $causas = [];
        switch ($veredicto) {
            case 'ADULTERADA':
                $m['agua_anadida'] = $r(7, 11); $m['densidad'] = $r(1.022, 1.026, 4); $m['grasa'] = $r(2.5, 2.8);
                $causas[] = "agua añadida {$m['agua_anadida']} % (≥ 5 %)";
                $causas[] = 'densidad fuera de 1.028–1.034';
                break;
            case 'SIGNIFICADA':
                $m['significada'] = true; $m['temperatura'] = $r(8, 11); $m['ph'] = $r(6.45, 6.55);
                $causas[] = 'leche significada (expuesta al sol)';
                $causas[] = "temperatura {$m['temperatura']} °C sobre 6 °C";
                break;
            case 'RECHAZADO':
                if (mt_rand(0, 1)) { $m['grasa'] = $r(2.6, 2.95); $causas[] = "grasa {$m['grasa']} % bajo 3 %"; }
                else { $m['temperatura'] = $r(6.5, 8.5); $causas[] = "temperatura {$m['temperatura']} °C sobre 6 °C"; }
                break;
        }

        return DB::table('analisis_calidad')->insertGetId(array_merge($m, [
            'uuid'            => (string) Str::uuid(),
            'entrega_id'      => $entregaId,
            'muestra'         => 'CAMPO',
            'fuente'          => ['OCR', 'BLUETOOTH', 'MANUAL'][mt_rand(0, 2)],
            'lactoscan_serie' => 'LS-0'.mt_rand(1, 3),
            'veredicto'       => $veredicto === 'SIGNIFICADA' ? 'RECHAZADO' : $veredicto,
            'causas'          => json_encode($causas, JSON_UNESCAPED_UNICODE),
            'analista_id'     => $analista,
            'tomada_en'       => $cuando,
            'created_at'      => $cuando, 'updated_at' => $cuando,
        ]));
    }

    private function sancion(int $prov, int $analisis, string $tipo, string $medida, ?float $precio, string $detalle, int $admin, Carbon $cuando): void
    {
        DB::table('sanciones')->insert([
            'uuid' => (string) Str::uuid(), 'proveedor_id' => $prov, 'analisis_id' => $analisis,
            'tipo' => $tipo, 'nivel' => 1, 'medida' => $medida, 'precio_aplicado' => $precio,
            'detalle' => $detalle, 'resuelta_por' => $admin,
            'created_at' => $cuando, 'updated_at' => $cuando,
        ]);
    }

    // ------------------------------------------------------ recepción y lotes

    private function recepcion(array $entregas, Carbon $d): void
    {
        foreach (collect($entregas)->groupBy('vehiculo_id') as $vehiculo => $grupo) {
            $campo  = round($grupo->sum('litros'), 2);
            $planta = round($campo * (1 - mt_rand(-5, 22) / 1000), 2); // -0.5 % .. +2.2 % de merma
            $dif    = $campo > 0 ? round(($planta - $campo) / $campo * 100, 2) : 0;
            DB::table('recepciones_planta')->insert([
                'vehiculo_id' => $vehiculo, 'fecha' => $d->toDateString(),
                'litros_campo' => $campo, 'litros_planta' => $planta, 'diferencia_pct' => $dif,
                'fuera_tolerancia' => abs($dif) > 1.5, 'origen' => 'MANUAL',
                'created_at' => $d->copy()->setTime(11, 0), 'updated_at' => $d->copy()->setTime(11, 0),
            ]);
        }
    }

    private function lotes(array $entregas, Carbon $d, int $n, int $operario, Carbon $fin, array $clientes, int $compras): int
    {
        $aceptadas = collect($entregas)->where('aceptado', true);
        if ($aceptadas->isEmpty()) return $n;

        $productos = DB::table('productos')->get()->keyBy('nombre');
        $queso  = $productos['Queso Paria'];
        $yogurt = $productos['Yogurt natural'];
        $motocar = DB::table('vehiculos')->where('tipo', 'MOTOCAR')->value('id');

        // martes y viernes la leche del motocar va a yogurt; el resto, a queso
        $grupos = in_array($d->dayOfWeekIso, [2, 5])
            ? [[$queso, $aceptadas->where('vehiculo_id', '!=', $motocar)], [$yogurt, $aceptadas->where('vehiculo_id', $motocar)]]
            : [[$queso, $aceptadas]];

        foreach ($grupos as [$prod, $ents]) {
            $litros = round($ents->sum('litros'), 2);
            if ($litros <= 0) continue;
            $min = round($litros * $prod->rendimiento_min, 2);
            $max = round($litros * $prod->rendimiento_max, 2);
            $abierto = $d->eq($fin); // el lote de ayer sigue en proceso
            $obtenido = $abierto ? null : round(($min + $max) / 2 * mt_rand(92, 106) / 100, 0);
            $medio = ($min + $max) / 2;
            $inicio = $d->copy()->setTime(12, 30);
            $codigo = 'L-'.$n++;

            $loteId = DB::table('lotes')->insertGetId([
                'uuid' => (string) Str::uuid(), 'codigo' => $codigo, 'producto_id' => $prod->id,
                'litros_leche' => $litros, 'esperado_min' => $min, 'esperado_max' => $max,
                'obtenido' => $obtenido,
                'desvio_pct' => $obtenido === null ? null : round(($obtenido - $medio) / $medio * 100, 2),
                'fuera_referencia' => $obtenido !== null && ($obtenido < $min * 0.97 || $obtenido > $max * 1.03),
                'estado' => $abierto ? 'EN_PROCESO' : 'TERMINADO',
                'vence_el' => $d->copy()->addDays($prod->vida_util_dias)->toDateString(),
                'operario_id' => $operario, 'iniciado_en' => $inicio,
                'cerrado_en' => $abierto ? null : $inicio->copy()->addHours(20),
                'created_at' => $inicio, 'updated_at' => $inicio,
            ]);

            foreach ($ents as $e) {
                DB::table('lote_entrega')->insert([
                    'lote_id' => $loteId, 'entrega_id' => $e['id'], 'litros' => $e['litros'],
                    'created_at' => $inicio, 'updated_at' => $inicio,
                ]);
            }

            // insumos gastados según receta
            foreach (DB::table('recetas_insumo')->where('producto_id', $prod->id)->get() as $rec) {
                DB::table('movimientos_insumo')->insert([
                    'uuid' => (string) Str::uuid(), 'insumo_id' => $rec->insumo_id,
                    'cantidad' => -round($rec->cantidad_por_100l * $litros / 100, 3),
                    'referencia' => "$codigo · inicio de lote",
                    'origen_type' => 'lote', 'origen_id' => $loteId,
                    'registrado_por' => $operario, 'created_at' => $inicio, 'updated_at' => $inicio,
                ]);
            }

            if ($obtenido) $this->ventas($loteId, $prod, $obtenido, $d, $clientes, $compras);
        }

        return $n;
    }

    // ------------------------------------------------ insumos, compras, ventas

    private function recetas(): void
    {
        if (DB::table('recetas_insumo')->exists()) return;
        $ins = DB::table('insumos')->pluck('id', 'nombre');
        $prod = DB::table('productos')->pluck('id', 'nombre');
        $filas = [
            ['Queso Paria', 'Cuajo líquido', 0.02], ['Queso Paria', 'Cloruro de calcio', 0.02],
            ['Queso Paria', 'Sal industrial', 1.5], ['Queso Paria', 'Cultivo láctico', 0.005],
            ['Yogurt natural', 'Cultivo láctico', 0.01], ['Yogurt natural', 'Azúcar blanca', 8],
            ['Yogurt natural', 'Saborizante fresa', 0.3],
        ];
        foreach ($filas as [$p, $i, $c]) {
            if (! isset($prod[$p], $ins[$i])) continue;
            DB::table('recetas_insumo')->insert([
                'producto_id' => $prod[$p], 'insumo_id' => $ins[$i], 'cantidad_por_100l' => $c,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function ordenesCompra(int $compras, Carbon $inicio): void
    {
        $ins = DB::table('insumos')->pluck('id', 'nombre');
        $ordenes = [
            // [código, insumo, cantidad, proveedor comercial, costo, estado, días desde el inicio]
            ['OC-101', 'Sal industrial',      50,  'Comercial Puno SAC',        95.00,  'RECIBIDA', 1],
            ['OC-102', 'Cultivo láctico',     1,   'Lácteos Insumos Arequipa',  380.00, 'RECIBIDA', 3],
            ['OC-103', 'Azúcar blanca',       50,  'Distribuidora Juliaca',     190.00, 'RECIBIDA', 8],
            ['OC-104', 'Cuajo líquido',       1,   'Lácteos Insumos Arequipa',  145.00, 'RECIBIDA', 12],
            ['OC-105', 'Envase 1 L',          300, 'Plásticos del Sur EIRL',    270.00, 'RECIBIDA', 15],
            ['OC-106', 'Cloruro de calcio',   1,   'Lácteos Insumos Arequipa',  42.00,  'EMITIDA',  19],
            ['OC-107', 'Film termoencogible', 200, 'Plásticos del Sur EIRL',    160.00, 'EMITIDA',  20],
        ];
        foreach ($ordenes as [$cod, $insumo, $cant, $prov, $costo, $estado, $dia]) {
            if (! isset($ins[$insumo])) continue;
            $cuando = $inicio->copy()->addDays($dia)->setTime(10, 0);
            $id = DB::table('ordenes_compra')->insertGetId([
                'uuid' => (string) Str::uuid(), 'codigo' => $cod, 'insumo_id' => $ins[$insumo],
                'cantidad' => $cant, 'proveedor_comercial' => $prov, 'costo_estimado' => $costo,
                'estado' => $estado, 'emitida_por' => $compras, 'created_at' => $cuando, 'updated_at' => $cuando,
            ]);
            if ($estado === 'RECIBIDA') {
                DB::table('movimientos_insumo')->insert([
                    'uuid' => (string) Str::uuid(), 'insumo_id' => $ins[$insumo], 'cantidad' => $cant,
                    'referencia' => "$cod · recepción de compra", 'origen_type' => 'orden_compra', 'origen_id' => $id,
                    'registrado_por' => $compras,
                    'created_at' => $cuando->copy()->addDays(2), 'updated_at' => $cuando->copy()->addDays(2),
                ]);
            }
        }
    }

    private function clientes(): array
    {
        $lista = [
            ['Distribuidora Lácteos Juliaca', 'MAYORISTA', '20448812345', '951234567'],
            ['Comercial Altiplano EIRL',      'MAYORISTA', '20601122334', '950112233'],
            ['Puesto Mercado Unión y Dignidad', 'DIRECTO', null, '973456789'],
            ['Puesto Mercado Central Puno',   'DIRECTO',   null, '962345678'],
            ['Puesto Mercado Bellavista',     'DIRECTO',   null, '984567123'],
            ['Venta en planta',               'PLANTA',    null, null],
        ];
        $ids = [];
        foreach ($lista as [$nombre, $tipo, $ruc, $tel]) {
            $ids[$tipo][] = DB::table('clientes')->where('nombre', $nombre)->value('id')
                ?? DB::table('clientes')->insertGetId([
                    'nombre' => $nombre, 'tipo' => $tipo, 'ruc' => $ruc, 'telefono' => $tel,
                    'activo' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
        }
        return $ids;
    }

    private function ventas(int $loteId, object $prod, float $obtenido, Carbon $d, array $clientes, int $compras): void
    {
        $precios = DB::table('precios_venta')->where('producto_id', $prod->id)->pluck('precio', 'tipo_cliente');
        $restante = floor($obtenido * mt_rand(70, 95) / 100);
        // 60 % mayorista, 30 % mercados, 10 % en planta
        foreach ([['MAYORISTA', 0.6], ['DIRECTO', 0.3], ['PLANTA', 0.1]] as [$tipo, $parte]) {
            $cant = floor($restante * $parte);
            if ($cant <= 0 || empty($clientes[$tipo])) continue;
            $precio = (float) ($precios[$tipo] ?? 20);
            $cuando = $d->copy()->addDays(mt_rand(1, 3))->setTime(mt_rand(8, 16), mt_rand(0, 59));
            DB::table('ventas')->insert([
                'uuid' => (string) Str::uuid(),
                'cliente_id' => $clientes[$tipo][array_rand($clientes[$tipo])],
                'producto_id' => $prod->id, 'lote_id' => $loteId,
                'cantidad' => $cant, 'precio_unitario' => $precio, 'total' => round($cant * $precio, 2),
                'registrada_por' => $compras, 'vendida_en' => $cuando,
                'created_at' => $cuando, 'updated_at' => $cuando,
            ]);
        }
    }

    // ------------------------------------------------------ pagos y solicitudes

    private function pagos(Carbon $inicio, Carbon $fin): void
    {
        // solo semanas completas (lunes a domingo); se pagan el viernes siguiente
        for ($lunes = $inicio->copy(); $lunes->copy()->addDays(6)->lte($fin); $lunes->addWeek()) {
            $domingo = $lunes->copy()->addDays(6);
            $viernes = $domingo->copy()->addDays(5);
            $pagado  = $viernes->lt(Carbon::today('America/Lima'));

            $porProveedor = DB::table('entregas')
                ->whereBetween('fecha', [$lunes->toDateString(), $domingo->toDateString()])
                ->where('estado_calidad', 'ACEPTADO')
                ->orderBy('fecha')->get()->groupBy('proveedor_id');

            foreach ($porProveedor as $prov => $ents) {
                DB::table('pagos_semanales')->insert([
                    'proveedor_id' => $prov,
                    'semana_iso' => $lunes->isoWeekYear.'-W'.str_pad($lunes->isoWeek, 2, '0', STR_PAD_LEFT),
                    'inicio' => $lunes->toDateString(), 'fin' => $domingo->toDateString(),
                    'litros_aceptados' => $ents->sum('litros'),
                    'precio_litro' => $this->precio,
                    'monto' => round($ents->sum('monto'), 2),
                    'detalle_dias' => json_encode($ents->map(fn ($e) => [
                        'fecha' => $e->fecha, 'litros' => (float) $e->litros, 'monto' => (float) $e->monto,
                    ])->values()),
                    'estado' => $pagado ? 'PAGADO' : 'PENDIENTE',
                    'pagado_el' => $pagado ? $viernes->toDateString() : null,
                    'created_at' => $domingo->copy()->setTime(20, 0), 'updated_at' => $domingo->copy()->setTime(20, 0),
                ]);
            }
        }
    }

    private function solicitudes($proveedores, int $admin): void
    {
        $com = DB::table('comunidades')->pluck('id', 'nombre');
        $hoy = Carbon::today('America/Lima');
        $filas = [
            // [índice de proveedor, comunidad destino, motivo, estado]
            [0, 'Canchi',        'Se mudó con su hija, más cerca de la carretera', 'APROBADA'],
            [4, 'Lluco',         'Alquiló pastos en Lluco para la temporada seca', 'PENDIENTE'],
            [8, 'Collana Primero', 'El carro no llega en época de lluvias',        'PENDIENTE'],
        ];
        foreach ($filas as [$i, $destino, $motivo, $estado]) {
            $p = $proveedores[$i] ?? null;
            if (! $p || ! isset($com[$destino])) continue;
            DB::table('solicitudes_cambio_zona')->insert([
                'uuid' => (string) Str::uuid(), 'proveedor_id' => $p->id,
                'comunidad_origen_id' => $p->comunidad_id, 'comunidad_destino_id' => $com[$destino],
                'motivo' => $motivo, 'estado' => $estado,
                'solicitada_para' => $hoy->copy()->addDays(3)->toDateString(),
                'vigente_desde' => $estado === 'APROBADA' ? $hoy->copy()->next(Carbon::MONDAY)->toDateString() : null,
                'resuelta_por' => $estado === 'APROBADA' ? $admin : null,
                'created_at' => $hoy->copy()->subDays(2), 'updated_at' => $hoy->copy()->subDays(2),
            ]);
        }
    }
}
