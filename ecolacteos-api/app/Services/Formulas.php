<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fórmulas de producción (insumos por cada 100 L de leche y pasos) y los
 * avisos de insumos que no alcanzan: "falta azúcar para el yogurt".
 */
class Formulas
{
    /**
     * Fórmulas iniciales de la planta: producto => [insumo, cantidad por 100 L].
     * Queso Paria según las guías técnicas de Puno: cloruro de calcio máx. 0,02 % del peso
     * de la leche; sal 1,5–2,7 kg por 100 L (lo usual 2,0) disuelta en 3 veces su peso de agua.
     * Cuajo y cultivo dependen de la marca: confirmar con la etiqueta del frasco o sobre.
     */
    public const INICIALES = [
        'Queso Paria' => [
            ['Cloruro de calcio', 0.02], ['Cultivo láctico', 0.005],
            ['Cuajo líquido', 0.02], ['Sal industrial', 2.0],
        ],
        'Yogurt natural' => [
            // azúcar: las guías ponen 4–10 %; 95 L de yogurt por cada 100 L de leche
            ['Azúcar blanca', 8], ['Cultivo láctico', 0.01],
            ['Saborizante fresa', 0.3], ['Envase 1 L', 95], // 95 L de yogurt por cada 100 L
        ],
    ];

    public const PASOS = [
        'Queso Paria' => [
            'Filtrar la leche aceptada y pasteurizar a 63–65 °C por 30 minutos.',
            'Enfriar a 36 °C, agregar el cloruro de calcio y el cultivo láctico y dejar 30 minutos.',
            'Llevar a 38 °C, agregar el cuajo, mover 1 minuto y dejar en reposo 35–40 minutos hasta que cuaje.',
            'Cortar la cuajada en cubos de 1–2 cm y batir suave por 15 minutos.',
            'Sacar un tercio del suero y salar la cuajada: 2 kg de sal por cada 100 L, disuelta en 3 veces su peso de agua.',
            'Llenar los moldes de 1 kg y prensar 4–6 horas, volteando a la mitad.',
            'Orear 1–2 días en lugar fresco y empacar con film.',
        ],
        'Yogurt natural' => [
            'Filtrar la leche aceptada y disolver el azúcar mientras se calienta.',
            'Pasteurizar a 85 °C por 10 minutos.',
            'Enfriar a 42–45 °C y agregar el cultivo láctico.',
            'Incubar a 42–45 °C por 4–5 horas, sin mover, hasta que quede firme (pH 4,4–4,6).',
            'Enfriar a 4–6 °C, batir y agregar el saborizante.',
            'Envasar en botellas de 1 L y guardar en frío.',
        ],
    ];

    /** Carga las fórmulas y pasos iniciales en los productos que todavía no los tienen. */
    public static function cargarIniciales(): void
    {
        $insumos = DB::table('insumos')->pluck('id', 'nombre');
        foreach (DB::table('productos')->get() as $p) {
            if (isset(self::PASOS[$p->nombre]) && ! $p->procedimiento) {
                DB::table('productos')->where('id', $p->id)->update([
                    'procedimiento' => json_encode(self::PASOS[$p->nombre], JSON_UNESCAPED_UNICODE),
                ]);
            }
            if (! isset(self::INICIALES[$p->nombre])) continue;
            if (DB::table('recetas_insumo')->where('producto_id', $p->id)->exists()) continue;
            foreach (self::INICIALES[$p->nombre] as [$insumo, $cantidad]) {
                if (! isset($insumos[$insumo])) continue;
                DB::table('recetas_insumo')->insert([
                    'producto_id' => $p->id, 'insumo_id' => $insumos[$insumo], 'cantidad_por_100l' => $cantidad,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Entregas aceptadas de los últimos 3 días a las que les queda leche
     * ("restante": lo que no entró a un lote ni se perdió).
     */
    public static function entregasDisponibles()
    {
        return Mermas::entregasConLeche(Carbon::today('America/Lima')->subDays(2)->toDateString());
    }

    public static function litrosEnTanque(): float
    {
        return round((float) self::entregasDisponibles()->get()->sum('restante'), 2);
    }

    /**
     * Cada producto con su fórmula, cuántos litros alcanzan con el stock de hoy
     * y lo que falta para procesar toda la leche del tanque.
     */
    public static function productos(?float $tanque = null): array
    {
        $tanque ??= self::litrosEnTanque();
        $receta = DB::table('recetas_insumo as r')->join('insumos as i', 'i.id', '=', 'r.insumo_id')
            ->select('r.producto_id', 'i.id', 'i.nombre', 'i.unidad', 'i.stock', 'i.stock_minimo', 'r.cantidad_por_100l')
            ->orderBy('i.nombre')->get()->groupBy('producto_id');

        return DB::table('productos')->orderBy('id')->get()->map(function ($p) use ($receta, $tanque) {
            $insumos = ($receta[$p->id] ?? collect())->map(function ($i) use ($tanque) {
                $por100 = (float) $i->cantidad_por_100l;
                $necesita = round($por100 * $tanque / 100, 3);
                return [
                    'id' => $i->id, 'nombre' => $i->nombre, 'unidad' => $i->unidad,
                    'por_100l' => $por100, 'stock' => (float) $i->stock, 'minimo' => (float) $i->stock_minimo,
                    'alcanza_litros' => $por100 > 0 ? floor((float) $i->stock / $por100 * 100) : null,
                    'para_tanque' => $necesita,
                    'falta' => max(0, round($necesita - (float) $i->stock, 3)),
                ];
            })->values();
            $alcances = $insumos->pluck('alcanza_litros')->filter(fn ($x) => $x !== null);

            return [
                'id' => $p->id, 'nombre' => $p->nombre, 'unidad' => $p->unidad,
                'rendimiento_min' => (float) $p->rendimiento_min, 'rendimiento_max' => (float) $p->rendimiento_max,
                'vida_util_dias' => $p->vida_util_dias,
                'procedimiento' => json_decode($p->procedimiento ?? '[]', true) ?: [],
                'insumos' => $insumos,
                // litros de leche que se pueden procesar con el stock de hoy (null = la fórmula no usa insumos)
                'alcanza_litros' => $alcances->isEmpty() ? null : $alcances->min(),
            ];
        })->all();
    }

    /**
     * Avisos para el operario y Compras:
     *  FALTA       el stock no alcanza para procesar la leche del tanque (o ni 100 L si está vacío)
     *  BAJO_MINIMO el insumo está bajo su stock mínimo
     */
    public static function avisos(?array $productos = null, ?float $tanque = null): array
    {
        $tanque ??= self::litrosEnTanque();
        $productos ??= self::productos($tanque);
        $base = max($tanque, 100);
        $avisos = [];
        $conFalta = [];

        foreach ($productos as $p) {
            foreach ($p['insumos'] as $i) {
                $necesita = round($i['por_100l'] * $base / 100, 3);
                if ($i['stock'] >= $necesita) continue;
                $conFalta[$i['id']] = true;
                $para = $tanque > 0 ? 'los '.self::numero($tanque).' L del tanque' : '100 L de leche';
                $avisos[] = [
                    'nivel' => 'FALTA', 'insumo_id' => $i['id'], 'insumo' => $i['nombre'], 'producto' => $p['nombre'],
                    'unidad' => $i['unidad'], 'stock' => $i['stock'], 'necesita' => $necesita,
                    'comprar' => round($necesita - $i['stock'] + $i['minimo'], 3),
                    'texto' => $i['stock'] <= 0
                        ? "No hay {$i['nombre']}: no se puede hacer {$p['nombre']}."
                        : "Falta {$i['nombre']} para {$p['nombre']}: hay ".self::numero($i['stock'])." {$i['unidad']} y para $para se necesitan "
                          .self::numero($necesita)." {$i['unidad']} (alcanza para ".self::numero($i['alcanza_litros'])." L).",
                ];
            }
        }

        foreach (DB::table('insumos')->whereColumn('stock', '<', 'stock_minimo')->orderBy('nombre')->get() as $i) {
            if (isset($conFalta[$i->id])) continue;
            $avisos[] = [
                'nivel' => 'BAJO_MINIMO', 'insumo_id' => $i->id, 'insumo' => $i->nombre, 'producto' => null,
                'unidad' => $i->unidad, 'stock' => (float) $i->stock, 'necesita' => (float) $i->stock_minimo,
                'comprar' => round((float) $i->stock_minimo * 2 - (float) $i->stock, 3),
                'texto' => "{$i->nombre} está bajo el mínimo: hay ".self::numero($i->stock)." {$i->unidad} y el mínimo es "
                    .self::numero($i->stock_minimo)." {$i->unidad}.",
            ];
        }

        return $avisos;
    }

    private static function numero($x): string
    {
        return rtrim(rtrim(number_format((float) $x, 3, '.', ','), '0'), '.');
    }
}
