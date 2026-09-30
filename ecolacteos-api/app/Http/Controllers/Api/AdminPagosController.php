<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PagoSemanal;
use App\Models\Proveedor;
use App\Services\PagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pagos a proveedores, para el administrador (rutas bajo admin/).
 * La boleta es semanal (lunes a domingo) y se paga el viernes siguiente.
 * Al abrir una semana ya cerrada se generan/actualizan sus boletas con
 * PagoService (solo las PENDIENTES: una boleta pagada no se recalcula).
 */
class AdminPagosController extends Controller
{
    private const SEMANAS = 6;

    public function __construct(private PagoService $pagos) {}

    /** GET /api/v1/admin/pagos?semana=2026-09-21 (cualquier día de la semana; por defecto la última cerrada) */
    public function semana(Request $r)
    {
        $cerradas = $this->semanasCerradas();
        $inicio = $r->query('semana')
            ? Carbon::parse($r->query('semana'))->startOfWeek(Carbon::MONDAY)
            : $cerradas[0];
        abort_if($inicio->copy()->endOfWeek(Carbon::SUNDAY)->gte(Carbon::today('America/Lima')), 422,
            'Esa semana todavía no termina: se paga cuando cierra el domingo.');

        $this->generar($inicio);

        $boletas = DB::table('pagos_semanales as b')
            ->join('proveedores as p', 'p.id', '=', 'b.proveedor_id')
            ->join('comunidades as c', 'c.id', '=', 'p.comunidad_id')
            ->join('vehiculos as v', 'v.id', '=', 'p.vehiculo_id')
            ->leftJoin('users as u', 'u.id', '=', 'b.pagado_por')
            ->whereDate('b.inicio', $inicio->toDateString())
            ->where('b.monto', '>', 0)
            ->orderByRaw("b.estado = 'PAGADO'")->orderBy('v.codigo')->orderBy('p.nombre')
            ->get(['b.id', 'p.nombre as proveedor', 'p.dni', 'c.nombre as comunidad', 'v.codigo as vehiculo',
                'b.litros_aceptados as litros', 'b.monto', 'b.estado', 'b.metodo', 'b.pagado_el', 'u.name as pagado_por']);

        $total = (float) $boletas->sum('monto');
        $pagado = (float) $boletas->where('estado', 'PAGADO')->sum('monto');

        return response()->json([
            'inicio'     => $inicio->toDateString(),
            'fin'        => $inicio->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            'total'      => round($total, 2),
            'pagado'     => round($pagado, 2),
            'pendiente'  => round($total - $pagado, 2),
            'pendientes' => $boletas->where('estado', 'PENDIENTE')->count(),
            'boletas'    => $boletas,
            'semanas'    => collect($cerradas)->map(fn (Carbon $s) => [
                'inicio'     => $s->toDateString(),
                'fin'        => $s->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
                'pendientes' => DB::table('pagos_semanales')->whereDate('inicio', $s->toDateString())
                    ->where('estado', 'PENDIENTE')->where('monto', '>', 0)->count(),
            ]),
        ]);
    }

    /** GET /api/v1/admin/pagos/{id} — detalle día por día (para reclamos). */
    public function detalle(int $id)
    {
        $b = PagoSemanal::with('proveedor:id,nombre,dni')->findOrFail($id);

        return response()->json([
            'id' => $b->id, 'proveedor' => $b->proveedor->nombre, 'dni' => $b->proveedor->dni,
            'inicio' => $b->inicio->toDateString(), 'fin' => $b->fin->toDateString(),
            'litros' => (float) $b->litros_aceptados, 'monto' => (float) $b->monto,
            'estado' => $b->estado, 'metodo' => $b->metodo, 'pagado_el' => $b->pagado_el?->toDateString(),
            'dias' => $b->detalle_dias,
        ]);
    }

    /** POST /api/v1/admin/pagos/pagar { ids: [..], metodo: EFECTIVO|YAPE } */
    public function pagar(Request $r)
    {
        $d = $r->validate([
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['integer'],
            'metodo' => ['required', 'in:EFECTIVO,YAPE'],
        ], ['metodo.in' => 'Elige efectivo o Yape.', 'metodo.required' => 'Elige efectivo o Yape.']);

        $pendientes = DB::table('pagos_semanales')->whereIn('id', $d['ids'])->where('estado', 'PENDIENTE');
        $total = (float) (clone $pendientes)->sum('monto');
        $n = $pendientes->update([
            'estado' => 'PAGADO', 'metodo' => $d['metodo'],
            'pagado_el' => Carbon::today('America/Lima')->toDateString(),
            'pagado_por' => $r->user()->id, 'updated_at' => now(),
        ]);

        return response()->json(['pagadas' => $n, 'total' => round($total, 2)]);
    }

    /** Lunes de las últimas semanas ya cerradas (la más reciente primero). */
    private function semanasCerradas(): array
    {
        $lunes = Carbon::today('America/Lima')->startOfWeek(Carbon::MONDAY)->subWeek();

        return array_map(fn ($i) => $lunes->copy()->subWeeks($i), range(0, self::SEMANAS - 1));
    }

    /** Boletas de la semana para quien entregó; las ya pagadas no se tocan. */
    private function generar(Carbon $inicio): void
    {
        $fin = $inicio->copy()->endOfWeek(Carbon::SUNDAY);
        $conEntregas = DB::table('entregas')->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->distinct()->pluck('proveedor_id');
        $pagadas = DB::table('pagos_semanales')->whereDate('inicio', $inicio->toDateString())
            ->where('estado', 'PAGADO')->pluck('proveedor_id');

        foreach (Proveedor::whereIn('id', $conEntregas->diff($pagadas))->get() as $p) {
            $this->pagos->generar($p, $inicio);
        }
    }
}
