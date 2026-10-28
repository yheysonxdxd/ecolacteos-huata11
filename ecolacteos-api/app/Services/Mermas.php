<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Pérdidas de leche, de producto terminado y de insumos, con su valor en soles, y el stock que queda después de ellas. */
class Mermas
{
    public const MOTIVOS = [
        'INSUMO' => [
            'ROTO' => 'Se rompió el saco o la bolsa',
            'MOJADO' => 'Se mojó o se derramó',
            'PLAGA' => 'Plagas o roedores',
            'VENCIDO' => 'Se venció',
            'DEFECTUOSO' => 'Envase dañado o defectuoso',
            'OTRO' => 'Otro motivo',
        ],
        'LECHE' => [
            'CORTADA' => 'Se cortó o se agrió',
            'DERRAMADA' => 'Se derramó',
            'VIEJA' => 'Pasó mucho tiempo sin procesar',
            'OTRO' => 'Otro motivo',
        ],
        'PRODUCTO' => [
            'VENCIDO' => 'Se venció o se pasó',
            'MALOGRADO' => 'Se malogró (hongos, mal olor, hinchado)',
            'DANADO' => 'Envase roto o dañado',
            'MUESTRA' => 'Muestra o degustación',
            'OTRO' => 'Otro motivo',
        ],
    ];

    /** Litros usados de cada entrega: en lotes y en pérdidas. */
    public static function usadoPorEntrega()
    {
        return DB::query()->fromSub(
            DB::table('lote_entrega')->select('entrega_id', 'litros')
                ->unionAll(DB::table('merma_entrega')->select('entrega_id', 'litros')),
            'x'
        )->selectRaw('entrega_id, SUM(litros) as usado')->groupBy('entrega_id');
    }

    /**
     * Entregas aceptadas a las que todavía les queda leche (columna "restante"),
     * la más antigua primero. Sin $desde: todas, aunque sean de hace días.
     */
    public static function entregasConLeche(?string $desde = null)
    {
        return DB::table('entregas as e')
            ->leftJoinSub(self::usadoPorEntrega(), 'u', 'u.entrega_id', '=', 'e.id')
            ->where('e.estado_calidad', 'ACEPTADO')->where('e.ausente', false)
            ->when($desde, fn ($q) => $q->where('e.fecha', '>=', $desde))
            ->whereRaw('e.litros - COALESCE(u.usado, 0) > 0.005')
            ->selectRaw('e.*, e.litros - COALESCE(u.usado, 0) as restante')
            ->orderBy('e.fecha')->orderBy('e.id');
    }

    /** Stock de producto terminado = obtenido en lotes cerrados − vendido − perdido. */
    /**
     * Cuánto costó lo que se perdió, en soles:
     * - leche: el precio que se le pagó al productor ese día;
     * - insumo: lo que se pagó por unidad en la última compra;
     * - producto: lo que costó hacer una unidad de su lote (leche + insumos ÷ lo que salió); sin lote, el último lote cerrado.
     * null si todavía no hay con qué calcularlo (un insumo que nunca se compró, un producto sin lotes).
     */
    public static function valor(string $tipo, ?int $id, ?int $loteId, float $cantidad, $fecha = null): ?float
    {
        if ($tipo === 'LECHE') return round($cantidad * Configuracion::precioLeche($fecha), 2);
        if ($tipo === 'INSUMO') {
            $c = Compras::costosUnitarios()[$id] ?? null;
            return $c === null ? null : round($cantidad * $c, 2);
        }
        $lote = DB::table('lotes')->where('estado', 'TERMINADO')->where('obtenido', '>', 0)
            ->when($loteId, fn ($q) => $q->where('id', $loteId), fn ($q) => $q->where('producto_id', $id)->orderByDesc('cerrado_en'))
            ->first();
        if (! $lote) return null;
        $costos = Compras::costosUnitarios();
        $insumos = DB::table('movimientos_insumo')->where('origen_type', 'lote')->where('origen_id', $lote->id)->get()
            ->sum(fn ($m) => abs((float) $m->cantidad) * ($costos[$m->insumo_id] ?? 0));
        $unidad = ((float) $lote->litros_leche * Configuracion::precioLeche($lote->iniciado_en) + $insumos) / (float) $lote->obtenido;

        return round($cantidad * $unidad, 2);
    }

    public static function stockProducto(int $productoId): float
    {
        $producido = (float) DB::table('lotes')->where('producto_id', $productoId)->where('estado', 'TERMINADO')->sum('obtenido');
        $vendido = (float) DB::table('ventas')->where('producto_id', $productoId)->sum('cantidad');
        $perdido = (float) DB::table('mermas')->where('tipo', 'PRODUCTO')->where('producto_id', $productoId)->sum('cantidad');

        return round($producido - $vendido - $perdido, 2);
    }

    /**
     * Lo que queda de cada lote cerrado, el que vence primero arriba (así se vende
     * primero lo que está por vencer). Cada lote: id, codigo, vence_el, iniciado_en, obtenido, queda, vencido.
     * Lo vendido o perdido sin lote (o de más en un lote) se descuenta de los que vencen
     * primero, así la suma de "queda" es igual al stockProducto.
     */
    public static function lotesConStock(int $productoId)
    {
        $hoy = now('America/Lima')->toDateString();
        $lotes = DB::table('lotes')->where('producto_id', $productoId)->where('estado', 'TERMINADO')
            ->orderBy('vence_el')->orderBy('iniciado_en')->orderBy('id')->get(['id', 'codigo', 'vence_el', 'iniciado_en', 'obtenido']);
        $salio = DB::table('ventas')->where('producto_id', $productoId)->select('lote_id', 'cantidad')
            ->unionAll(DB::table('mermas')->where('tipo', 'PRODUCTO')->where('producto_id', $productoId)->select('lote_id', 'cantidad'))
            ->get()->groupBy(fn ($x) => (int) $x->lote_id)->map(fn ($g) => (float) $g->sum('cantidad'));

        $suelto = (float) $salio->except($lotes->pluck('id')->all())->sum(); // sin lote o de un lote que ya no está
        $lotes = $lotes->map(function ($l) use ($salio, $hoy, &$suelto) {
            $queda = (float) $l->obtenido - ($salio[$l->id] ?? 0);
            if ($queda < 0) { $suelto -= $queda; $queda = 0; }
            return (object) ['id' => $l->id, 'codigo' => $l->codigo, 'vence_el' => $l->vence_el, 'iniciado_en' => $l->iniciado_en,
                'obtenido' => (float) $l->obtenido, 'queda' => $queda, 'vencido' => $l->vence_el < $hoy];
        });
        foreach ($lotes as $l) {
            $usa = min($suelto, $l->queda);
            $l->queda = round($l->queda - $usa, 2);
            $suelto -= $usa;
        }

        return $lotes->filter(fn ($l) => $l->queda > 0.005)->values();
    }

    /** Toda la leche aceptada que todavía no entró a un lote ni se perdió. */
    public static function lecheSinUsar(): float
    {
        return round((float) self::entregasConLeche()->get()->sum('restante'), 2);
    }

    /**
     * Registra la pérdida. La leche sale de las entregas más antiguas primero
     * (la que lleva más tiempo es la que se malogra).
     */
    public static function registrar(array $d, int $usuarioId): int
    {
        return DB::transaction(function () use ($d, $usuarioId) {
            $cant = round((float) $d['cantidad'], 3);
            $ahora = now();

            if ($d['tipo'] === 'LECHE') {
                $entregas = self::entregasConLeche()->lockForUpdate()->get();
                $hay = round((float) $entregas->sum('restante'), 2);
                abort_if($cant > $hay + 0.005, 422, "Solo hay $hay L de leche sin procesar.");
            } elseif ($d['tipo'] === 'INSUMO') {
                $insumo = DB::table('insumos')->lockForUpdate()->find($d['insumo_id']);
                abort_if($cant > (float) $insumo->stock + 0.0005, 422, 'Solo hay '.(float) $insumo->stock." {$insumo->unidad} de {$insumo->nombre}.");
            } else {
                $stock = self::stockProducto((int) $d['producto_id']);
                abort_if($cant > $stock + 0.005, 422, "Solo hay $stock en stock.");
            }

            $esProducto = $d['tipo'] === 'PRODUCTO';
            $id = DB::table('mermas')->insertGetId([
                'uuid' => (string) Str::uuid(), 'tipo' => $d['tipo'],
                'producto_id' => $esProducto ? $d['producto_id'] : null,
                'lote_id' => $esProducto ? ($d['lote_id'] ?? null) : null,
                'insumo_id' => $d['tipo'] === 'INSUMO' ? $d['insumo_id'] : null,
                'cantidad' => $cant, 'motivo' => $d['motivo'],
                'valor' => self::valor($d['tipo'], $d['tipo'] === 'INSUMO' ? (int) $d['insumo_id'] : ($esProducto ? (int) $d['producto_id'] : null),
                    $esProducto ? ($d['lote_id'] ?? null) : null, $cant, $ahora),
                'detalle' => isset($d['detalle']) ? trim($d['detalle']) ?: null : null,
                'registrado_por' => $usuarioId, 'fecha' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

            // el insumo perdido baja su stock y sale en su kardex como una salida más
            if ($d['tipo'] === 'INSUMO') {
                DB::table('insumos')->where('id', $insumo->id)->decrement('stock', $cant);
                DB::table('movimientos_insumo')->insert([
                    'uuid' => (string) Str::uuid(), 'insumo_id' => $insumo->id, 'cantidad' => -$cant,
                    'referencia' => 'Pérdida · '.self::MOTIVOS['INSUMO'][$d['motivo']].(empty($d['detalle']) ? '' : ' · '.trim($d['detalle'])),
                    'origen_type' => 'merma', 'origen_id' => $id,
                    'registrado_por' => $usuarioId, 'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }

            if ($d['tipo'] === 'LECHE') {
                $falta = $cant;
                foreach ($entregas as $e) {
                    if ($falta <= 0.005) break;
                    $usa = round(min($falta, (float) $e->restante), 2);
                    DB::table('merma_entrega')->insert([
                        'merma_id' => $id, 'entrega_id' => $e->id, 'litros' => $usa, 'created_at' => $ahora, 'updated_at' => $ahora,
                    ]);
                    $falta -= $usa;
                }
            }

            return $id;
        });
    }
}
