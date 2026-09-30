<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Operario de planta: recetas, leche disponible, iniciar y cerrar lotes. */
class PlantaAppController extends Controller
{
    /** Entregas aceptadas de los últimos 3 días que todavía no entraron a un lote. */
    private function entregasDisponibles()
    {
        return DB::table('entregas')
            ->where('estado_calidad', 'ACEPTADO')->where('ausente', false)
            ->where('fecha', '>=', Carbon::today('America/Lima')->subDays(2)->toDateString())
            ->whereNotIn('id', DB::table('lote_entrega')->select('entrega_id'))
            ->orderBy('fecha')->orderBy('id');
    }

    private function recetas()
    {
        $insumos = DB::table('recetas_insumo as r')->join('insumos as i', 'i.id', '=', 'r.insumo_id')
            ->select('r.producto_id', 'i.id', 'i.nombre', 'i.unidad', 'i.stock', 'r.cantidad_por_100l')
            ->get()->groupBy('producto_id');

        return DB::table('productos')->orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id, 'nombre' => $p->nombre, 'unidad' => $p->unidad,
            'por_100l' => round(($p->rendimiento_min + $p->rendimiento_max) / 2 * 100, 2),
            'min_100l' => round($p->rendimiento_min * 100, 2), 'max_100l' => round($p->rendimiento_max * 100, 2),
            'vida_util_dias' => $p->vida_util_dias,
            'insumos' => ($insumos[$p->id] ?? collect())->map(fn ($i) => [
                'id' => $i->id, 'nombre' => $i->nombre, 'unidad' => $i->unidad,
                'por_100l' => (float) $i->cantidad_por_100l, 'stock' => (float) $i->stock,
            ])->values(),
        ]);
    }

    /** GET /api/v1/planta/recetas — recetas y leche disponible en el tanque. */
    public function recetasYTanque()
    {
        return response()->json([
            'tanque' => round((float) $this->entregasDisponibles()->sum('litros'), 2),
            'recetas' => $this->recetas(),
        ]);
    }

    /** GET /api/v1/planta/lotes — últimos 15 lotes, los abiertos primero. */
    public function lotes()
    {
        $filas = DB::table('lotes as l')->join('productos as p', 'p.id', '=', 'l.producto_id')
            ->orderByRaw("l.estado = 'EN_PROCESO' DESC")->orderByDesc('l.iniciado_en')->limit(15)
            ->select('l.id', 'l.codigo', 'l.estado', 'l.litros_leche', 'l.esperado_min', 'l.esperado_max',
                'l.obtenido', 'l.desvio_pct', 'l.fuera_referencia', 'l.vence_el', 'l.iniciado_en',
                'p.nombre as producto', 'p.unidad')
            ->get();

        return response()->json(['lotes' => $filas]);
    }

    /**
     * POST /api/v1/planta/lotes { producto_id, litros }
     * Toma la leche aceptada más antigua, descuenta insumos según receta y abre el lote.
     */
    public function iniciar(Request $r)
    {
        $data = $r->validate([
            'producto_id' => ['required', 'exists:productos,id'],
            'litros'      => ['required', 'numeric', 'min:1'],
        ]);

        return DB::transaction(function () use ($data, $r) {
            $prod = DB::table('productos')->find($data['producto_id']);
            $litros = (float) $data['litros'];

            $disponibles = $this->entregasDisponibles()->lockForUpdate()->get();
            abort_if($disponibles->sum('litros') < $litros, 422,
                'Solo hay '.$disponibles->sum('litros').' L de leche aceptada disponible.');

            $receta = DB::table('recetas_insumo as r')->join('insumos as i', 'i.id', '=', 'r.insumo_id')
                ->where('r.producto_id', $prod->id)->select('i.id', 'i.nombre', 'i.stock', 'r.cantidad_por_100l')->get();
            foreach ($receta as $i) {
                $necesita = $i->cantidad_por_100l * $litros / 100;
                abort_if($i->stock < $necesita, 422, "Falta {$i->nombre}: se necesita ".round($necesita, 3).' y hay '.(float) $i->stock);
            }

            $ultimo = DB::table('lotes')->where('codigo', 'like', 'L-%')->get('codigo')
                ->map(fn ($l) => (int) substr($l->codigo, 2))->max() ?? 2400;
            $codigo = 'L-'.($ultimo + 1);
            $ahora = now();

            $loteId = DB::table('lotes')->insertGetId([
                'uuid' => (string) Str::uuid(), 'codigo' => $codigo, 'producto_id' => $prod->id,
                'litros_leche' => $litros,
                'esperado_min' => round($litros * $prod->rendimiento_min, 2),
                'esperado_max' => round($litros * $prod->rendimiento_max, 2),
                'estado' => 'EN_PROCESO',
                'vence_el' => Carbon::today('America/Lima')->addDays($prod->vida_util_dias)->toDateString(),
                'operario_id' => $r->user()->id, 'iniciado_en' => $ahora,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

            // trazabilidad: qué entregas entran al lote (la última puede entrar en parte)
            $falta = $litros;
            foreach ($disponibles as $e) {
                if ($falta <= 0) break;
                $usa = min($falta, (float) $e->litros);
                DB::table('lote_entrega')->insert([
                    'lote_id' => $loteId, 'entrega_id' => $e->id, 'litros' => $usa,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
                $falta -= $usa;
            }

            foreach ($receta as $i) {
                $cant = round($i->cantidad_por_100l * $litros / 100, 3);
                DB::table('insumos')->where('id', $i->id)->decrement('stock', $cant);
                DB::table('movimientos_insumo')->insert([
                    'uuid' => (string) Str::uuid(), 'insumo_id' => $i->id, 'cantidad' => -$cant,
                    'referencia' => "$codigo · inicio de lote", 'origen_type' => 'lote', 'origen_id' => $loteId,
                    'registrado_por' => $r->user()->id, 'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }

            return response()->json(['codigo' => $codigo, 'id' => $loteId], 201);
        });
    }

    /** POST /api/v1/planta/lotes/{id}/cerrar { obtenido } */
    public function cerrar(Request $r, int $id)
    {
        $data = $r->validate(['obtenido' => ['required', 'numeric', 'min:0']]);
        $l = DB::table('lotes')->find($id);
        abort_if(! $l, 404, 'Lote no encontrado');
        abort_if($l->estado !== 'EN_PROCESO', 422, 'El lote ya está cerrado');

        $obt = (float) $data['obtenido'];
        $medio = ($l->esperado_min + $l->esperado_max) / 2;
        $desvio = $medio > 0 ? round(($obt - $medio) / $medio * 100, 2) : 0;
        DB::table('lotes')->where('id', $id)->update([
            'obtenido' => $obt, 'desvio_pct' => $desvio,
            'fuera_referencia' => $obt < $l->esperado_min * 0.97 || $obt > $l->esperado_max * 1.03,
            'estado' => 'TERMINADO', 'cerrado_en' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['codigo' => $l->codigo, 'desvio_pct' => $desvio]);
    }
}
