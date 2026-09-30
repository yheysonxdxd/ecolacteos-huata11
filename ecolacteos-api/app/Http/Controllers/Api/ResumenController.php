<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Resúmenes de solo lectura para las pantallas de la app. */
class ResumenController extends Controller
{
    private const DIAS = ['', 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'];

    /**
     * GET /api/v1/acopio/semana?vehiculo_id=
     * Últimos 7 días (hoy incluido): litros por día y acumulado por proveedor.
     */
    public function semanaAcopio(Request $r)
    {
        $r->validate(['vehiculo_id' => ['nullable', 'integer']]);
        $hoy   = Carbon::today('America/Lima');
        $desde = $hoy->copy()->subDays(6);

        $base = DB::table('entregas')
            ->whereBetween('entregas.fecha', [$desde->toDateString(), $hoy->toDateString()])
            ->when($r->vehiculo_id, fn ($q, $v) => $q->where('entregas.vehiculo_id', $v));

        $porDia = (clone $base)->selectRaw('fecha, SUM(litros) litros')
            ->groupBy('fecha')->pluck('litros', 'fecha');

        $dias = [];
        for ($d = $desde->copy(); $d->lte($hoy); $d->addDay()) {
            $dias[] = [
                'fecha'  => $d->toDateString(),
                'dia'    => self::DIAS[$d->dayOfWeekIso],
                'litros' => (float) ($porDia[$d->toDateString()] ?? 0),
            ];
        }

        $porProveedor = (clone $base)
            ->join('proveedores', 'proveedores.id', '=', 'entregas.proveedor_id')
            ->join('comunidades', 'comunidades.id', '=', 'proveedores.comunidad_id')
            ->selectRaw("proveedores.nombre, comunidades.nombre comunidad,
                SUM(entregas.litros) litros,
                SUM(entregas.ausente = 0) entregas,
                SUM(entregas.estado_calidad = 'RECHAZADO') rechazos")
            ->groupBy('proveedores.id', 'proveedores.nombre', 'comunidades.nombre')
            ->orderByDesc('litros')->get()
            ->map(fn ($p) => [
                'nombre' => $p->nombre, 'comunidad' => $p->comunidad, 'litros' => (float) $p->litros,
                'entregas' => (int) $p->entregas, 'rechazos' => (int) $p->rechazos,
            ]);

        return response()->json([
            'desde' => $desde->toDateString(), 'hasta' => $hoy->toDateString(),
            'total' => array_sum(array_column($dias, 'litros')),
            'dias' => $dias, 'proveedores' => $porProveedor,
        ]);
    }

    /**
     * GET /api/v1/productor/resumen
     * Todo lo del productor que inició sesión (proveedores.user_id):
     * su asignación, la entrega de hoy, los últimos 7 días y sus pagos.
     */
    public function productor(Request $r)
    {
        $p = DB::table('proveedores')
            ->join('comunidades', 'comunidades.id', '=', 'proveedores.comunidad_id')
            ->join('vehiculos', 'vehiculos.id', '=', 'proveedores.vehiculo_id')
            ->leftJoin('users as acop', 'acop.id', '=', 'vehiculos.acopiador_id')
            ->where('proveedores.user_id', $r->user()->id)
            ->select('proveedores.*', 'comunidades.nombre as comunidad',
                'vehiculos.codigo as vehiculo', 'acop.name as acopiador')
            ->first();

        if (! $p) {
            return response()->json(['message' => 'Tu usuario no está enlazado a ningún proveedor.'], 404);
        }

        $hoy   = Carbon::today('America/Lima');
        $lunes = $hoy->copy()->startOfWeek();
        $precio = (float) (DB::table('parametros')->where('clave', 'precio.leche_litro')->value('valor') ?? 1.80);

        $ultimos = DB::table('entregas')->where('proveedor_id', $p->id)
            ->whereBetween('fecha', [$hoy->copy()->subDays(6)->toDateString(), $hoy->toDateString()])
            ->orderByDesc('fecha')->get();

        $entrega = fn ($e) => [
            'id' => $e->id, 'fecha' => $e->fecha,
            'dia' => self::DIAS[Carbon::parse($e->fecha)->dayOfWeekIso],
            'litros' => (float) $e->litros, 'ausente' => (bool) $e->ausente,
            'estado' => $e->estado_calidad, 'confirmada' => (bool) $e->confirmada_proveedor,
            'hora' => Carbon::parse($e->registrado_en)->format('H:i'),
        ];

        $hoyEntrega = $ultimos->firstWhere('fecha', $hoy->toDateString());

        // la semana en curso todavía no está en pagos_semanales
        $enCurso = DB::table('entregas')->where('proveedor_id', $p->id)
            ->where('estado_calidad', 'ACEPTADO')
            ->whereBetween('fecha', [$lunes->toDateString(), $hoy->toDateString()]);
        $litrosCurso = (float) (clone $enCurso)->sum('litros');

        $pagos = collect([[
            'semana' => 'Semana en curso',
            'inicio' => $lunes->toDateString(), 'fin' => $lunes->copy()->addDays(6)->toDateString(),
            'litros' => $litrosCurso, 'monto' => round((float) (clone $enCurso)->sum('monto'), 2),
            'precio' => $precio, 'estado' => 'EN_CURSO', 'pagado_el' => null,
        ]])->concat(
            DB::table('pagos_semanales')->where('proveedor_id', $p->id)->orderByDesc('inicio')->limit(8)->get()
                ->map(fn ($s) => [
                    'semana' => $s->semana_iso, 'inicio' => $s->inicio, 'fin' => $s->fin,
                    'litros' => (float) $s->litros_aceptados, 'monto' => (float) $s->monto,
                    'precio' => (float) $s->precio_litro, 'estado' => $s->estado, 'pagado_el' => $s->pagado_el,
                    'metodo' => $s->metodo ?? null,
                ])
        );

        return response()->json([
            'proveedor' => [
                'id' => $p->id, 'nombre' => $p->nombre, 'comunidad' => $p->comunidad,
                'vehiculo' => $p->vehiculo, 'acopiador' => $p->acopiador,
                'promedio_litros' => $p->promedio_litros ? (float) $p->promedio_litros : null,
            ],
            'hoy' => $hoyEntrega ? $entrega($hoyEntrega) : null,
            'ultimos' => $ultimos->map($entrega)->values(),
            'pagos' => $pagos->values(),
        ]);
    }
}
