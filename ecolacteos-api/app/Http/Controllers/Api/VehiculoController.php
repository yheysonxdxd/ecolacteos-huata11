<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehiculo;
use Illuminate\Http\Request;

class VehiculoController extends Controller
{
    /**
     * POST /api/v1/vehiculos/{vehiculo}/tomar
     * El acopiador elige en la app con qué vehículo sale hoy: queda como su
     * acopiador y se libera cualquier otro vehículo que tuviera asignado.
     */
    public function tomar(Request $r, Vehiculo $vehiculo)
    {
        $uid = $r->user()->id;

        Vehiculo::where('acopiador_id', $uid)
            ->where('id', '!=', $vehiculo->id)
            ->update(['acopiador_id' => null]);

        $vehiculo->update(['acopiador_id' => $uid]);

        return response()->json($vehiculo->load('acopiador:id,name'));
    }
}
