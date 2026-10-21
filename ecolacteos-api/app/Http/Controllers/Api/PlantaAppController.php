<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Formulas;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
                'p.nombre as producto', 'p.unidad', 'p.procedimiento', 'p.base_id', 'p.paso_variante')
            ->get();

        // fórmula con la que se hizo cada lote: insumos que se le descontaron y pasos
        $usados = DB::table('movimientos_insumo as m')->join('insumos as i', 'i.id', '=', 'm.insumo_id')
            ->where('m.origen_type', 'lote')->whereIn('m.origen_id', $filas->pluck('id'))->orderBy('i.nombre')
            ->get(['m.origen_id', 'i.nombre', 'i.unidad', 'm.cantidad'])->groupBy('origen_id');
        $filas = $filas->map(function ($l) use ($usados) {
            $l->procedimiento = Formulas::pasos($l);
            unset($l->base_id, $l->paso_variante);
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
            'producto_id' => ['required', Rule::exists('productos', 'id')->where('activo', true)],
            'litros'      => ['required', 'numeric', 'min:1'],
        ]);

        $lote = $this->abrirLotes([[(int) $data['producto_id'], (float) $data['litros']]], $r->user()->id)[0];

        return response()->json(['codigo' => $lote['codigo'], 'id' => $lote['id']], 201);
    }

    /**
     * POST /api/v1/planta/lotes/varios { lotes: [{ producto_id, litros }, ...] }
     * Varios productos a la vez (ej.: queso y yogurt con la leche del día). La leche y
     * los insumos que comparten se revisan sumados: o se abren todos los lotes o ninguno.
     */
    public function iniciarVarios(Request $r)
    {
        $data = $r->validate([
            'lotes' => ['required', 'array', 'min:1', 'max:10'],
            'lotes.*.producto_id' => ['required', 'distinct', Rule::exists('productos', 'id')->where('activo', true)],
            'lotes.*.litros' => ['required', 'numeric', 'min:1'],
        ], [
            'lotes.required' => 'Elige al menos un producto y cuánto hacer.',
            'lotes.*.producto_id.distinct' => 'Un producto está repetido: pon todo lo que quieres hacer de él en una sola fila.',
        ]);

        $lotes = $this->abrirLotes(array_map(fn ($l) => [(int) $l['producto_id'], (float) $l['litros']], $data['lotes']), $r->user()->id);

        return response()->json(['lotes' => $lotes], 201);
    }

    /**
     * Abre uno o varios lotes en una sola transacción: toma la leche aceptada más
     * antigua, descuenta los insumos según cada receta y deja la trazabilidad.
     *
     * @param  array<int, array{0: int, 1: float}>  $pedidos  [producto_id, litros]
     * @return array<int, array{id: int, codigo: string, producto: string, litros: float}>
     */
    private function abrirLotes(array $pedidos, int $usuarioId): array
    {
        return DB::transaction(function () use ($pedidos, $usuarioId) {
            $varios = count($pedidos) > 1;
            $juntos = $varios ? ' para hacerlos juntos' : '';
            $productos = DB::table('productos')->whereIn('id', array_column($pedidos, 0))->get()->keyBy('id');

            $disponibles = $this->entregasDisponibles()->lockForUpdate()->get();
            $hay = round($disponibles->sum('restante'), 2);
            $total = array_sum(array_column($pedidos, 1));
            abort_if($hay < $total, 422, $varios
                ? "Para hacerlos juntos se necesitan {$total} L de leche y solo hay {$hay} L de leche aceptada disponible."
                : "Solo hay {$hay} L de leche aceptada disponible.");

            // lo que necesita cada insumo, sumando los productos que lo comparten (ej.: cultivo láctico)
            // la de la base más lo del sabor (Yogurt de fresa = natural + saborizante)
            $recetas = Formulas::recetas(array_column($pedidos, 0));
            $necesita = [];
            foreach ($pedidos as [$prod, $litros]) {
                foreach ($recetas[$prod] ?? [] as $i) {
                    $necesita[$i->id] ??= ['i' => $i, 'cant' => 0.0];
                    $necesita[$i->id]['cant'] += $i->cantidad_por_100l * $litros / 100;
                }
            }
            $faltan = collect($necesita)->filter(fn ($n) => $n['i']->stock < round($n['cant'], 3))
                ->map(fn ($n) => "{$n['i']->nombre} (se necesita ".round($n['cant'], 3)." {$n['i']->unidad}{$juntos} y hay ".(float) $n['i']->stock.')');
            abort_if($faltan->isNotEmpty(), 422, 'Falta '.$faltan->join(', ', ' y ').'. Pide a Compras que reponga.');

            $ultimo = DB::table('lotes')->where('codigo', 'like', 'L-%')->get('codigo')
                ->map(fn ($l) => (int) substr($l->codigo, 2))->max() ?? 2400;
            $ahora = now();
            $restante = $disponibles->mapWithKeys(fn ($e) => [$e->id => (float) $e->restante])->all();
            $abiertos = [];

            foreach ($pedidos as [$prodId, $litros]) {
                $prod = $productos[$prodId];
                $codigo = 'L-'.(++$ultimo);
                $loteId = DB::table('lotes')->insertGetId([
                    'uuid' => (string) Str::uuid(), 'codigo' => $codigo, 'producto_id' => $prod->id,
                    'litros_leche' => $litros,
                    'esperado_min' => round($litros * $prod->rendimiento_min, 2),
                    'esperado_max' => round($litros * $prod->rendimiento_max, 2),
                    'estado' => 'EN_PROCESO',
                    'vence_el' => Carbon::today('America/Lima')->addDays($prod->vida_util_dias)->toDateString(),
                    'operario_id' => $usuarioId, 'iniciado_en' => $ahora,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);

                // trazabilidad: qué entregas entran al lote (la última puede entrar en parte)
                $falta = $litros;
                foreach ($restante as $entregaId => $queda) {
                    if ($falta <= 0) break;
                    if ($queda <= 0) continue;
                    $usa = min($falta, $queda);
                    DB::table('lote_entrega')->insert([
                        'lote_id' => $loteId, 'entrega_id' => $entregaId, 'litros' => $usa,
                        'created_at' => $ahora, 'updated_at' => $ahora,
                    ]);
                    $restante[$entregaId] -= $usa;
                    $falta -= $usa;
                }

                foreach ($recetas[$prodId] ?? [] as $i) {
                    $cant = round($i->cantidad_por_100l * $litros / 100, 3);
                    DB::table('insumos')->where('id', $i->id)->decrement('stock', $cant);
                    DB::table('movimientos_insumo')->insert([
                        'uuid' => (string) Str::uuid(), 'insumo_id' => $i->id, 'cantidad' => -$cant,
                        'referencia' => "$codigo · inicio de lote", 'origen_type' => 'lote', 'origen_id' => $loteId,
                        'registrado_por' => $usuarioId, 'created_at' => $ahora, 'updated_at' => $ahora,
                    ]);
                }
                $abiertos[] = ['id' => $loteId, 'codigo' => $codigo, 'producto' => $prod->nombre, 'litros' => $litros];
            }

            return $abiertos;
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
