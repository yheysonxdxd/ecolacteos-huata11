<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Formulas;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Operario de planta: fórmulas, leche disponible, iniciar y cerrar lotes. */
class PlantaAppController extends Controller
{
    private function entregasDisponibles()
    {
        return Formulas::entregasDisponibles();
    }

    private function recetas(array $productos)
    {
        return collect($productos)->map(fn ($p) => [
            'id' => $p['id'], 'nombre' => $p['nombre'], 'unidad' => $p['unidad'],
            'por_100l' => round(($p['rendimiento_min'] + $p['rendimiento_max']) / 2 * 100, 2),
            'min_100l' => round($p['rendimiento_min'] * 100, 2), 'max_100l' => round($p['rendimiento_max'] * 100, 2),
            'vida_util_dias' => $p['vida_util_dias'],
            'procedimiento' => $p['procedimiento'],
            'alcanza_litros' => $p['alcanza_litros'],
            'insumos' => collect($p['insumos'])->map(fn ($i) => [
                'id' => $i['id'], 'nombre' => $i['nombre'], 'unidad' => $i['unidad'],
                'por_100l' => $i['por_100l'], 'stock' => $i['stock'], 'minimo' => $i['minimo'],
                'alcanza_litros' => $i['alcanza_litros'],
            ])->values(),
        ])->values();
    }

    /** GET /api/v1/planta/recetas — fórmulas, leche disponible en el tanque y avisos de insumos. */
    public function recetasYTanque()
    {
        $tanque = Formulas::litrosEnTanque();
        $productos = Formulas::productos($tanque);

        return response()->json([
            'tanque' => $tanque,
            'recetas' => $this->recetas($productos),
            'avisos' => Formulas::avisos($productos, $tanque),
        ]);
    }

    /** GET /api/v1/planta/lotes — últimos 15 lotes, los abiertos primero. */
    public function lotes()
    {
        $filas = DB::table('lotes as l')->join('productos as p', 'p.id', '=', 'l.producto_id')
            ->orderByRaw("l.estado = 'EN_PROCESO' DESC")->orderByDesc('l.iniciado_en')->limit(15)
            ->select('l.id', 'l.codigo', 'l.estado', 'l.litros_leche', 'l.esperado_min', 'l.esperado_max',
                'l.obtenido', 'l.desvio_pct', 'l.fuera_referencia', 'l.vence_el', 'l.iniciado_en',
                'p.nombre as producto', 'p.unidad', 'p.procedimiento')
            ->get();

        // fórmula con la que se hizo cada lote: insumos que se le descontaron y pasos
        $usados = DB::table('movimientos_insumo as m')->join('insumos as i', 'i.id', '=', 'm.insumo_id')
            ->where('m.origen_type', 'lote')->whereIn('m.origen_id', $filas->pluck('id'))->orderBy('i.nombre')
            ->get(['m.origen_id', 'i.nombre', 'i.unidad', 'm.cantidad'])->groupBy('origen_id');
        $filas = $filas->map(function ($l) use ($usados) {
            $l->procedimiento = json_decode($l->procedimiento ?? '[]', true) ?: [];
            $l->insumos = ($usados[$l->id] ?? collect())->map(fn ($m) => [
                'nombre' => $m->nombre, 'unidad' => $m->unidad, 'cantidad' => round(abs((float) $m->cantidad), 3),
            ])->values();
            return $l;
        });

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
            abort_if(round($disponibles->sum('restante'), 2) < $litros, 422,
                'Solo hay '.round($disponibles->sum('restante'), 2).' L de leche aceptada disponible.');

            $receta = DB::table('recetas_insumo as r')->join('insumos as i', 'i.id', '=', 'r.insumo_id')
                ->where('r.producto_id', $prod->id)->select('i.id', 'i.nombre', 'i.unidad', 'i.stock', 'r.cantidad_por_100l')->get();
            $faltan = $receta->filter(fn ($i) => $i->stock < $i->cantidad_por_100l * $litros / 100)
                ->map(fn ($i) => "{$i->nombre} (se necesita ".round($i->cantidad_por_100l * $litros / 100, 3)
                    ." {$i->unidad} y hay ".(float) $i->stock.')');
            abort_if($faltan->isNotEmpty(), 422, 'Falta '.$faltan->join(', ', ' y ').'. Pide a Compras que reponga.');

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
                $usa = min($falta, (float) $e->restante);
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
