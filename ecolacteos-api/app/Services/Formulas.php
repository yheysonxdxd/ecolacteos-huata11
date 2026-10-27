<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fórmulas de producción (insumos por cada 100 L de leche y pasos) y los
 * avisos de insumos que no alcanzan: "falta azúcar para el yogurt".
 *
 * Sabores y variedades: un producto con base_id (Yogurt de fresa) usa la
 * fórmula, los pasos, el rendimiento y la vida útil de su base (Yogurt
 * natural) y solo agrega lo suyo (el saborizante) y un paso más. Si se cambia
 * la base, cambian todos sus sabores. Cada sabor tiene su propio stock y precio.
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
            // sin saborizante: los sabores (fresa…) van aparte, ver SABORES
            ['Azúcar blanca', 8], ['Cultivo láctico', 0.01],
            ['Envase 1 L', 95], // 95 L de yogurt por cada 100 L
            ['Sorbato de potasio', 0.05], // 0,05 %: evita hongos y levaduras, el yogurt dura más
        ],
        // queso semiduro tipo suizo (Flüeler y Marbach, Guía para la elaboración de quesos, CEDEPAS,
        // 6.3): CaCl2 2 g por 10 L; cuajo líquido 8–12 g por 100 L. La sal va en salmuera aparte.
        'Queso andino' => [
            ['Cloruro de calcio', 0.02], ['Cultivo láctico', 0.005], // cultivo: el de la planta, según su sobre
            ['Cuajo líquido', 0.01],
        ],
        // informe de elaboración de queso mantecoso (Ing. Industrias Alimentarias, Cajamarca, 2010):
        // CaCl2 20 g y sal 0,5 kg por 100 L (2–2,5 % del quesillo); cultivo 1 g por 100 L (UNT Huamachuco)
        'Queso mantecoso' => [
            ['Cloruro de calcio', 0.02], ['Cultivo láctico', 0.001],
            ['Cuajo líquido', 0.01], ['Sal industrial', 0.5],
        ],
    ];

    /** De dónde salen las fórmulas y pasos (la ficha de la receta lo muestra). Un sabor usa la de su base. */
    public const FUENTES = [
        'Queso Paria' => ['texto' => 'Guías técnicas de queso Paria de Puno', 'url' => null],
        'Yogurt natural' => ['texto' => 'Guías técnicas de yogurt (azúcar 4–10 %, sorbato 0,05 %)', 'url' => null],
        'Queso andino' => ['texto' => 'Flüeler y Marbach, Guía para la elaboración de quesos (CEDEPAS), queso semiduro tipo suizo',
            'url' => 'https://www.cedepas.org.pe/sites/default/files/guia_para_la_elaboracion_de_quesos_web.pdf'],
        'Queso mantecoso' => ['texto' => 'Informe de elaboración de queso mantecoso, Ing. Industrias Alimentarias, Cajamarca (2010)',
            'url' => 'https://idoc.pub/documents/informe-deelaboracion-de-queso-mantecoso-1-en5kwj3j51no'],
    ];

    /**
     * Sabores iniciales: base => [nombre, sabor, insumo, cantidad por 100 L].
     * Fresa: la misma cantidad que llevaba antes el yogurt (0,3 por 100 L).
     */
    public const SABORES = [
        'Yogurt natural' => [['Yogurt de fresa', 'Fresa', 'Saborizante fresa', 0.3]],
    ];

    /** Antes el yogurt natural llevaba el saborizante en este paso. */
    private const PASO_CON_SABORIZANTE = 'Enfriar a 4–6 °C, batir y agregar el saborizante y el sorbato de potasio (disuelto antes en un poco de leche).';

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
            'Enfriar a 4–6 °C, batir y agregar el sorbato de potasio (disuelto antes en un poco de leche).',
            'Envasar en botellas de 1 L y guardar en frío.',
        ],
        'Queso andino' => [
            'Filtrar la leche aceptada (pH mayor a 6,20) y agregar 10 % de agua (10 L por cada 100 L).',
            'Pasteurizar a 65 °C por 30 minutos y enfriar a 35 °C.',
            'Agregar el cultivo láctico y el cloruro de calcio (20 g por cada 100 L) y dejar madurar al menos 30 minutos.',
            'Agregar el cuajo y dejar cuajar 25–40 minutos (empieza a flocular a los 10–20 minutos).',
            'Cortar en granos del tamaño de un maíz, agitar y sacar el 30 % del suero (unos 30 minutos).',
            'Calentar unos 20 minutos agregando 20 % de agua caliente a 60 °C, batiendo, hasta que la cuajada llegue a 39–42 °C.',
            'Moldear rápido, molde por molde, y prensar volteando a 35–40 °C durante 20 horas.',
            'Salar en salmuera al 22 % (25 kg de sal por 100 L de salmuera, a 12–16 °C): 6–8 horas por cada kilo de queso.',
            'Secar 2 días y madurar a 12–15 °C con unos 90 % de humedad, de 2 a 15 semanas.',
        ],
        'Queso mantecoso' => [
            'Filtrar la leche aceptada y pasteurizar a 70 °C por 15 minutos.',
            'Enfriar a 30–35 °C (nunca más de 35 °C) y agregar el cultivo, el cloruro de calcio y el cuajo; dejar cuajar unos 45 minutos.',
            'Cortar en cubos de 3–4 cm, reposar 5 minutos y batir suave 15–20 minutos.',
            'Sacar el suero hasta dos tercios de la tina, agregar agua a 70–75 °C hasta que la cuajada llegue a 37 °C y batir otros 15–20 minutos.',
            'Sacar todo el suero: esa cuajada es el quesillo. Ponerlo en costalillos de 20–25 kg y desaguarlo hasta 48 horas en agua, con 4–6 cambios, volteando los costales en cada cambio.',
            'Prensar el quesillo (solo, unas 12 horas) hasta que no se pegue entre los dedos ni se desmorone.',
            'Moler el quesillo, agregar la sal (2–2,5 % del peso del quesillo: 0,5 kg por cada 100 L de leche) y amasar hasta que quede una masa pareja y pastosa.',
            'Moldear (15 × 10 × 6 cm), voltear a los 30 minutos, envolver en papel poligrasa y guardar a 2–5 °C al menos 6 horas antes de vender. Dura unas 2 semanas en frío.',
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
        // una migración vieja también carga las fórmulas, antes de que existan los sabores
        if (\Illuminate\Support\Facades\Schema::hasColumn('productos', 'base_id')) self::separarSabores();
    }

    /**
     * Saca los sabores de su base (el natural queda sin saborizante) y crea cada
     * sabor con lo suyo y los mismos precios de la base. Se puede correr varias veces.
     */
    public static function separarSabores(): void
    {
        foreach (self::SABORES as $baseNombre => $sabores) {
            $base = DB::table('productos')->where('nombre', $baseNombre)->whereNull('base_id')->first();
            if (! $base) continue;
            foreach ($sabores as [$nombre, $sabor, $insumoNombre, $cantidad]) {
                $insumo = DB::table('insumos')->where('nombre', $insumoNombre)->first();
                if (! $insumo) continue;
                $enBase = DB::table('recetas_insumo')->where('producto_id', $base->id)->where('insumo_id', $insumo->id)->first();
                if ($enBase) {
                    $cantidad = (float) $enBase->cantidad_por_100l; // la que tenía la planta
                    DB::table('recetas_insumo')->where('id', $enBase->id)->delete();
                }
                if (! DB::table('productos')->where('nombre', $nombre)->exists()) {
                    self::crearSabor($base, $nombre, $sabor, $insumo, $cantidad, null, copiarPrecios: true);
                }
            }
            // el paso de la base ya no menciona el saborizante
            $pasos = json_decode($base->procedimiento ?? '[]', true) ?: [];
            $nuevos = array_map(fn ($x) => $x === self::PASO_CON_SABORIZANTE ? self::PASOS[$baseNombre][4] : $x, $pasos);
            if ($nuevos !== $pasos) {
                DB::table('productos')->where('id', $base->id)->update(['procedimiento' => json_encode($nuevos, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    /** Paso que se agrega al de la base: dónde va el saborizante o lo que lleva la variedad. */
    public static function pasoSabor(string $tipo, string $sabor, string $insumo, float $cantidad, string $unidad): string
    {
        $cuanto = self::numero($cantidad)." $unidad de $insumo por cada 100 L";
        $sabor = mb_strtolower($sabor);
        return match ($tipo) {
            'YOGURT' => "Sabor $sabor: al batir, agregar $cuanto y mezclar bien antes de envasar.",
            'QUESO'  => "Variedad $sabor: al salar la cuajada, mezclar $cuanto.",
            default  => "$sabor: agregar $cuanto.",
        };
    }

    /**
     * Crea un sabor o variedad de [base]: copia sus datos (tipo, unidad, rendimiento,
     * vida útil) y guarda solo su insumo propio. Devuelve el id del producto nuevo.
     */
    public static function crearSabor(object $base, string $nombre, string $sabor, object $insumo, float $cantidad,
        ?string $paso, bool $copiarPrecios = false): int
    {
        $id = DB::table('productos')->insertGetId([
            'nombre' => $nombre, 'tipo' => $base->tipo, 'unidad' => $base->unidad,
            'rendimiento_min' => $base->rendimiento_min, 'rendimiento_max' => $base->rendimiento_max,
            'vida_util_dias' => $base->vida_util_dias, 'activo' => true,
            'base_id' => $base->id, 'variante' => $sabor,
            'paso_variante' => $paso ?: self::pasoSabor($base->tipo, $sabor, $insumo->nombre, $cantidad, $insumo->unidad),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('recetas_insumo')->insert([
            'producto_id' => $id, 'insumo_id' => $insumo->id, 'cantidad_por_100l' => $cantidad,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($copiarPrecios) {
            foreach (DB::table('precios_venta')->where('producto_id', $base->id)->whereNull('vigente_hasta')->get() as $pv) {
                DB::table('precios_venta')->insert([
                    'producto_id' => $id, 'tipo_cliente' => $pv->tipo_cliente, 'precio' => $pv->precio,
                    'vigente_desde' => now('America/Lima')->toDateString(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        return $id;
    }

    /**
     * Fórmula que de verdad se usa, por producto: la de su base más lo suyo
     * (si el sabor pone un insumo que la base ya tiene, vale la cantidad del sabor).
     * Cada fila: id, nombre, unidad, stock, stock_minimo, cantidad_por_100l.
     *
     * @param  int[]|null  $productos  null = todos
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection>
     */
    public static function recetas(?array $productos = null)
    {
        $prods = DB::table('productos')->when($productos, fn ($q) => $q->whereIn('id', $productos))->get(['id', 'base_id']);
        $ids = $prods->pluck('id')->merge($prods->pluck('base_id')->filter())->unique()->values()->all();
        $filas = DB::table('recetas_insumo as r')->join('insumos as i', 'i.id', '=', 'r.insumo_id')
            ->whereIn('r.producto_id', $ids)
            ->select('r.producto_id', 'i.id', 'i.nombre', 'i.unidad', 'i.stock', 'i.stock_minimo', 'r.cantidad_por_100l')
            ->orderBy('i.nombre')->get()->groupBy('producto_id');

        return $prods->mapWithKeys(fn ($p) => [$p->id => collect($p->base_id ? $filas[$p->base_id] ?? [] : [])
            ->keyBy('id')->replace(collect($filas[$p->id] ?? [])->keyBy('id'))->sortBy('nombre')->values()]);
    }

    /** Cuánto se usa de un insumo para [litros] de leche. Lo que se cuenta por unidad (envases) va entero, hacia arriba. */
    public static function cantidad(float $por100, float $litros, string $unidad): float
    {
        $cant = $por100 * $litros / 100;
        return in_array(mb_strtolower(trim($unidad)), ['un', 'und', 'unidad', 'unidades'], true) ? ceil($cant - 1e-9) : round($cant, 3);
    }

    /** Pasos de un producto: los de su base y, si es un sabor, el paso del sabor al final. */
    public static function pasos(object $p, ?object $base = null): array
    {
        if (! $p->base_id) return json_decode($p->procedimiento ?? '[]', true) ?: [];
        $base ??= DB::table('productos')->find($p->base_id);
        return array_values(array_filter([...(json_decode($base->procedimiento ?? '[]', true) ?: []), $p->paso_variante]));
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
        $receta = self::recetas();
        $todos = DB::table('productos')->get()->keyBy('id');

        // solo lo que se sigue produciendo (un producto que se dejó de hacer no sale aquí);
        // cada base seguida de sus sabores
        return $todos->where('activo', true)
            ->sortBy(fn ($p) => [$p->base_id ?? $p->id, $p->base_id ? 1 : 0, $p->nombre])->values()
            ->map(function ($p) use ($receta, $tanque, $todos) {
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
                'id' => $p->id, 'nombre' => $p->nombre, 'tipo' => $p->tipo, 'unidad' => $p->unidad,
                'rendimiento_min' => (float) $p->rendimiento_min, 'rendimiento_max' => (float) $p->rendimiento_max,
                'vida_util_dias' => $p->vida_util_dias,
                'fuente' => self::FUENTES[$p->nombre] ?? ($p->base_id ? self::FUENTES[$todos[$p->base_id]->nombre ?? ''] ?? null : null),
                'procedimiento' => self::pasos($p, $p->base_id ? $todos[$p->base_id] ?? null : null),
                // sabor: de qué base sale, cómo se llama el sabor y qué le agrega a la base
                'base_id' => $p->base_id, 'base' => $p->base_id ? ($todos[$p->base_id]->nombre ?? null) : null,
                'variante' => $p->variante, 'paso_variante' => $p->paso_variante,
                'propios' => $p->base_id ? DB::table('recetas_insumo')->where('producto_id', $p->id)->get(['insumo_id', 'cantidad_por_100l'])
                    ->map(fn ($x) => ['insumo_id' => $x->insumo_id, 'por_100l' => (float) $x->cantidad_por_100l])->values() : [],
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

        // un aviso por insumo, aunque lo usen varios productos (el azúcar de todos los sabores del yogurt)
        $faltas = [];
        foreach ($productos as $p) {
            foreach ($p['insumos'] as $i) {
                $necesita = round($i['por_100l'] * $base / 100, 3);
                if ($i['stock'] >= $necesita) continue;
                $f = $faltas[$i['id']] ??= ['i' => $i, 'necesita' => 0.0, 'productos' => []];
                $faltas[$i['id']]['necesita'] = max($f['necesita'], $necesita);
                $faltas[$i['id']]['productos'][] = $p['nombre'];
            }
        }
        $para = $tanque > 0 ? 'los '.self::numero($tanque).' L del tanque' : '100 L de leche';
        foreach ($faltas as $id => ['i' => $i, 'necesita' => $necesita, 'productos' => $prods]) {
            $conFalta[$id] = true;
            $quien = implode(', ', array_slice($prods, 0, -1)).(count($prods) > 1 ? ' y ' : '').end($prods);
            $avisos[] = [
                'nivel' => 'FALTA', 'insumo_id' => $i['id'], 'insumo' => $i['nombre'], 'producto' => $quien,
                'unidad' => $i['unidad'], 'stock' => $i['stock'], 'necesita' => $necesita,
                'comprar' => round($necesita - $i['stock'] + $i['minimo'], 3),
                'texto' => $i['stock'] <= 0
                    ? "No hay {$i['nombre']}: no se puede hacer $quien."
                    : "Falta {$i['nombre']} para $quien: hay ".self::numero($i['stock'])." {$i['unidad']} y para $para se necesitan "
                      .self::numero($necesita)." {$i['unidad']} (alcanza para ".self::numero($i['alcanza_litros'])." L).",
            ];
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

    public static function numero($x): string
    {
        return rtrim(rtrim(number_format((float) $x, 3, '.', ','), '0'), '.');
    }
}
