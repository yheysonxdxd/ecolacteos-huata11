<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mapa real de Huata en la web de oficina: cada comunidad en su lugar (GPS),
 * con lo acopiado en el día, y la ruta de cada vehículo.
 *
 * Las comunidades que todavía no tienen GPS salen en una posición aproximada
 * calculada desde el croquis; el administrador las arrastra a su lugar real.
 */
class MapaController extends Controller
{
    /** Huatta (el pueblo) como referencia: su punto en el croquis y en el mapa. */
    private const REF_X = 104;
    private const REF_Y = 266;
    private const REF_LAT = -15.6133;
    private const REF_LNG = -69.9725;
    /** Metros por unidad del croquis (el lienzo de 340 × 400 cubre unos 20 × 24 km). */
    private const METROS = 60;

    /** Posición aproximada desde el croquis: [latitud, longitud]. */
    public static function aproximar(float $x, float $y): array
    {
        $lat = self::REF_LAT - ($y - self::REF_Y) * self::METROS / 111_000;
        $lng = self::REF_LNG + ($x - self::REF_X) * self::METROS / (111_000 * cos(deg2rad(self::REF_LAT)));

        return [round($lat, 7), round($lng, 7)];
    }

    /** GET /api/v1/admin/mapa?fecha=AAAA-MM-DD (hoy si no se manda) */
    public function ver(Request $r)
    {
        $r->validate(['fecha' => ['nullable', 'date']]);
        $fecha = $r->query('fecha') ?: Carbon::today('America/Lima')->toDateString();

        $proveedores = DB::table('proveedores')->where('estado', 'ACTIVO')
            ->selectRaw('comunidad_id, COUNT(*) as total')->groupBy('comunidad_id')->pluck('total', 'comunidad_id');
        $entregas = DB::table('entregas as e')->join('proveedores as p', 'p.id', '=', 'e.proveedor_id')
            ->where('e.fecha', $fecha)
            ->selectRaw('p.comunidad_id, COUNT(DISTINCT e.proveedor_id) as registrados, SUM(CASE WHEN e.ausente THEN 0 ELSE e.litros END) as litros')
            ->groupBy('p.comunidad_id')->get()->keyBy('comunidad_id');

        $comunidades = DB::table('comunidades')->orderBy('nombre')->get()->map(function ($c) use ($proveedores, $entregas) {
            $gps = $c->latitud !== null && $c->longitud !== null;
            [$lat, $lng] = $gps ? [(float) $c->latitud, (float) $c->longitud] : self::aproximar($c->croquis_x, $c->croquis_y);

            return [
                'id' => $c->id, 'nombre' => $c->nombre, 'vias' => $c->vias,
                'latitud' => $lat, 'longitud' => $lng, 'aproximada' => ! $gps,
                'proveedores' => (int) ($proveedores[$c->id] ?? 0),
                'registrados' => (int) ($entregas[$c->id]->registrados ?? 0),
                'litros' => round((float) ($entregas[$c->id]->litros ?? 0), 2),
            ];
        });

        $vehiculos = DB::table('vehiculos as v')->leftJoin('users as u', 'u.id', '=', 'v.acopiador_id')
            ->where('v.activo', true)->orderBy('v.codigo')
            ->get(['v.id', 'v.codigo', 'v.tipo', 'v.ruta_circular', 'v.orden_ruta', 'u.name as acopiador'])
            ->map(fn ($v) => [
                'id' => $v->id, 'codigo' => $v->codigo, 'tipo' => $v->tipo, 'circular' => (bool) $v->ruta_circular,
                'acopiador' => $v->acopiador, 'ruta' => array_map('intval', json_decode($v->orden_ruta ?? '[]', true) ?: []),
            ]);

        return response()->json(['fecha' => $fecha, 'comunidades' => $comunidades, 'vehiculos' => $vehiculos]);
    }

    /** POST /api/v1/admin/comunidades/{id}/ubicacion { latitud, longitud } */
    public function ubicar(Request $r, int $id)
    {
        abort_unless(DB::table('comunidades')->where('id', $id)->exists(), 404, 'Comunidad no encontrada.');
        // alrededor de Huata (Puno): evita guardar un punto en otro lado por un arrastre equivocado
        $data = $r->validate([
            'latitud' => ['required', 'numeric', 'between:-16.2,-15.0'],
            'longitud' => ['required', 'numeric', 'between:-70.6,-69.4'],
        ], [
            'latitud.between' => 'Ese punto está fuera de Huata.',
            'longitud.between' => 'Ese punto está fuera de Huata.',
        ]);
        DB::table('comunidades')->where('id', $id)->update([
            'latitud' => round($data['latitud'], 7), 'longitud' => round($data['longitud'], 7), 'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'latitud' => (float) $data['latitud'], 'longitud' => (float) $data['longitud']]);
    }
}
