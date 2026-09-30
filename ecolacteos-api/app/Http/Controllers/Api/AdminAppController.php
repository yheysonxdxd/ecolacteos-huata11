<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Tablero del administrador para la app: totales, padrón, calidad, solicitudes, conciliación y costos. */
class AdminAppController extends Controller
{
    private function hoy(): Carbon
    {
        return Carbon::today('America/Lima');
    }

    /** Rango del periodo. "dia" toma el último día con entregas (hoy o antes). */
    private function rango(string $periodo): array
    {
        $hoy = $this->hoy();
        return match ($periodo) {
            'mes'    => [$hoy->copy()->subDays(29), $hoy],
            'semana' => [$hoy->copy()->subDays(6), $hoy],
            default  => (function () use ($hoy) {
                $ultimo = DB::table('entregas')->where('fecha', '<=', $hoy->toDateString())->max('fecha');
                $d = $ultimo ? Carbon::parse($ultimo) : $hoy;
                return [$d, $d->copy()];
            })(),
        };
    }

    /** GET /api/v1/admin/totales?periodo=dia|semana|mes */
    public function totales(Request $r)
    {
        [$desde, $hasta] = $this->rango($r->query('periodo', 'semana'));
        $ents = DB::table('entregas')->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->where('ausente', false);

        $litros    = (float) (clone $ents)->sum('litros');
        $aPagar    = (float) (clone $ents)->where('estado_calidad', 'ACEPTADO')->sum('monto');
        $total     = (clone $ents)->count();
        // una adulterada de 1.ª vez queda ACEPTADO a precio reducido: se cuenta aparte
        $idsAdult = DB::table('analisis_calidad')->where('veredicto', 'ADULTERADA')->select('entrega_id');
        $adulteradas = (clone $ents)->whereIn('id', $idsAdult)->count();
        $aceptadas  = (clone $ents)->where('estado_calidad', 'ACEPTADO')->whereNotIn('id', $idsAdult)->count();
        $rechazadas = (clone $ents)->where('estado_calidad', 'RECHAZADO')->whereNotIn('id', $idsAdult)->count();
        $pendientes = (clone $ents)->where('estado_calidad', 'PENDIENTE')->count();
        $pct = fn ($n) => $total ? round($n / $total * 100, 1) : 0;

        $comunidades = DB::table('comunidades')->pluck('nombre', 'id');
        $porVehiculo = DB::table('vehiculos as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.acopiador_id')
            ->select('v.id', 'v.codigo', 'v.tipo', 'v.orden_ruta', 'u.name as acopiador')
            ->orderBy('v.codigo')->get()
            ->map(function ($v) use ($desde, $hasta, $comunidades) {
                $ruta = collect(json_decode($v->orden_ruta ?? '[]', true))->map(fn ($id) => $comunidades[$id] ?? '?');
                return [
                    'codigo' => $v->codigo, 'tipo' => $v->tipo, 'acopiador' => $v->acopiador,
                    'ruta' => $ruta->implode(', '),
                    'litros' => (float) DB::table('entregas')->where('vehiculo_id', $v->id)
                        ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->sum('litros'),
                ];
            });

        return response()->json([
            'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(),
            'litros' => $litros, 'a_pagar' => round($aPagar, 2), 'entregas' => $total,
            'aceptados_pct' => $pct($aceptadas), 'rechazados_pct' => $pct($rechazadas),
            'adulterada_pct' => $pct($adulteradas), 'pendientes' => $pendientes,
            'vehiculos' => $porVehiculo,
        ]);
    }

    /** GET /api/v1/admin/padron — proveedores y trabajadores. */
    public function padron()
    {
        $proveedores = DB::table('proveedores as p')
            ->join('comunidades as c', 'c.id', '=', 'p.comunidad_id')
            ->join('vehiculos as v', 'v.id', '=', 'p.vehiculo_id')
            ->orderBy('v.codigo')->orderBy('c.nombre')->orderBy('p.nombre')
            ->select('p.id', 'p.nombre', 'p.dni', 'p.estado', 'p.promedio_litros', 'p.motivo_baja',
                'c.nombre as comunidad', 'v.codigo as vehiculo')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'nombre' => $p->nombre, 'dni' => $p->dni, 'estado' => $p->estado,
                'promedio' => $p->promedio_litros ? (float) $p->promedio_litros : null,
                'comunidad' => $p->comunidad, 'vehiculo' => $p->vehiculo, 'motivo_baja' => $p->motivo_baja,
            ]);

        $trabajadores = DB::table('users')->orderBy('rol')->orderBy('name')
            ->get(['id', 'name', 'dni', 'rol', 'activo'])
            ->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->name, 'dni' => $u->dni, 'rol' => $u->rol, 'activo' => (bool) $u->activo]);

        return response()->json(['proveedores' => $proveedores, 'trabajadores' => $trabajadores]);
    }

    /** GET /api/v1/admin/calidad — promedios de los últimos 30 días por vehículo. */
    public function calidad()
    {
        $desde = $this->hoy()->subDays(29)->toDateString();
        $filas = DB::table('vehiculos as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.acopiador_id')
            ->leftJoin('entregas as e', function ($j) use ($desde) {
                $j->on('e.vehiculo_id', '=', 'v.id')->where('e.fecha', '>=', $desde)->where('e.ausente', false);
            })
            ->leftJoin('analisis_calidad as a', 'a.entrega_id', '=', 'e.id')
            ->groupBy('v.id', 'v.codigo', 'u.name')->orderBy('v.codigo')
            ->selectRaw("v.codigo, u.name as acopiador,
                AVG(a.grasa) grasa, AVG(a.densidad) densidad, AVG(a.temperatura) temperatura,
                SUM(a.veredicto = 'ADULTERADA') adulteradas,
                SUM(a.veredicto IN ('RECHAZADO','ADULTERADA')) rechazos,
                COUNT(a.id) analisis")
            ->get()
            ->map(fn ($f) => [
                'codigo' => $f->codigo, 'acopiador' => $f->acopiador,
                'grasa' => round((float) $f->grasa, 2), 'densidad' => round((float) $f->densidad, 4),
                'temperatura' => round((float) $f->temperatura, 1),
                'adulteradas' => (int) $f->adulteradas, 'analisis' => (int) $f->analisis,
                'rechazo_pct' => $f->analisis ? round($f->rechazos / $f->analisis * 100, 1) : 0,
            ]);

        return response()->json(['vehiculos' => $filas]);
    }

    /** GET /api/v1/admin/solicitudes — cambios de zona, pendientes primero. */
    public function solicitudes()
    {
        $filas = DB::table('solicitudes_cambio_zona as s')
            ->join('proveedores as p', 'p.id', '=', 's.proveedor_id')
            ->join('comunidades as o', 'o.id', '=', 's.comunidad_origen_id')
            ->join('comunidades as d', 'd.id', '=', 's.comunidad_destino_id')
            ->orderByRaw("s.estado = 'PENDIENTE' DESC")->orderByDesc('s.created_at')
            ->select('s.id', 's.estado', 's.motivo', 's.solicitada_para', 's.vigente_desde',
                'p.nombre as proveedor', 'o.nombre as origen', 'd.nombre as destino')
            ->get();

        return response()->json(['solicitudes' => $filas]);
    }

    /**
     * POST /api/v1/admin/solicitudes/{id}/resolver { aprobar }
     * Al aprobar, el proveedor pasa a la comunidad destino y al vehículo cuya
     * ruta la incluye, y queda el registro en proveedor_ubicaciones.
     */
    public function resolverSolicitud(Request $r, int $id)
    {
        $r->validate(['aprobar' => ['required', 'boolean']]);
        $s = DB::table('solicitudes_cambio_zona')->find($id);
        abort_if(! $s, 404, 'Solicitud no encontrada');
        abort_if($s->estado !== 'PENDIENTE', 422, 'La solicitud ya fue resuelta');

        return DB::transaction(function () use ($r, $s) {
            $vigente = max($this->hoy()->toDateString(), $s->solicitada_para);

            if (! $r->boolean('aprobar')) {
                DB::table('solicitudes_cambio_zona')->where('id', $s->id)
                    ->update(['estado' => 'RECHAZADA', 'resuelta_por' => $r->user()->id, 'updated_at' => now()]);
                return response()->json(['estado' => 'RECHAZADA']);
            }

            $vehiculo = DB::table('vehiculos')->get()
                ->first(fn ($v) => in_array($s->comunidad_destino_id, json_decode($v->orden_ruta ?? '[]', true)));
            abort_if(! $vehiculo, 422, 'Ningún vehículo pasa por la comunidad destino');

            DB::table('proveedor_ubicaciones')->insert([
                'proveedor_id' => $s->proveedor_id, 'comunidad_id' => $s->comunidad_destino_id,
                'vehiculo_id' => $vehiculo->id, 'vigente_desde' => $vigente,
                'registrado_por' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('proveedores')->where('id', $s->proveedor_id)->update([
                'comunidad_id' => $s->comunidad_destino_id, 'vehiculo_id' => $vehiculo->id,
                'ubicacion_actualizada_en' => $vigente, 'updated_at' => now(),
            ]);
            DB::table('solicitudes_cambio_zona')->where('id', $s->id)->update([
                'estado' => 'APROBADA', 'vigente_desde' => $vigente,
                'resuelta_por' => $r->user()->id, 'updated_at' => now(),
            ]);

            return response()->json(['estado' => 'APROBADA', 'vigente_desde' => $vigente, 'vehiculo' => $vehiculo->codigo]);
        });
    }

    /** GET /api/v1/admin/conciliacion — campo vs. planta del último día registrado. */
    public function conciliacion()
    {
        $fecha = DB::table('recepciones_planta')->max('fecha');
        $filas = DB::table('recepciones_planta as r')->join('vehiculos as v', 'v.id', '=', 'r.vehiculo_id')
            ->where('r.fecha', $fecha)->orderBy('v.codigo')
            ->select('v.codigo', 'r.litros_campo', 'r.litros_planta', 'r.diferencia_pct', 'r.fuera_tolerancia')
            ->get();
        $tol = (float) (DB::table('parametros')->where('clave', 'conciliacion.tolerancia_pct')->value('valor') ?? 1.5);

        return response()->json(['fecha' => $fecha, 'tolerancia' => $tol, 'vehiculos' => $filas]);
    }

    /** GET /api/v1/admin/costos — últimos 30 días: costo por litro, rendimiento y ventas. */
    public function costos()
    {
        $desde = $this->hoy()->subDays(29);
        $precioLeche = (float) (DB::table('parametros')->where('clave', 'precio.leche_litro')->value('valor') ?? 1.80);

        $lotes = DB::table('lotes as l')->join('productos as p', 'p.id', '=', 'l.producto_id')
            ->where('l.iniciado_en', '>=', $desde)->select('l.*', 'p.nombre as producto', 'p.rendimiento_min', 'p.rendimiento_max')->get();
        $litrosProcesados = (float) $lotes->sum('litros_leche');

        // costo unitario de cada insumo según su última orden de compra
        $costoUnit = DB::table('ordenes_compra')->orderBy('created_at')->get()
            ->mapWithKeys(fn ($o) => [$o->insumo_id => $o->cantidad > 0 ? $o->costo_estimado / $o->cantidad : 0]);
        $costoInsumos = DB::table('movimientos_insumo')->where('origen_type', 'lote')
            ->where('created_at', '>=', $desde)->where('cantidad', '<', 0)->get()
            ->sum(fn ($m) => abs($m->cantidad) * ($costoUnit[$m->insumo_id] ?? 0));

        $rend = $lotes->where('estado', 'TERMINADO')->groupBy('producto')->map(fn ($g) => [
            'producto' => $g->first()->producto,
            'esperado_100l' => round(($g->first()->rendimiento_min + $g->first()->rendimiento_max) / 2 * 100, 1),
            'real_100l' => round($g->sum('obtenido') / max($g->sum('litros_leche'), 1) * 100, 1),
            'lotes' => $g->count(),
        ])->values();

        $ventas = DB::table('ventas')->where('vendida_en', '>=', $desde);
        $precios = DB::table('precios_venta as pv')->join('productos as p', 'p.id', '=', 'pv.producto_id')
            ->whereNull('pv.vigente_hasta')->orderBy('p.nombre')->orderBy('pv.precio')
            ->get(['p.nombre as producto', 'pv.tipo_cliente', 'pv.precio']);

        return response()->json([
            'desde' => $desde->toDateString(),
            'litros_procesados' => $litrosProcesados,
            'costo_leche_litro' => $precioLeche,
            'costo_insumos_litro' => $litrosProcesados ? round($costoInsumos / $litrosProcesados, 3) : 0,
            'rendimiento' => $rend,
            'ventas_total' => round((float) (clone $ventas)->sum('total'), 2),
            'ventas_cantidad' => (clone $ventas)->count(),
            'precios' => $precios,
        ]);
    }
}
