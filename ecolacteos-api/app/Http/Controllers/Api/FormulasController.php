<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Formulas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Fórmulas del queso y el yogurt: Compras las ve, el administrador las cambia. */
class FormulasController extends Controller
{
    /** GET /api/v1/compras/formulas — fórmulas, cuánto alcanza el stock y avisos. */
    public function index()
    {
        $tanque = Formulas::litrosEnTanque();
        $productos = Formulas::productos($tanque);

        return response()->json([
            'tanque' => $tanque,
            'productos' => $productos,
            'avisos' => Formulas::avisos($productos, $tanque),
            'insumos' => DB::table('insumos')->orderBy('nombre')->get(['id', 'nombre', 'unidad']),
        ]);
    }

    /**
     * POST /api/v1/admin/formulas/{producto}
     * { insumos: [{ insumo_id, por_100l }], procedimiento: ["paso 1", ...] }
     * Reemplaza la fórmula completa. Los lotes ya iniciados no cambian.
     */
    public function guardar(Request $r, int $producto)
    {
        abort_if(! DB::table('productos')->where('id', $producto)->exists(), 404, 'Producto no encontrado');
        $data = $r->validate([
            'insumos' => ['present', 'array'],
            'insumos.*.insumo_id' => ['required', 'distinct', 'exists:insumos,id'],
            'insumos.*.por_100l' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'procedimiento' => ['present', 'array', 'max:30'],
            'procedimiento.*' => ['nullable', 'string', 'max:300'],
        ], [
            'insumos.*.insumo_id.distinct' => 'Un insumo está repetido en la fórmula.',
            'insumos.*.por_100l.gt' => 'La cantidad por 100 L debe ser mayor a 0.',
        ]);

        DB::transaction(function () use ($data, $producto) {
            DB::table('recetas_insumo')->where('producto_id', $producto)->delete();
            foreach ($data['insumos'] as $i) {
                DB::table('recetas_insumo')->insert([
                    'producto_id' => $producto, 'insumo_id' => $i['insumo_id'], 'cantidad_por_100l' => $i['por_100l'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $pasos = array_values(array_filter(array_map('trim', $data['procedimiento']), fn ($p) => $p !== ''));
            DB::table('productos')->where('id', $producto)->update([
                'procedimiento' => json_encode($pasos, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
            ]);
        });

        return response()->json(collect(Formulas::productos())->firstWhere('id', $producto));
    }
}
