<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Compras;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Personas (DNI) y empresas (RUC) que le venden insumos a la planta.
 * No son los proveedores de leche: esos están en el padrón.
 */
class VendedoresInsumoController extends Controller
{
    /** GET /api/v1/compras/vendedores — todos, con cuántas compras y cuánto se les pagó. */
    public function index()
    {
        $compras = DB::table('ordenes_compra')->whereNotNull('vendedor_id')->where('estado', 'RECIBIDA')
            ->selectRaw('vendedor_id, COUNT(*) as compras, SUM(total) as pagado, MAX(comprobante_fecha) as ultima')
            ->groupBy('vendedor_id')->get()->keyBy('vendedor_id');

        return response()->json([
            'vendedores' => DB::table('vendedores_insumo')->orderByDesc('activo')->orderBy('nombre')->get()->map(fn ($v) => [
                'id' => $v->id, 'tipo_documento' => $v->tipo_documento, 'documento' => $v->documento, 'nombre' => $v->nombre,
                'telefono' => $v->telefono, 'direccion' => $v->direccion, 'activo' => (bool) $v->activo,
                'compras' => (int) ($compras[$v->id]->compras ?? 0), 'pagado' => (float) ($compras[$v->id]->pagado ?? 0),
                'ultima' => $compras[$v->id]->ultima ?? null,
            ]),
        ]);
    }

    /** POST /api/v1/compras/vendedores { tipo_documento, documento, nombre, telefono?, direccion? } */
    public function guardar(Request $r)
    {
        $data = $this->validar($r);
        $id = DB::table('vendedores_insumo')->insertGetId($data + ['activo' => true, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id, 'nombre' => $data['nombre']], 201);
    }

    /** POST /api/v1/compras/vendedores/{id} { ..., activo? } */
    public function editar(Request $r, int $id)
    {
        abort_unless(DB::table('vendedores_insumo')->where('id', $id)->exists(), 404, 'Vendedor no encontrado.');
        $data = $this->validar($r, $id);
        if ($r->has('activo')) $data['activo'] = $r->boolean('activo');
        DB::table('vendedores_insumo')->where('id', $id)->update($data + ['updated_at' => now()]);

        return response()->json(['id' => $id, 'nombre' => $data['nombre']]);
    }

    private function validar(Request $r, ?int $id = null): array
    {
        $r->merge(['documento' => preg_replace('/\D/', '', (string) $r->input('documento'))]);
        $data = $r->validate([
            'tipo_documento' => ['required', Rule::in(['DNI', 'RUC'])],
            'documento' => ['required', Rule::unique('vendedores_insumo', 'documento')->ignore($id)],
            'nombre' => ['required', 'string', 'min:3', 'max:255'],
            'telefono' => ['nullable', 'regex:/^[0-9 +]{6,20}$/'],
            'direccion' => ['nullable', 'string', 'max:255'],
        ], [
            'tipo_documento.required' => 'Elige si es persona (DNI) o empresa (RUC).',
            'documento.required' => 'Escribe el DNI o RUC.',
            'documento.unique' => 'Ya hay un vendedor con ese DNI/RUC.',
            'nombre.required' => 'Escribe el nombre o la razón social.',
            'nombre.min' => 'El nombre es muy corto.',
            'telefono.regex' => 'El teléfono solo lleva números.',
        ]);
        abort_unless(Compras::documentoValido($data['tipo_documento'], $data['documento']), 422,
            $data['tipo_documento'] === 'DNI' ? 'El DNI debe tener 8 dígitos.' : 'El RUC debe tener 11 dígitos y empezar en 10, 15, 16, 17 o 20.');
        $data['nombre'] = trim($data['nombre']);

        return $data;
    }
}
