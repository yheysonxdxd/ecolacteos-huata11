<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Formulas;
use App\Services\Mermas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Pérdidas de leche (se cortó, se derramó) y de queso/yogurt (se venció, se malogró). */
class MermasController extends Controller
{
    /** GET /api/v1/compras/mermas (y planta/mermas) — motivos, qué hay, lotes y las últimas pérdidas. */
    public function index()
    {
        $productos = DB::table('productos')->orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id, 'nombre' => $p->nombre, 'unidad' => explode(' ', $p->unidad)[0],
            'stock' => Mermas::stockProducto($p->id),
            // lotes de los que puede ser (opcional al registrar)
            'lotes' => DB::table('lotes')->where('producto_id', $p->id)->where('estado', 'TERMINADO')
                ->orderByDesc('cerrado_en')->limit(15)->get(['id', 'codigo', 'vence_el', 'cerrado_en']),
        ]);

        $ultimas = DB::table('mermas as m')->leftJoin('productos as p', 'p.id', '=', 'm.producto_id')
            ->leftJoin('lotes as l', 'l.id', '=', 'm.lote_id')->leftJoin('users as u', 'u.id', '=', 'm.registrado_por')
            ->orderByDesc('m.fecha')->limit(40)
            ->get(['m.id', 'm.tipo', 'm.cantidad', 'm.motivo', 'm.detalle', 'm.fecha', 'p.nombre as producto', 'p.unidad',
                'l.codigo as lote', 'u.name as usuario'])
            ->map(fn ($m) => (array) $m + [
                'motivo_texto' => Mermas::MOTIVOS[$m->tipo][$m->motivo] ?? $m->motivo,
                'que' => $m->tipo === 'LECHE' ? 'Leche' : $m->producto,
                'unidad_corta' => $m->tipo === 'LECHE' ? 'L' : explode(' ', $m->unidad)[0],
            ]);

        return response()->json([
            'motivos' => Mermas::MOTIVOS,
            'leche' => ['sin_usar' => Mermas::lecheSinUsar(), 'tanque' => Formulas::litrosEnTanque()],
            'productos' => $productos,
            'mermas' => $ultimas,
        ]);
    }

    /** POST /api/v1/compras/mermas (y planta/mermas) { tipo, producto_id?, lote_id?, cantidad, motivo, detalle? } */
    public function guardar(Request $r)
    {
        $tipo = $r->input('tipo');
        $data = $r->validate([
            'tipo' => ['required', 'in:LECHE,PRODUCTO'],
            'producto_id' => ['required_if:tipo,PRODUCTO', 'nullable', 'exists:productos,id'],
            'lote_id' => ['nullable', 'exists:lotes,id'],
            'cantidad' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'motivo' => ['required', Rule::in(array_keys(Mermas::MOTIVOS[$tipo] ?? []))],
            'detalle' => ['required_if:motivo,OTRO', 'nullable', 'string', 'max:200'],
        ], [
            'producto_id.required_if' => 'Elige si es queso o yogurt.',
            'motivo.in' => 'Elige el motivo de la pérdida.',
            'detalle.required_if' => 'Escribe qué pasó.',
        ]);

        $id = Mermas::registrar($data, $r->user()->id);

        return response()->json([
            'id' => $id,
            'stock' => $data['tipo'] === 'LECHE' ? Mermas::lecheSinUsar() : Mermas::stockProducto((int) $data['producto_id']),
        ], 201);
    }
}
