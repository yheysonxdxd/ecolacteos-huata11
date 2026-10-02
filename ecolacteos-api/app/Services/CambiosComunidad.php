<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cambios de comunidad de los proveedores:
 *  - un cambio con fecha futura queda programado y se aplica al llegar el día
 *  - "cambio reciente": los primeros días en la comunidad nueva (padrón y app del acopiador)
 *  - historial de comunidades de un proveedor
 */
class CambiosComunidad
{
    public const DIAS_RECIENTE = 15;

    private static function hoy(): string
    {
        return Carbon::today('America/Lima')->toDateString();
    }

    /** Vehículo cuya ruta pasa por la comunidad. */
    public static function vehiculoDe(int $comunidadId): ?object
    {
        return DB::table('vehiculos')->get()
            ->first(fn ($v) => in_array($comunidadId, json_decode($v->orden_ruta ?? '[]', true)));
    }

    /**
     * Mueve al proveedor a la comunidad (y al vehículo de esa ruta). Si la fecha
     * es futura solo queda programado: se aplica con aplicarProgramados().
     */
    public static function mover(int $proveedorId, int $comunidadId, string $vigente, int $usuarioId): object
    {
        $vehiculo = self::vehiculoDe($comunidadId);
        abort_if(! $vehiculo, 422, 'Ningún vehículo pasa por esa comunidad');

        DB::table('proveedor_ubicaciones')->insert([
            'proveedor_id' => $proveedorId, 'comunidad_id' => $comunidadId,
            'vehiculo_id' => $vehiculo->id, 'vigente_desde' => $vigente,
            'registrado_por' => $usuarioId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($vigente <= self::hoy()) {
            DB::table('proveedores')->where('id', $proveedorId)->update([
                'comunidad_id' => $comunidadId, 'vehiculo_id' => $vehiculo->id,
                'ubicacion_actualizada_en' => $vigente, 'updated_at' => now(),
            ]);
        }

        return $vehiculo;
    }

    /**
     * Corrige la comunidad cuando se equivocaron (no es un traslado nuevo):
     *  - si hay un traslado programado, se corrige su destino (no se mueve antes de tiempo)
     *  - si llegó a su comunidad por un traslado, se corrige ese traslado
     *    (y si la corrección es volver a donde estaba, el traslado se borra: no ocurrió)
     *  - si no, el error fue en el alta: se corrige el proveedor
     * Las entregas pasadas no cambian: cada una guarda con qué vehículo se recogió.
     * Devuelve qué se corrigió: PROGRAMADO, TRASLADO, TRASLADO_ANULADO o ALTA.
     */
    public static function corregir(int $proveedorId, int $comunidadId): string
    {
        $p = DB::table('proveedores')->find($proveedorId);
        $vehiculo = self::vehiculoDe($comunidadId);
        abort_if(! $vehiculo, 422, 'Ningún vehículo pasa por esa comunidad');
        $ubicacion = fn (string $desde) => DB::table('proveedor_ubicaciones')->where('proveedor_id', $proveedorId)
            ->where('vigente_desde', $desde)->orderByDesc('id')->value('id');
        $traslado = fn (string $desde) => DB::table('solicitudes_cambio_zona')->where('proveedor_id', $proveedorId)
            ->where('estado', 'APROBADA')->where('vigente_desde', $desde)->orderByDesc('id')->first();

        if ($prog = self::programados()->get($proveedorId)) {
            $s = $traslado($prog->desde);
            abort_if($s && (int) $s->comunidad_origen_id === $comunidadId, 422,
                'Esa es la comunidad donde ya está. Si el traslado no va, recházalo o regístralo de nuevo.');
            DB::table('proveedor_ubicaciones')->where('id', $ubicacion($prog->desde))
                ->update(['comunidad_id' => $comunidadId, 'vehiculo_id' => $vehiculo->id, 'updated_at' => now()]);
            if ($s) DB::table('solicitudes_cambio_zona')->where('id', $s->id)->update(['comunidad_destino_id' => $comunidadId, 'updated_at' => now()]);

            return 'PROGRAMADO';
        }

        // el traslado que lo trajo a la comunidad donde está ahora
        $s = DB::table('solicitudes_cambio_zona')->where('proveedor_id', $proveedorId)->where('estado', 'APROBADA')
            ->where('comunidad_destino_id', $p->comunidad_id)->where('vigente_desde', '<=', $p->ubicacion_actualizada_en ?? '9999-12-31')
            ->orderByDesc('vigente_desde')->orderByDesc('id')->first();
        $mover = fn () => DB::table('proveedores')->where('id', $proveedorId)
            ->update(['comunidad_id' => $comunidadId, 'vehiculo_id' => $vehiculo->id, 'updated_at' => now()]);

        if ($s && (int) $s->comunidad_origen_id === $comunidadId) {
            // "lo trasladamos por error": el traslado no ocurrió
            DB::table('proveedor_ubicaciones')->where('id', $ubicacion($s->vigente_desde))->delete();
            DB::table('solicitudes_cambio_zona')->where('id', $s->id)->delete();
            $mover();

            return 'TRASLADO_ANULADO';
        }
        if ($s) {
            DB::table('proveedor_ubicaciones')->where('id', $ubicacion($s->vigente_desde))
                ->update(['comunidad_id' => $comunidadId, 'vehiculo_id' => $vehiculo->id, 'updated_at' => now()]);
            DB::table('solicitudes_cambio_zona')->where('id', $s->id)->update(['comunidad_destino_id' => $comunidadId, 'updated_at' => now()]);
            $mover();

            return 'TRASLADO';
        }
        $mover();

        return 'ALTA';
    }

    /**
     * El traslado que todavía se puede corregir: el programado o, si no hay,
     * el que lo trajo a la comunidad donde está ahora. Null si no hay ninguno.
     */
    public static function trasladoEditable(int $proveedorId): ?object
    {
        $p = DB::table('proveedores')->find($proveedorId);
        $base = fn () => DB::table('solicitudes_cambio_zona as s')
            ->join('comunidades as o', 'o.id', '=', 's.comunidad_origen_id')->join('comunidades as d', 'd.id', '=', 's.comunidad_destino_id')
            ->where('s.proveedor_id', $proveedorId)->where('s.estado', 'APROBADA')
            ->orderByDesc('s.vigente_desde')->orderByDesc('s.id')
            ->select('s.*', 'o.nombre as origen', 'd.nombre as destino');

        if ($prog = self::programados()->get($proveedorId)) {
            $s = $base()->where('s.vigente_desde', $prog->desde)->first();
            if ($s) { $s->programado = true; return $s; }
        }
        $s = $base()->where('s.comunidad_destino_id', $p->comunidad_id)
            ->where('s.vigente_desde', '<=', $p->ubicacion_actualizada_en ?? '9999-12-31')->first();
        if ($s) $s->programado = false;

        return $s;
    }

    /**
     * Corrige el traslado: destino (si se equivocaron de lugar), fecha (solo si
     * todavía no se aplicó), cómo avisó y motivo. Devuelve el traslado corregido.
     */
    public static function editarTraslado(int $proveedorId, array $d): object
    {
        $s = self::trasladoEditable($proveedorId);
        abort_if(! $s, 422, 'Este proveedor no tiene un traslado para corregir.');
        $hoy = self::hoy();

        if ((int) $d['comunidad_id'] !== (int) $s->comunidad_destino_id) {
            abort_if((int) $d['comunidad_id'] === (int) $s->comunidad_origen_id, 422,
                "Esa es la comunidad de donde sale ({$s->origen}). Si el traslado no va, usa “Anular traslado”.");
            self::corregir($proveedorId, (int) $d['comunidad_id']);
        }

        $desde = $d['desde'] ?? $s->vigente_desde;
        if ($desde !== $s->vigente_desde) {
            abort_if(! $s->programado, 422, 'Ese traslado ya se hizo: su fecha no se cambia. Si no iba, anúlalo.');
            abort_if($desde < $hoy, 422, 'La fecha no puede ser pasada.');
            DB::table('proveedor_ubicaciones')->where('proveedor_id', $proveedorId)->where('vigente_desde', $s->vigente_desde)
                ->orderByDesc('id')->limit(1)->update(['vigente_desde' => $desde, 'updated_at' => now()]);
            DB::table('solicitudes_cambio_zona')->where('id', $s->id)
                ->update(['vigente_desde' => $desde, 'solicitada_para' => $desde, 'updated_at' => now()]);
            self::aplicarProgramados(); // si ahora es para hoy, se aplica ya
        }

        $cambios = ['updated_at' => now()];
        if (! empty($d['canal'])) $cambios['canal'] = $d['canal'];
        if (array_key_exists('motivo', $d)) $cambios['motivo'] = $d['motivo'] ?: null;
        DB::table('solicitudes_cambio_zona')->where('id', $s->id)->update($cambios);

        return DB::table('solicitudes_cambio_zona')->find($s->id);
    }

    /**
     * El traslado no iba: si estaba programado no pasa nada (no se mueve);
     * si ya se hizo, vuelve a la comunidad de donde salió. Si lo pidió por la
     * app queda como rechazado (para que lo vea); si lo registró la oficina se borra.
     */
    public static function anularTraslado(int $proveedorId): string
    {
        $s = self::trasladoEditable($proveedorId);
        abort_if(! $s, 422, 'Este proveedor no tiene un traslado para anular.');

        DB::table('proveedor_ubicaciones')->where('proveedor_id', $proveedorId)->where('vigente_desde', $s->vigente_desde)
            ->orderByDesc('id')->limit(1)->delete();
        if (! $s->programado) { // ya se había hecho: vuelve a donde estaba
            $vehiculo = self::vehiculoDe((int) $s->comunidad_origen_id);
            abort_if(! $vehiculo, 422, 'Ningún vehículo pasa por la comunidad de origen.');
            DB::table('proveedores')->where('id', $proveedorId)->update([
                'comunidad_id' => $s->comunidad_origen_id, 'vehiculo_id' => $vehiculo->id, 'updated_at' => now(),
            ]);
        }
        if ($s->canal === 'APP') {
            DB::table('solicitudes_cambio_zona')->where('id', $s->id)->update(['estado' => 'RECHAZADA', 'updated_at' => now()]);
        } else {
            DB::table('solicitudes_cambio_zona')->where('id', $s->id)->delete();
        }

        return $s->origen;
    }

    /** Aplica los cambios programados cuyo día ya llegó. Se llama al leer padrón, ruta y sync. */
    public static function aplicarProgramados(): int
    {
        $hoy = self::hoy();
        $listos = DB::table('proveedor_ubicaciones as u')->join('proveedores as p', 'p.id', '=', 'u.proveedor_id')
            ->where('u.vigente_desde', '<=', $hoy)
            ->where(fn ($q) => $q->whereNull('p.ubicacion_actualizada_en')->orWhereColumn('u.vigente_desde', '>', 'p.ubicacion_actualizada_en'))
            ->orderBy('u.vigente_desde')->orderBy('u.id')
            ->get(['u.proveedor_id', 'u.comunidad_id', 'u.vehiculo_id', 'u.vigente_desde']);

        // el último que ya entró en vigencia es el que vale
        foreach ($listos->groupBy('proveedor_id') as $proveedorId => $us) {
            $u = $us->last();
            DB::table('proveedores')->where('id', $proveedorId)->update([
                'comunidad_id' => $u->comunidad_id, 'vehiculo_id' => $u->vehiculo_id,
                'ubicacion_actualizada_en' => $u->vigente_desde, 'updated_at' => now(),
            ]);
        }

        return $listos->groupBy('proveedor_id')->count();
    }

    /**
     * Por proveedor, el cambio que lo trajo a la comunidad donde está ahora,
     * si fue en los últimos 15 días. (Los aprobados con el sistema anterior se
     * aplicaron al momento aunque su fecha fuera futura: también cuentan.)
     */
    public static function recientes(): Collection
    {
        $desde = Carbon::today('America/Lima')->subDays(self::DIAS_RECIENTE)->toDateString();

        return DB::table('solicitudes_cambio_zona as s')
            ->join('proveedores as p', fn ($j) => $j->on('p.id', '=', 's.proveedor_id')->on('p.comunidad_id', '=', 's.comunidad_destino_id'))
            ->join('comunidades as o', 'o.id', '=', 's.comunidad_origen_id')
            ->join('comunidades as d', 'd.id', '=', 's.comunidad_destino_id')
            ->where('s.estado', 'APROBADA')->where('s.vigente_desde', '>=', $desde)
            ->where(fn ($q) => $q->whereNull('p.ubicacion_actualizada_en')->orWhereColumn('s.vigente_desde', '<=', 'p.ubicacion_actualizada_en'))
            ->orderBy('s.vigente_desde')->orderBy('s.id')
            ->get(['s.proveedor_id', 's.vigente_desde as desde', 's.canal', 's.comunidad_origen_id', 'o.nombre as antes', 'd.nombre as ahora'])
            ->keyBy('proveedor_id')
            ->map(function ($c) {
                $c->antes_vehiculo = self::vehiculoDe($c->comunidad_origen_id)?->codigo;
                $c->antes_vehiculo_id = self::vehiculoDe($c->comunidad_origen_id)?->id;
                return $c;
            });
    }

    /**
     * Cambios con fecha futura que todavía no se aplicaron, por proveedor.
     * (Los aprobados antes de que existiera lo "programado" ya se aplicaron
     * aunque su fecha sea futura: esos tienen ubicacion_actualizada_en = su fecha.)
     */
    public static function programados(): Collection
    {
        return DB::table('proveedor_ubicaciones as u')->join('comunidades as c', 'c.id', '=', 'u.comunidad_id')
            ->join('vehiculos as v', 'v.id', '=', 'u.vehiculo_id')->join('proveedores as p', 'p.id', '=', 'u.proveedor_id')
            ->where('u.vigente_desde', '>', self::hoy())
            ->where(fn ($q) => $q->whereNull('p.ubicacion_actualizada_en')->orWhereColumn('u.vigente_desde', '>', 'p.ubicacion_actualizada_en'))
            ->orderBy('u.vigente_desde')
            ->get(['u.proveedor_id', 'u.vigente_desde as desde', 'u.comunidad_id', 'c.nombre as comunidad', 'v.codigo as vehiculo'])
            ->keyBy('proveedor_id');
    }

    /** Historial: dónde se inscribió y cada cambio aprobado, del más antiguo al más nuevo. */
    public static function historial(int $proveedorId): array
    {
        $p = DB::table('proveedores as p')->join('comunidades as c', 'c.id', '=', 'p.comunidad_id')
            ->where('p.id', $proveedorId)->first(['p.id', 'p.nombre', 'p.dni', 'p.created_at', 'c.nombre as comunidad']);
        abort_if(! $p, 404, 'Proveedor no encontrado');

        $cambios = DB::table('solicitudes_cambio_zona as s')
            ->join('comunidades as o', 'o.id', '=', 's.comunidad_origen_id')
            ->join('comunidades as d', 'd.id', '=', 's.comunidad_destino_id')
            ->leftJoin('users as u', 'u.id', '=', 's.registrada_por')
            ->leftJoin('users as r', 'r.id', '=', 's.resuelta_por')
            ->where('s.proveedor_id', $proveedorId)->where('s.estado', 'APROBADA')
            ->orderBy('s.vigente_desde')->orderBy('s.id')
            ->get(['s.vigente_desde as desde', 's.canal', 's.motivo', 'o.nombre as antes', 'd.nombre as comunidad',
                'u.name as registro', 'r.name as aprobo']);

        $prog = self::programados()->get($proveedorId); // solo este es "futuro": los demás ya se aplicaron
        $pasos = [['desde' => substr((string) $p->created_at, 0, 10), 'comunidad' => $cambios->first()->antes ?? $p->comunidad,
            'como' => 'Inscripción', 'motivo' => null, 'quien' => null, 'futuro' => false]];
        foreach ($cambios as $c) {
            $pasos[] = ['desde' => $c->desde, 'comunidad' => $c->comunidad,
                'como' => ['APP' => 'Lo pidió por la app', 'LLAMADA' => 'Llamó por teléfono', 'OFICINA' => 'Vino a Huata'][$c->canal] ?? $c->canal,
                'motivo' => $c->motivo, 'quien' => $c->registro ?? $c->aprobo,
                'futuro' => $prog !== null && $c->desde === $prog->desde && $c->comunidad === $prog->comunidad];
        }

        return ['proveedor' => ['id' => $p->id, 'nombre' => $p->nombre, 'dni' => $p->dni, 'comunidad' => $p->comunidad], 'pasos' => $pasos];
    }
}
