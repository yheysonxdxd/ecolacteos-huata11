<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Kardex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Kardex de insumos: entradas (compras), salidas (producción) y ajustes de almacén. */
class KardexController extends Controller
{
    /**
     * GET /api/v1/compras/kardex?tipo=insumo|producto|leche&id=&desde=AAAA-MM-DD&hasta=AAAA-MM-DD
     * (también acepta insumo_id=, como antes)
     */
    public function ver(Request $r)
    {
        $data = $r->validate([
            'tipo' => ['nullable', 'in:insumo,producto,leche'],
            'id' => ['nullable', 'integer'],
            'insumo_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);
        $tipo = $data['tipo'] ?? 'insumo';
        $id = $data['id'] ?? $data['insumo_id'] ?? null;
        abort_if($tipo !== 'leche' && ! $id, 422, 'Elige qué insumo o producto quieres ver.');

        return response()->json(Kardex::de($tipo, $id ? (int) $id : null, $data['desde'] ?? null, $data['hasta'] ?? null));
    }

    /**
     * POST /api/v1/compras/ajustes { insumo_id, tipo: ENTRADA|SALIDA, cantidad, motivo }
     * Para lo que no es compra ni producción: merma, vencido, conteo físico, devolución.
     */
    public function ajustar(Request $r)
    {
        $data = $r->validate([
            'insumo_id' => ['required', 'exists:insumos,id'],
            'tipo' => ['required', 'in:ENTRADA,SALIDA'],
            'cantidad' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'motivo' => ['required', 'string', 'min:3', 'max:120'],
        ], ['motivo.required' => 'Cuenta qué pasó (se malogró, se venció, al contar no cuadra…).']);

        return DB::transaction(function () use ($data, $r) {
            $i = DB::table('insumos')->lockForUpdate()->find($data['insumo_id']);
            $cant = round((float) $data['cantidad'], 3) * ($data['tipo'] === 'SALIDA' ? -1 : 1);
            abort_if($i->stock + $cant < -0.0005, 422,
                "No se puede sacar más de lo que hay: el stock de {$i->nombre} es ".(float) $i->stock." {$i->unidad}.");

            DB::table('insumos')->where('id', $i->id)->increment('stock', $cant);
            DB::table('movimientos_insumo')->insert([
                'uuid' => (string) Str::uuid(), 'insumo_id' => $i->id, 'cantidad' => $cant,
                'referencia' => 'Corrección · '.trim($data['motivo']), 'origen_type' => 'ajuste', 'origen_id' => null,
                'registrado_por' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return response()->json(['insumo' => $i->nombre, 'stock' => round((float) $i->stock + $cant, 3)], 201);
        });
    }
}
