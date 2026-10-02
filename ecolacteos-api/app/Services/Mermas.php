<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Pérdidas de leche y de producto terminado, y el stock que queda después de ellas. */
class Mermas
{
    public const MOTIVOS = [
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
    public static function stockProducto(int $productoId): float
    {
        $producido = (float) DB::table('lotes')->where('producto_id', $productoId)->where('estado', 'TERMINADO')->sum('obtenido');
        $vendido = (float) DB::table('ventas')->where('producto_id', $productoId)->sum('cantidad');
        $perdido = (float) DB::table('mermas')->where('tipo', 'PRODUCTO')->where('producto_id', $productoId)->sum('cantidad');

        return round($producido - $vendido - $perdido, 2);
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
            } else {
                $stock = self::stockProducto((int) $d['producto_id']);
                abort_if($cant > $stock + 0.005, 422, "Solo hay $stock en stock.");
            }

            $id = DB::table('mermas')->insertGetId([
                'uuid' => (string) Str::uuid(), 'tipo' => $d['tipo'],
                'producto_id' => $d['tipo'] === 'PRODUCTO' ? $d['producto_id'] : null,
                'lote_id' => $d['lote_id'] ?? null, 'cantidad' => $cant, 'motivo' => $d['motivo'],
                'detalle' => isset($d['detalle']) ? trim($d['detalle']) ?: null : null,
                'registrado_por' => $usuarioId, 'fecha' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

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
