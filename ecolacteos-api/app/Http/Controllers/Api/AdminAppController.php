<?php

namespace App\Http\Controllers\Api;

use App\Services\Configuracion;
use App\Http\Controllers\Controller;
use App\Services\AcopioService;
use App\Services\CambiosComunidad;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    /** GET /api/v1/admin/totales?periodo=dia|semana|mes  ·  ?fecha=2026-10-14 (un día elegido en el calendario) */
    public function totales(Request $r)
    {
        if ($r->filled('fecha')) {
            $r->validate(['fecha' => ['date_format:Y-m-d']]);
            $desde = Carbon::parse($r->query('fecha'));
            $hasta = $desde->copy();
        } else {
            [$desde, $hasta] = $this->rango($r->query('periodo', 'semana'));
        }
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
        // calidad de cada vehículo en el mismo periodo (antes era la pestaña aparte "Calidad por vehículo")
        $calidad = DB::table('entregas as e')->join('analisis_calidad as a', 'a.entrega_id', '=', 'e.id')
            ->whereBetween('e.fecha', [$desde->toDateString(), $hasta->toDateString()])->where('e.ausente', false)
            ->groupBy('e.vehiculo_id')
            ->selectRaw("e.vehiculo_id, AVG(a.grasa) grasa, AVG(a.densidad) densidad, AVG(a.temperatura) temperatura,
                SUM(a.veredicto = 'ADULTERADA') adulteradas, SUM(a.veredicto IN ('RECHAZADO','ADULTERADA')) rechazos, COUNT(a.id) analisis")
            ->get()->keyBy('vehiculo_id');
        $porVehiculo = DB::table('vehiculos as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.acopiador_id')
            ->select('v.id', 'v.codigo', 'v.tipo', 'v.orden_ruta', 'u.name as acopiador')
            ->orderBy('v.codigo')->get()
            ->map(function ($v) use ($desde, $hasta, $comunidades, $calidad) {
                $ruta = collect(json_decode($v->orden_ruta ?? '[]', true))->map(fn ($id) => $comunidades[$id] ?? '?');
                $c = $calidad->get($v->id);
                return [
                    'codigo' => $v->codigo, 'tipo' => $v->tipo, 'acopiador' => $v->acopiador,
                    'ruta' => $ruta->implode(', '),
                    'litros' => (float) DB::table('entregas')->where('vehiculo_id', $v->id)
                        ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->sum('litros'),
                    'analisis' => (int) ($c->analisis ?? 0),
                    'grasa' => $c ? round((float) $c->grasa, 2) : null,
                    'densidad' => $c ? round((float) $c->densidad, 4) : null,
                    'temperatura' => $c ? round((float) $c->temperatura, 1) : null,
                    'adulteradas' => (int) ($c->adulteradas ?? 0),
                    'rechazo_pct' => $c && $c->analisis ? round($c->rechazos / $c->analisis * 100, 1) : 0,
                ];
            });

        return response()->json([
            'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(),
            'litros' => $litros, 'a_pagar' => round($aPagar, 2), 'entregas' => $total,
            'aceptados_pct' => $pct($aceptadas), 'rechazados_pct' => $pct($rechazadas),
            'adulterada_pct' => $pct($adulteradas), 'pendientes' => $pendientes,
            'vehiculos' => $porVehiculo,
            // para el gráfico de la web: litros de cada día del periodo
            'por_dia' => (clone $ents)->groupBy('fecha')->orderBy('fecha')
                ->selectRaw("fecha, SUM(litros) as litros, SUM(CASE WHEN estado_calidad = 'RECHAZADO' THEN 1 ELSE 0 END) as rechazadas, COUNT(*) as entregas")
                ->get()->map(fn ($x) => ['fecha' => $x->fecha, 'litros' => (float) $x->litros, 'rechazadas' => (int) $x->rechazadas, 'entregas' => (int) $x->entregas]),
        ]);
    }

    /**
     * GET /api/v1/admin/acopio/calendario?mes=2026-10 — litros de cada día del mes para el calendario de Acopio.
     * Sin ?mes, el mes del último día con entregas.
     */
    public function calendario(Request $r)
    {
        $hoy = $this->hoy();
        if ($r->filled('mes')) {
            $r->validate(['mes' => ['date_format:Y-m']]);
            $inicio = Carbon::parse($r->query('mes') . '-01');
        } else {
            $ultimo = DB::table('entregas')->where('fecha', '<=', $hoy->toDateString())->max('fecha');
            $inicio = ($ultimo ? Carbon::parse($ultimo) : $hoy->copy())->startOfMonth();
        }
        $fin = $inicio->copy()->endOfMonth();

        $dias = DB::table('entregas')->where('ausente', false)
            ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->groupBy('fecha')->orderBy('fecha')
            ->selectRaw("fecha, SUM(litros) as litros, COUNT(*) as entregas,
                SUM(CASE WHEN estado_calidad = 'RECHAZADO' THEN 1 ELSE 0 END) as rechazadas")
            ->get()->map(fn ($x) => [
                'fecha' => substr((string) $x->fecha, 0, 10), 'litros' => round((float) $x->litros, 1),
                'entregas' => (int) $x->entregas, 'rechazadas' => (int) $x->rechazadas,
            ]);

        return response()->json([
            'mes' => $inicio->format('Y-m'),
            'anterior' => $inicio->copy()->subMonth()->format('Y-m'),
            // no se puede pasar a un mes que todavía no empieza
            'siguiente' => $inicio->copy()->addMonth()->lte($hoy) ? $inicio->copy()->addMonth()->format('Y-m') : null,
            'hoy' => $hoy->toDateString(),
            'litros' => round((float) $dias->sum('litros'), 1),
            'dias' => $dias,
        ]);
    }

    /** GET /api/v1/admin/padron — proveedores y trabajadores. */
    public function padron()
    {
        CambiosComunidad::aplicarProgramados();
        $recientes = CambiosComunidad::recientes();
        $programados = CambiosComunidad::programados();

        // cuánto entrega de verdad: litros de los últimos 30 días y de los últimos 7, y su última entrega
        $hace30 = $this->hoy()->subDays(29)->toDateString();
        $hace7 = $this->hoy()->subDays(6)->toDateString();
        $leche = DB::table('entregas')->where('ausente', false)->where('litros', '>', 0)
            ->selectRaw('proveedor_id, SUM(CASE WHEN fecha >= ? THEN litros ELSE 0 END) as litros_30,
                SUM(CASE WHEN fecha >= ? THEN 1 ELSE 0 END) as dias_30,
                SUM(CASE WHEN fecha >= ? THEN litros ELSE 0 END) as litros_7,
                MAX(fecha) as ultima, COUNT(*) as entregas', [$hace30, $hace30, $hace7])
            ->groupBy('proveedor_id')->get()->keyBy('proveedor_id');

        $proveedores = DB::table('proveedores as p')
            ->join('comunidades as c', 'c.id', '=', 'p.comunidad_id')
            ->join('vehiculos as v', 'v.id', '=', 'p.vehiculo_id')
            ->orderBy('v.codigo')->orderBy('c.nombre')->orderBy('p.nombre')
            ->select('p.id', 'p.nombre', 'p.dni', 'p.estado', 'p.promedio_litros', 'p.vacas_ordeno', 'p.motivo_baja',
                'c.nombre as comunidad', 'v.codigo as vehiculo')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'nombre' => $p->nombre, 'dni' => $p->dni, 'estado' => $p->estado,
                'promedio' => $p->promedio_litros ? (float) $p->promedio_litros : null,
                'comunidad' => $p->comunidad, 'vehiculo' => $p->vehiculo, 'motivo_baja' => $p->motivo_baja,
                // "Cambió el 2 oct · antes Carata" (15 días) y "Pasa a Kapi el 5 oct"
                'cambio_reciente' => ($c = $recientes->get($p->id)) ? ['desde' => $c->desde, 'antes' => $c->antes, 'canal' => $c->canal] : null,
                'programado' => ($g = $programados->get($p->id)) ? ['desde' => $g->desde, 'comunidad' => $g->comunidad, 'vehiculo' => $g->vehiculo] : null,
                'leche' => ($l = $leche->get($p->id)) ? [
                    'por_dia' => $l->dias_30 > 0 ? round($l->litros_30 / $l->dias_30, 1) : null, // en los días que entregó
                    'litros_30' => round((float) $l->litros_30, 1), 'dias_30' => (int) $l->dias_30,
                    'litros_7' => round((float) $l->litros_7, 1), 'ultima' => $l->ultima, 'entregas' => (int) $l->entregas,
                ] : null,
                'vacas' => $p->vacas_ordeno,
            ]);

        $trabajadores = DB::table('users')->orderBy('rol')->orderBy('name')
            ->get(['id', 'name', 'dni', 'rol', 'activo'])
            ->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->name, 'dni' => $u->dni, 'rol' => $u->rol, 'activo' => (bool) $u->activo]);

        return response()->json(['proveedores' => $proveedores, 'trabajadores' => $trabajadores]);
    }

    /**
     * GET /api/v1/admin/calidad — promedios de los últimos 30 días por vehículo.
     * La web y la app nueva ya lo ven en Totales; queda para los APK anteriores (1.0 y 1.1).
     */
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

    /**
     * GET /api/v1/admin/adulteraciones — proveedores sancionados por adulteración:
     * los dados de baja (2.ª vez) primero y luego los que tienen su 1.ª advertencia,
     * cada uno con el detalle de cada vez que se le detectó.
     */
    public function adulteraciones()
    {
        $sanciones = DB::table('sanciones as s')
            ->join('proveedores as p', 'p.id', '=', 's.proveedor_id')
            ->join('comunidades as c', 'c.id', '=', 'p.comunidad_id')
            ->join('vehiculos as v', 'v.id', '=', 'p.vehiculo_id')
            ->leftJoin('analisis_calidad as a', 'a.id', '=', 's.analisis_id')
            ->leftJoin('entregas as e', 'e.id', '=', 'a.entrega_id')
            ->leftJoin('users as u', 'u.id', '=', 's.resuelta_por')
            ->where('s.tipo', 'ADULTERACION')
            ->orderBy('s.created_at')->orderBy('s.id')
            ->get(['s.proveedor_id', 's.nivel', 's.medida', 's.precio_aplicado', 's.detalle', 's.created_at',
                'p.nombre', 'p.dni', 'p.estado', 'p.motivo_baja', 'c.nombre as comunidad', 'v.codigo as vehiculo',
                'a.agua_anadida', 'a.muestra', 'e.fecha', 'e.litros', 'u.name as analista']);

        $proveedores = $sanciones->groupBy('proveedor_id')->map(function ($filas) {
            $p = $filas->first();
            $veces = $filas->map(fn ($s) => [
                'nivel' => (int) $s->nivel,
                'fecha' => $s->fecha ?? substr((string) $s->created_at, 0, 10),
                'litros' => $s->litros !== null ? (float) $s->litros : null,
                'agua_anadida' => $s->agua_anadida !== null ? (float) $s->agua_anadida : null,
                'muestra' => $s->muestra,
                'medida' => $s->medida,
                'precio_aplicado' => $s->precio_aplicado !== null ? (float) $s->precio_aplicado : null,
                'detalle' => $s->detalle,
                'analista' => $s->analista,
            ])->values();

            return [
                'id' => (int) $p->proveedor_id, 'nombre' => $p->nombre, 'dni' => $p->dni,
                'comunidad' => $p->comunidad, 'vehiculo' => $p->vehiculo,
                'estado' => $p->estado, 'motivo_baja' => $p->motivo_baja,
                'de_baja' => $p->estado === 'BAJA',
                'ultima' => $veces->last()['fecha'],
                'veces' => $veces,
            ];
        })
            ->sortBy([['de_baja', 'desc'], ['ultima', 'desc']])
            ->values();

        return response()->json([
            'de_baja' => $proveedores->where('de_baja', true)->count(),
            'advertidos' => $proveedores->where('de_baja', false)->count(),
            'proveedores' => $proveedores,
        ]);
    }

    /** GET /api/v1/admin/solicitudes — cambios de zona, pendientes primero. */
    public function solicitudes()
    {
        CambiosComunidad::aplicarProgramados();
        $filas = DB::table('solicitudes_cambio_zona as s')
            ->join('proveedores as p', 'p.id', '=', 's.proveedor_id')
            ->join('comunidades as o', 'o.id', '=', 's.comunidad_origen_id')
            ->join('comunidades as d', 'd.id', '=', 's.comunidad_destino_id')
            ->orderByRaw("s.estado = 'PENDIENTE' DESC")->orderByDesc('s.created_at')
            ->leftJoin('users as u', 'u.id', '=', 's.registrada_por')
            ->select('s.id', 's.estado', 's.motivo', 's.canal', 's.solicitada_para', 's.vigente_desde', 's.created_at',
                'p.nombre as proveedor', 'p.dni', 'o.nombre as origen', 'd.nombre as destino', 'u.name as registrada_por')
            ->get();

        // para registrar un cambio: a qué comunidades se puede ir (con el vehículo que pasa por ahí) y quiénes son los proveedores
        $vehiculos = DB::table('vehiculos as v')->leftJoin('users as a', 'a.id', '=', 'v.acopiador_id')
            ->get(['v.id', 'v.codigo', 'v.orden_ruta', 'a.name as acopiador']);
        $comunidades = DB::table('comunidades')->orderBy('nombre')->get(['id', 'nombre'])->map(function ($c) use ($vehiculos) {
            $v = $vehiculos->first(fn ($v) => in_array($c->id, json_decode($v->orden_ruta ?? '[]', true)));
            return ['id' => $c->id, 'nombre' => $c->nombre, 'vehiculo' => $v?->codigo, 'acopiador' => $v?->acopiador];
        });
        $proveedores = DB::table('proveedores as p')->join('comunidades as c', 'c.id', '=', 'p.comunidad_id')
            ->leftJoin('vehiculos as v', 'v.id', '=', 'p.vehiculo_id')->where('p.estado', '!=', 'BAJA')->orderBy('p.nombre')
            ->get(['p.id', 'p.nombre', 'p.dni', 'p.comunidad_id', 'c.nombre as comunidad', 'v.codigo as vehiculo']);

        return response()->json(['solicitudes' => $filas, 'comunidades' => $comunidades, 'proveedores' => $proveedores]);
    }

    /**
     * POST /api/v1/admin/proveedores/{id}/cambiar-comunidad
     * { comunidad_id, canal: LLAMADA|OFICINA, desde?: AAAA-MM-DD, motivo? }
     * El productor avisó por teléfono o vino a Huata (casi no tienen internet):
     * el administrador registra el cambio y queda aprobado al momento.
     */
    public function cambiarComunidad(Request $r, int $id)
    {
        $data = $r->validate([
            'comunidad_id' => ['required', 'integer', 'exists:comunidades,id'],
            'canal' => ['required', 'in:LLAMADA,OFICINA'],
            'desde' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$this->hoy()->toDateString()],
            'motivo' => ['nullable', 'string', 'max:300'],
        ], [
            'canal.required' => 'Indica si el productor llamó o vino a Huata.',
            'desde.after_or_equal' => 'El cambio no puede ser para una fecha pasada.',
        ]);
        $p = DB::table('proveedores')->find($id);
        abort_if(! $p, 404, 'Proveedor no encontrado');
        abort_if($p->estado === 'BAJA', 422, 'Ese proveedor está dado de baja.');
        abort_if((int) $data['comunidad_id'] === (int) $p->comunidad_id, 422, 'El proveedor ya está en esa comunidad.');
        $programado = CambiosComunidad::programados()->get($p->id);
        abort_if($programado !== null, 422,
            "Ya tiene un cambio programado a {$programado?->comunidad} desde el {$programado?->desde}. Espera a que se aplique.");

        return DB::transaction(function () use ($r, $p, $data) {
            $vigente = $data['desde'] ?? $this->hoy()->toDateString();
            $vehiculo = $this->moverProveedor($p->id, (int) $data['comunidad_id'], $vigente, $r->user()->id);

            // si había pedido algo por la app, ese pedido queda resuelto con este cambio
            DB::table('solicitudes_cambio_zona')->where('proveedor_id', $p->id)->where('estado', 'PENDIENTE')->update([
                'estado' => DB::raw('CASE WHEN comunidad_destino_id = '.(int) $data['comunidad_id']." THEN 'APROBADA' ELSE 'RECHAZADA' END"),
                'resuelta_por' => $r->user()->id, 'updated_at' => now(),
            ]);
            DB::table('solicitudes_cambio_zona')->insert([
                'uuid' => (string) Str::uuid(), 'proveedor_id' => $p->id,
                'comunidad_origen_id' => $p->comunidad_id, 'comunidad_destino_id' => $data['comunidad_id'],
                'motivo' => $data['motivo'] ?? null, 'canal' => $data['canal'], 'registrada_por' => $r->user()->id,
                'estado' => 'APROBADA', 'solicitada_para' => $vigente, 'vigente_desde' => $vigente,
                'resuelta_por' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return response()->json([
                'proveedor' => $p->nombre, 'comunidad' => DB::table('comunidades')->where('id', $data['comunidad_id'])->value('nombre'),
                'vehiculo' => $vehiculo->codigo, 'vigente_desde' => $vigente,
            ], 201);
        });
    }

    /** Pasa al proveedor a la comunidad y al vehículo cuya ruta la incluye; deja el registro en proveedor_ubicaciones. */
    private function moverProveedor(int $proveedorId, int $comunidadId, string $vigente, int $usuarioId): object
    {
        // con fecha futura queda programado (CambiosComunidad::aplicarProgramados)
        return CambiosComunidad::mover($proveedorId, $comunidadId, $vigente, $usuarioId);
    }

    /** GET /api/v1/admin/proveedores/{id}/traslado — el traslado que se puede corregir (programado o reciente). */
    public function verTraslado(int $id)
    {
        CambiosComunidad::aplicarProgramados();
        $s = CambiosComunidad::trasladoEditable($id);
        abort_if(! $s, 404, 'Este proveedor no tiene un traslado para corregir.');

        return response()->json(['traslado' => [
            'origen_id' => $s->comunidad_origen_id, 'origen' => $s->origen, 'destino_id' => $s->comunidad_destino_id,
            'destino' => $s->destino, 'desde' => $s->vigente_desde, 'canal' => $s->canal, 'motivo' => $s->motivo,
            'programado' => $s->programado,
        ]]);
    }

    /** POST /api/v1/admin/proveedores/{id}/traslado { comunidad_id, desde?, canal?, motivo? } — corregir el traslado. */
    public function editarTraslado(Request $r, int $id)
    {
        $d = $r->validate([
            'comunidad_id' => ['required', 'integer', 'exists:comunidades,id'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'canal' => ['nullable', 'in:APP,LLAMADA,OFICINA'],
            'motivo' => ['nullable', 'string', 'max:300'],
        ]);
        abort_if(! DB::table('proveedores')->where('id', $id)->exists(), 404, 'Proveedor no encontrado');
        $s = DB::transaction(fn () => CambiosComunidad::editarTraslado($id, $d));

        return response()->json([
            'comunidad' => DB::table('comunidades')->where('id', $s->comunidad_destino_id)->value('nombre'),
            'desde' => $s->vigente_desde,
        ]);
    }

    /** POST /api/v1/admin/proveedores/{id}/traslado/anular — el traslado no iba. */
    public function anularTraslado(int $id)
    {
        abort_if(! DB::table('proveedores')->where('id', $id)->exists(), 404, 'Proveedor no encontrado');
        $origen = DB::transaction(fn () => CambiosComunidad::anularTraslado($id));

        return response()->json(['se_queda_en' => $origen]);
    }

    /** GET /api/v1/admin/proveedores/{id}/historial-comunidad */
    public function historialComunidad(int $id)
    {
        CambiosComunidad::aplicarProgramados();

        return response()->json(CambiosComunidad::historial($id));
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

            $vehiculo = $this->moverProveedor($s->proveedor_id, $s->comunidad_destino_id, $vigente, $r->user()->id);
            DB::table('solicitudes_cambio_zona')->where('id', $s->id)->update([
                'estado' => 'APROBADA', 'vigente_desde' => $vigente,
                'resuelta_por' => $r->user()->id, 'updated_at' => now(),
            ]);

            return response()->json(['estado' => 'APROBADA', 'vigente_desde' => $vigente, 'vehiculo' => $vehiculo->codigo]);
        });
    }

    /**
     * GET /api/v1/admin/conciliacion?fecha= — campo vs. planta de un día (sin fecha: el último día con acopio).
     * "vehiculos" son los ya medidos en planta (lo que lee la app); "filas" incluye además los que faltan anotar.
     */
    public function conciliacion(Request $r)
    {
        $fecha = $r->query('fecha')
            ?: DB::table('entregas')->where('fecha', '<=', $this->hoy()->toDateString())->max('fecha')
            ?: $this->hoy()->toDateString();
        $fecha = Carbon::parse($fecha)->toDateString();

        $registradas = DB::table('recepciones_planta as r')->join('vehiculos as v', 'v.id', '=', 'r.vehiculo_id')
            ->where('r.fecha', $fecha)->orderBy('v.codigo')
            ->select('r.vehiculo_id', 'v.codigo', 'r.litros_campo', 'r.litros_planta', 'r.diferencia_pct', 'r.fuera_tolerancia')
            ->get();
        $campo = DB::table('entregas as e')->join('vehiculos as v', 'v.id', '=', 'e.vehiculo_id')
            ->where('e.fecha', $fecha)->where('e.ausente', false)
            ->groupBy('e.vehiculo_id', 'v.codigo')
            ->select('e.vehiculo_id', 'v.codigo', DB::raw('ROUND(SUM(e.litros), 2) as litros_campo'))
            ->get();

        $filas = $campo->whereNotIn('vehiculo_id', $registradas->pluck('vehiculo_id'))
            ->map(fn ($c) => (object) [...(array) $c, 'litros_planta' => null, 'diferencia_pct' => null, 'fuera_tolerancia' => null])
            ->concat($registradas)->sortBy('codigo')->values();
        $tol = (float) (DB::table('parametros')->where('clave', 'conciliacion.tolerancia_pct')->value('valor') ?? 1.5);

        return response()->json(['fecha' => $fecha, 'tolerancia' => $tol, 'vehiculos' => $registradas, 'filas' => $filas]);
    }

    /** POST /api/v1/admin/conciliacion — anota los litros que llegaron a planta de un vehículo en un día. */
    public function registrarConciliacion(Request $r, AcopioService $acopio)
    {
        $d = $r->validate([
            'fecha'         => ['required', 'date', 'before_or_equal:' . $this->hoy()->toDateString()],
            'vehiculo_id'   => ['required', 'integer', 'exists:vehiculos,id'],
            'litros_planta' => ['required', 'numeric', 'min:0', 'max:99999'],
        ]);
        $fecha = Carbon::parse($d['fecha']);
        $hubo = DB::table('entregas')->where('vehiculo_id', $d['vehiculo_id'])->where('fecha', $fecha->toDateString())
            ->where('ausente', false)->exists();
        abort_unless($hubo, 422, 'Ese vehículo no tiene acopio registrado en campo ese día.');

        $rec = $acopio->conciliar($d['vehiculo_id'], $fecha, (float) $d['litros_planta']);

        return response()->json([
            'litros_campo' => $rec->litros_campo, 'litros_planta' => $rec->litros_planta,
            'diferencia_pct' => $rec->diferencia_pct, 'fuera_tolerancia' => $rec->fuera_tolerancia,
        ]);
    }

    /** GET /api/v1/admin/costos — últimos 30 días: costo por litro, rendimiento y ventas. */
    public function costos()
    {
        $desde = $this->hoy()->subDays(29);
        $precioLeche = Configuracion::precioLeche();

        $lotes = DB::table('lotes as l')->join('productos as p', 'p.id', '=', 'l.producto_id')
            ->where('l.iniciado_en', '>=', $desde)->select('l.*', 'p.nombre as producto', 'p.rendimiento_min', 'p.rendimiento_max')->get();
        $litrosProcesados = (float) $lotes->sum('litros_leche');

        // costo unitario de cada insumo según su última compra
        $costoUnit = \App\Services\Compras::costosUnitarios();
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
