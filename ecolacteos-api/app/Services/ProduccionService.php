<?php

namespace App\Services;

use App\Models\Entrega;
use App\Models\Insumo;
use App\Models\Lote;
use App\Models\MovimientoInsumo;
use App\Models\Producto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProduccionService
{
    /** Litros aceptados que no han entrado a ningún lote. */
    public function litrosDisponibles(): float
    {
        return (float) Entrega::where('estado_calidad', 'ACEPTADO')
            ->whereDoesntHave('lotes')->sum('litros');
    }

    /**
     * Inicia un lote: valida stock, descuenta insumos y toma los litros de las
     * entregas aceptadas más antiguas (FIFO) para dejar trazabilidad.
     */
    public function iniciar(Producto $producto, float $litros, int $operarioId): Lote
    {
        return DB::transaction(function () use ($producto, $litros, $operarioId) {
            if ($litros > $this->litrosDisponibles()) {
                abort(422, 'No hay tantos litros aceptados disponibles en el tanque.');
            }

            $factor    = $litros / 100;
            $faltantes = [];
            $requerido = [];

            foreach ($producto->recetaInsumos as $r) {
                $need = round($r->cantidad_por_100l * $factor, 3);
                $requerido[$r->insumo_id] = $need;
                if ($need > (float) $r->insumo->stock) {
                    $faltantes[] = $r->insumo->nombre;
                }
            }
            if ($faltantes) {
                abort(422, 'Faltan insumos: ' . implode(', ', $faltantes));
            }

            $lote = Lote::create([
                'uuid'         => (string) Str::uuid(),
                'codigo'       => $this->siguienteCodigo(),
                'producto_id'  => $producto->id,
                'litros_leche' => $litros,
                'esperado_min' => round($litros * $producto->rendimiento_min, 2),
                'esperado_max' => round($litros * $producto->rendimiento_max, 2),
                'estado'       => 'EN_PROCESO',
                'vence_el'     => Carbon::today()->addDays($producto->vida_util_dias),
                'operario_id'  => $operarioId,
                'iniciado_en'  => now(),
            ]);

            // FIFO sobre entregas aceptadas
            $restante = $litros;
            $pendientes = Entrega::where('estado_calidad', 'ACEPTADO')
                ->whereDoesntHave('lotes')->orderBy('fecha')->get();

            foreach ($pendientes as $e) {
                if ($restante <= 0) break;
                $usa = min($restante, (float) $e->litros);
                $lote->entregas()->attach($e->id, ['litros' => $usa]);
                $restante -= $usa;
            }

            foreach ($requerido as $insumoId => $need) {
                $insumo = Insumo::lockForUpdate()->find($insumoId);
                $insumo->decrement('stock', $need);
                MovimientoInsumo::create([
                    'uuid'           => (string) Str::uuid(),
                    'insumo_id'      => $insumoId,
                    'cantidad'       => -$need,
                    'referencia'     => "{$lote->codigo} · inicio de lote",
                    'origen_type'    => Lote::class,
                    'origen_id'      => $lote->id,
                    'registrado_por' => $operarioId,
                ]);
            }

            return $lote->fresh('entregas');
        });
    }

    /**
     * Cierra el lote y verifica el rendimiento contra la referencia del producto
     * (queso Paria: 12 a 13 quesos de 1 kg por cada 100 litros).
     */
    public function cerrar(Lote $lote, float $obtenido): Lote
    {
        $medio = ((float) $lote->esperado_min + (float) $lote->esperado_max) / 2;

        $lote->update([
            'obtenido'         => $obtenido,
            'desvio_pct'       => $medio > 0 ? round((($obtenido - $medio) / $medio) * 100, 2) : null,
            'fuera_referencia' => $obtenido < (float) $lote->esperado_min
                               || $obtenido > (float) $lote->esperado_max,
            'estado'           => 'TERMINADO',
            'cerrado_en'       => now(),
        ]);

        return $lote->fresh();
    }

    private function siguienteCodigo(): string
    {
        $n = (int) (Lote::max('id') ?? 0) + 2419;

        return 'L-' . $n;
    }
}
