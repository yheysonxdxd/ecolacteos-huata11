<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comunidad;
use App\Models\Parametro;
use App\Models\Proveedor;
use App\Models\Vehiculo;
use App\Services\SyncService;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(private SyncService $sync) {}

    /** Sube el outbox acumulado offline. Idempotente por uuid. */
    public function push(Request $r)
    {
        $data = $r->validate([
            'operaciones'          => ['required', 'array', 'max:500'],
            'operaciones.*.uuid'   => ['required', 'uuid'],
            'operaciones.*.tipo'   => ['required', 'string'],
        ]);

        return response()->json(
            // validate() solo devuelve uuid y tipo; los demás campos de cada
            // operación (proveedor_id, litros, grasa…) vienen del input completo
            $this->sync->push($r->input('operaciones'), $r->user()->id)
        );
    }

    /** Cambios desde el último cursor. */
    public function pull(Request $r)
    {
        $r->validate(['desde' => ['nullable', 'date']]);

        return response()->json($this->sync->pull($r->query('desde')));
    }

    /**
     * Carga inicial: todo lo que la app necesita para operar días sin señal,
     * incluido el croquis de comunidades (coordenadas relativas, no GPS) y los
     * parámetros de calidad para evaluar offline.
     */
    public function bootstrap()
    {
        return response()->json([
            'cursor'      => now()->toIso8601String(),
            'vehiculos'   => Vehiculo::with('zona:id,nombre', 'acopiador:id,name')->get(),
            'comunidades' => Comunidad::select(
                'id', 'zona_id', 'nombre', 'croquis_x', 'croquis_y', 'vias'
            )->get(),
            'proveedores' => Proveedor::activos()
                ->with('comunidad:id,nombre')->get(),
            'parametros'  => Parametro::pluck('valor', 'clave'),
        ]);
    }
}
