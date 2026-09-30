<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pestaña "Comunidad" del productor: su asignación actual, a qué comunidades
 * puede pedir traslado y sus solicitudes. El proveedor sale siempre del
 * usuario con sesión (un productor no puede pedir cambios por otro).
 * El administrador las aprueba en admin/solicitudes/{id}/resolver.
 */
class ProductorZonaController extends Controller
{
    private const DIAS_ANTICIPACION = 3;

    /** GET /api/v1/productor/comunidad */
    public function ver(Request $r)
    {
        $p = $this->proveedor($r);

        $vehiculos = DB::table('vehiculos')
            ->leftJoin('users as acop', 'acop.id', '=', 'vehiculos.acopiador_id')
            ->select('vehiculos.id', 'vehiculos.codigo', 'vehiculos.orden_ruta', 'acop.name as acopiador')
            ->get();
        // comunidad_id => vehículo cuya ruta pasa por ahí (el mismo criterio que usa el admin al aprobar)
        $vehiculoDe = fn ($comunidadId) => $vehiculos
            ->first(fn ($v) => in_array($comunidadId, json_decode($v->orden_ruta ?? '[]', true)));

        $actual = DB::table('comunidades')->find($p->comunidad_id);
        $miVehiculo = $vehiculos->firstWhere('id', $p->vehiculo_id);
        $desde = $p->ubicacion_actualizada_en
            ?? DB::table('proveedor_ubicaciones')->where('proveedor_id', $p->id)->max('vigente_desde')
            ?? substr((string) $p->created_at, 0, 10);

        $destinos = DB::table('comunidades')->where('id', '!=', $p->comunidad_id)->orderBy('nombre')->get()
            ->map(function ($c) use ($vehiculoDe) {
                $v = $vehiculoDe($c->id);
                return $v ? [
                    'comunidad_id' => $c->id, 'comunidad' => $c->nombre,
                    'vehiculo' => $v->codigo, 'acopiador' => $v->acopiador,
                ] : null;
            })->filter()->values();

        $solicitudes = DB::table('solicitudes_cambio_zona as s')
            ->join('comunidades as d', 'd.id', '=', 's.comunidad_destino_id')
            ->where('s.proveedor_id', $p->id)
            ->orderByDesc('s.created_at')->limit(5)
            ->get(['s.id', 's.estado', 's.solicitada_para', 's.vigente_desde', 's.created_at', 'd.nombre as destino']);

        return response()->json([
            'asignacion' => [
                'comunidad_id' => $p->comunidad_id,
                'comunidad'    => $actual?->nombre,
                'vehiculo'     => $miVehiculo?->codigo,
                'acopiador'    => $miVehiculo?->acopiador,
                'desde'        => $desde,
            ],
            'destinos'            => $destinos,
            'solicitudes'         => $solicitudes,
            'dias_anticipacion'   => self::DIAS_ANTICIPACION,
        ]);
    }

    /** POST /api/v1/productor/solicitud-zona { comunidad_destino_id, motivo? } */
    public function solicitar(Request $r)
    {
        $data = $r->validate([
            'comunidad_destino_id' => ['required', 'integer', 'exists:comunidades,id'],
            'motivo'               => ['nullable', 'string', 'max:300'],
        ]);
        $p = $this->proveedor($r);

        abort_if($data['comunidad_destino_id'] == $p->comunidad_id, 422, 'Ya estás en esa comunidad.');
        abort_if(
            DB::table('solicitudes_cambio_zona')->where('proveedor_id', $p->id)->where('estado', 'PENDIENTE')->exists(),
            422, 'Ya tienes una solicitud esperando aprobación.'
        );

        $para = Carbon::today('America/Lima')->addDays(self::DIAS_ANTICIPACION)->toDateString();
        $id = DB::table('solicitudes_cambio_zona')->insertGetId([
            'uuid'                 => (string) Str::uuid(),
            'proveedor_id'         => $p->id,
            'comunidad_origen_id'  => $p->comunidad_id,
            'comunidad_destino_id' => $data['comunidad_destino_id'],
            'motivo'               => $data['motivo'] ?? null,
            'estado'               => 'PENDIENTE',
            'solicitada_para'      => $para,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        return response()->json(['id' => $id, 'estado' => 'PENDIENTE', 'solicitada_para' => $para], 201);
    }

    private function proveedor(Request $r): object
    {
        $p = DB::table('proveedores')->where('user_id', $r->user()->id)->first();
        abort_if(! $p, 404, 'Tu usuario no está enlazado a ningún proveedor.');

        return $p;
    }
}
