<?php

namespace App\Http\Controllers\Api;

use App\Services\Configuracion;
use App\Http\Controllers\Controller;
use App\Models\Comunidad;
use App\Models\Parametro;
use App\Models\Proveedor;
use App\Models\Vehiculo;
use App\Services\CambiosComunidad;
use App\Services\Croquis;
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
        CambiosComunidad::aplicarProgramados(); // así el cambio del día baja en este pull

        return response()->json($this->sync->pull($r->query('desde')));
    }

    /**
     * Carga inicial: todo lo que la app necesita para operar días sin señal,
     * incluido el croquis de comunidades y los parámetros de calidad para
     * evaluar offline. croquis_x / croquis_y salen de la ubicación real de cada
     * comunidad (la del mapa de la web), pasada al dibujo de la app (Croquis).
     */
    public function bootstrap()
    {
        CambiosComunidad::aplicarProgramados();
        $recientes = CambiosComunidad::recientes();
        $proveedores = Proveedor::activos()->with('comunidad:id,nombre')->get();

        return response()->json([
            'cursor'      => now()->toIso8601String(),
            'vehiculos'   => Vehiculo::with('zona:id,nombre', 'acopiador:id,name')->get(),
            'comunidades' => Comunidad::all()->map(function ($c) {
                [$x, $y] = Croquis::deComunidad($c);
                return ['id' => $c->id, 'zona_id' => $c->zona_id, 'nombre' => $c->nombre,
                    'croquis_x' => $x, 'croquis_y' => $y, 'vias' => $c->vias];
            }),
            // "🆕 Nuevo en tu ruta · antes en Carata" los primeros 15 días tras un cambio de comunidad
            'proveedores' => $proveedores->map(fn ($p) => $p->setAttribute('cambio_reciente', ($c = $recientes->get($p->id))
                ? ['desde' => $c->desde, 'antes' => $c->antes, 'antes_vehiculo_id' => $c->antes_vehiculo_id] : null)),
            // "Ya no está en tu ruta": se cambiaron a otra comunidad que recoge otro vehículo
            'se_fueron'   => $recientes->map(function ($c) use ($proveedores) {
                $p = $proveedores->firstWhere('id', $c->proveedor_id);
                return $p && $c->antes_vehiculo_id && $c->antes_vehiculo_id !== $p->vehiculo_id ? [
                    'proveedor_id' => $p->id, 'nombre' => $p->nombre, 'de_vehiculo_id' => $c->antes_vehiculo_id,
                    'antes' => $c->antes, 'ahora' => $c->ahora, 'desde' => $c->desde,
                ] : null;
            })->filter()->values(),
            'parametros'  => Parametro::pluck('valor', 'clave')->merge(['precio.leche_litro' => (string) Configuracion::precioLeche()]),
        ]);
    }
}
