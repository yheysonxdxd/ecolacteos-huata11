<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Compras;
use App\Services\Configuracion;
use App\Services\Formulas;
use App\Services\Mermas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Pérdidas de leche (se cortó, se derramó), de queso/yogurt (se venció, se malogró)
 * y de insumos (se rompió el saco, se mojó la bolsa), cada una con su valor en soles.
 */
class MermasController extends Controller
{
    /** Desde cuántas pérdidas iguales en 30 días se avisa "se repite: revisa cómo se guarda". */
    private const VECES_REPETIDA = 3;

    /** GET /api/v1/compras/mermas (y planta/mermas) — motivos, qué hay, lotes, las últimas pérdidas y lo perdido del mes. */
    public function index()
    {
        $productos = DB::table('productos')->orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id, 'nombre' => $p->nombre, 'tipo' => $p->tipo, 'unidad' => explode(' ', $p->unidad)[0],
            'stock' => Mermas::stockProducto($p->id),
            // lotes de los que puede ser (opcional al registrar): los que todavía tienen algo, lo que vence primero arriba
            'lotes' => Mermas::lotesConStock($p->id)->map(fn ($l) => ['id' => $l->id, 'codigo' => $l->codigo,
                'vence_el' => $l->vence_el, 'queda' => $l->queda, 'vencido' => $l->vencido])->values(),
        ]);
        $costos = Compras::costosUnitarios();
        $insumos = DB::table('insumos')->orderBy('nombre')->get()->map(fn ($i) => [
            'id' => $i->id, 'nombre' => $i->nombre, 'unidad' => $i->unidad, 'stock' => (float) $i->stock,
            'costo' => isset($costos[$i->id]) ? (float) $costos[$i->id] : null, // por unidad, de la última compra
        ]);

        $ultimas = DB::table('mermas as m')->leftJoin('productos as p', 'p.id', '=', 'm.producto_id')
            ->leftJoin('insumos as i', 'i.id', '=', 'm.insumo_id')
            ->leftJoin('lotes as l', 'l.id', '=', 'm.lote_id')->leftJoin('users as u', 'u.id', '=', 'm.registrado_por')
            ->orderByDesc('m.fecha')->limit(40)
            ->get(['m.id', 'm.tipo', 'm.cantidad', 'm.valor', 'm.motivo', 'm.detalle', 'm.fecha', 'm.foto', 'p.nombre as producto', 'p.unidad',
                'i.nombre as insumo', 'i.unidad as insumo_unidad', 'l.codigo as lote', 'u.name as usuario'])
            ->map(fn ($m) => array_merge((array) $m, [
                'valor' => $m->valor !== null ? (float) $m->valor : null,
                'tiene_foto' => (bool) $m->foto, 'foto' => null,
                'motivo_texto' => Mermas::MOTIVOS[$m->tipo][$m->motivo] ?? $m->motivo,
                'que' => match ($m->tipo) { 'LECHE' => 'Leche', 'INSUMO' => $m->insumo, default => $m->producto },
                'unidad_corta' => match ($m->tipo) { 'LECHE' => 'L', 'INSUMO' => $m->insumo_unidad, default => explode(' ', (string) $m->unidad)[0] },
            ]));

        return response()->json([
            'motivos' => Mermas::MOTIVOS,
            'leche' => ['sin_usar' => Mermas::lecheSinUsar(), 'tanque' => Formulas::litrosEnTanque(), 'precio' => Configuracion::precioLeche()],
            'productos' => $productos,
            'insumos' => $insumos,
            'mermas' => $ultimas,
            'mes' => self::resumenMes(),
            'repetidas' => self::repetidas(),
        ]);
    }

    /** Lo perdido este mes en soles, por tipo, y el mes anterior para comparar. */
    private static function resumenMes(): array
    {
        $ini = now('America/Lima')->startOfMonth();
        $delMes = DB::table('mermas')->where('fecha', '>=', $ini)->groupBy('tipo')
            ->selectRaw('tipo, COUNT(*) as veces, SUM(COALESCE(valor, 0)) as valor')->get()->keyBy('tipo');
        $tipo = fn ($t) => ['veces' => (int) ($delMes[$t]->veces ?? 0), 'valor' => round((float) ($delMes[$t]->valor ?? 0), 2)];

        return [
            'valor' => round((float) $delMes->sum('valor'), 2),
            'veces' => (int) $delMes->sum('veces'),
            'por_tipo' => ['LECHE' => $tipo('LECHE'), 'PRODUCTO' => $tipo('PRODUCTO'), 'INSUMO' => $tipo('INSUMO')],
            'anterior' => round((float) DB::table('mermas')->where('fecha', '>=', $ini->copy()->subMonth())->where('fecha', '<', $ini)->sum('valor'), 2),
        ];
    }

    /** Lo que se perdió VECES_REPETIDA veces o más en 30 días (el mismo insumo o producto): algo pasa con cómo se guarda. */
    private static function repetidas(): array
    {
        return DB::table('mermas as m')->leftJoin('productos as p', 'p.id', '=', 'm.producto_id')->leftJoin('insumos as i', 'i.id', '=', 'm.insumo_id')
            ->where('m.tipo', '!=', 'LECHE')->where('m.fecha', '>=', now('America/Lima')->subDays(30))
            ->groupBy('m.tipo', 'm.producto_id', 'm.insumo_id', 'p.nombre', 'i.nombre')
            ->havingRaw('COUNT(*) >= ?', [self::VECES_REPETIDA])
            ->selectRaw('m.tipo, COALESCE(i.nombre, p.nombre) as que, COUNT(*) as veces, SUM(m.cantidad) as cantidad, SUM(COALESCE(m.valor, 0)) as valor')
            ->orderByDesc('veces')->get()
            ->map(fn ($x) => ['tipo' => $x->tipo, 'que' => $x->que, 'veces' => (int) $x->veces,
                'cantidad' => (float) $x->cantidad, 'valor' => round((float) $x->valor, 2)])->all();
    }

    /**
     * POST /api/v1/compras/mermas (y planta/mermas)
     * { tipo: LECHE|PRODUCTO|INSUMO, producto_id?, lote_id?, insumo_id?, cantidad, motivo, detalle?, foto? }
     */
    public function guardar(Request $r)
    {
        $tipo = $r->input('tipo');
        $data = $r->validate([
            'tipo' => ['required', 'in:LECHE,PRODUCTO,INSUMO'],
            'producto_id' => ['required_if:tipo,PRODUCTO', 'nullable', 'exists:productos,id'],
            'insumo_id' => ['required_if:tipo,INSUMO', 'nullable', 'exists:insumos,id'],
            'lote_id' => ['nullable', 'exists:lotes,id'],
            'cantidad' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'motivo' => ['required', Rule::in(array_keys(Mermas::MOTIVOS[$tipo] ?? []))],
            'detalle' => ['required_if:motivo,OTRO', 'nullable', 'string', 'max:200'],
            'foto' => ['nullable', 'string'],
        ], [
            'producto_id.required_if' => 'Elige si es queso o yogurt.',
            'insumo_id.required_if' => 'Elige qué insumo se perdió.',
            'motivo.in' => 'Elige el motivo de la pérdida.',
            'detalle.required_if' => 'Escribe qué pasó.',
        ]);

        // la foto (el saco roto, el queso con hongos) es opcional; se guarda antes para que una foto mala no deje la pérdida a medias
        $ruta = empty($data['foto']) ? null : Compras::guardarFoto((object) ['codigo' => 'PERDIDA-'.Str::random(6)], $data['foto']);
        try {
            $id = Mermas::registrar($data, $r->user()->id);
        } catch (\Throwable $e) {
            if ($ruta) Storage::disk('local')->delete($ruta);
            throw $e;
        }
        if ($ruta) DB::table('mermas')->where('id', $id)->update(['foto' => $ruta]);

        return response()->json([
            'id' => $id,
            'valor' => DB::table('mermas')->where('id', $id)->value('valor'),
            'stock' => match ($data['tipo']) {
                'LECHE' => Mermas::lecheSinUsar(),
                'INSUMO' => (float) DB::table('insumos')->where('id', $data['insumo_id'])->value('stock'),
                default => Mermas::stockProducto((int) $data['producto_id']),
            },
        ], 201);
    }
}
