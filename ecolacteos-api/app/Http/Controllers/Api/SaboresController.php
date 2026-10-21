<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Configuracion;
use App\Services\Formulas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Sabores y variedades: Yogurt de durazno = la fórmula del natural + el
 * saborizante de durazno. Solo se escribe lo que cambia; lo demás sale de la base.
 */
class SaboresController extends Controller
{
    /**
     * POST /api/v1/admin/sabores (nuevo) · /api/v1/admin/sabores/{id} (editar)
     * { base_id (solo al crear), sabor, nombre, insumo_id | insumo_nuevo: { nombre, unidad }, por_100l, paso?,
     *   precios?: { MAYORISTA, DIRECTO, PLANTA } (vacío = los de la base) }
     */
    public function guardar(Request $r, ?int $id = null)
    {
        $sabor = $id ? DB::table('productos')->find($id) : null;
        abort_if($id && (! $sabor || ! $sabor->base_id), 404, 'Ese producto no es un sabor');
        $d = $r->validate([
            'base_id' => [$id ? 'prohibited' : 'required', Rule::exists('productos', 'id')->whereNull('base_id')],
            'sabor' => ['required', 'string', 'min:2', 'max:40'],
            'nombre' => ['required', 'string', 'min:3', 'max:60', Rule::unique('productos', 'nombre')->ignore($id)],
            'insumo_id' => ['required_without:insumo_nuevo', 'nullable', 'exists:insumos,id'],
            'insumo_nuevo' => ['required_without:insumo_id', 'nullable', 'array'],
            'insumo_nuevo.nombre' => ['required_with:insumo_nuevo', 'string', 'min:3', 'max:80', Rule::unique('insumos', 'nombre')],
            'insumo_nuevo.unidad' => ['required_with:insumo_nuevo', 'string', 'max:12'],
            'por_100l' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'paso' => ['nullable', 'string', 'max:300'],
            'precios' => ['sometimes', 'array'],
            'precios.*' => ['nullable', 'numeric', 'min:0.1', 'max:10000'],
        ], [
            'base_id.exists' => 'Elige un producto base (no otro sabor).',
            'sabor.required' => 'Escribe el sabor (ej.: Durazno).', 'nombre.unique' => 'Ya hay un producto con ese nombre.',
            'insumo_id.required_without' => 'Elige qué le da el sabor (o crea el insumo).',
            'insumo_nuevo.nombre.unique' => 'Ese insumo ya existe: elígelo de la lista.',
            'por_100l.required' => '¿Cuánto lleva por cada 100 L?', 'por_100l.gt' => 'La cantidad debe ser mayor a 0.',
        ]);
        $u = $r->user()->id;
        $base = DB::table('productos')->find($id ? $sabor->base_id : $d['base_id']);

        $creando = ! $id;

        return DB::transaction(function () use ($d, $id, $sabor, $base, $u, $creando) {
            if ($nuevo = $d['insumo_nuevo'] ?? null) {
                $insumoId = DB::table('insumos')->insertGetId([
                    'nombre' => trim($nuevo['nombre']), 'unidad' => trim($nuevo['unidad']), 'stock' => 0, 'stock_minimo' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                Configuracion::anotar('Almacén · insumo nuevo', null, trim($nuevo['nombre'])." ({$nuevo['unidad']})", $u);
            }
            $insumo = DB::table('insumos')->find($insumoId ?? $d['insumo_id']);
            $paso = trim($d['paso'] ?? '') ?: Formulas::pasoSabor($base->tipo, $d['sabor'], $insumo->nombre, (float) $d['por_100l'], $insumo->unidad);

            if (! $id) {
                $id = Formulas::crearSabor($base, trim($d['nombre']), trim($d['sabor']), $insumo, (float) $d['por_100l'], $paso,
                    copiarPrecios: empty(array_filter($d['precios'] ?? [])));
                Configuracion::anotar("Productos · sabor nuevo de {$base->nombre}", null, trim($d['nombre']), $u);
            } else {
                DB::table('productos')->where('id', $id)->update([
                    'nombre' => trim($d['nombre']), 'variante' => trim($d['sabor']), 'paso_variante' => $paso, 'updated_at' => now(),
                ]);
                // lo propio del sabor: un solo insumo (el que le da el sabor)
                $antes = DB::table('recetas_insumo as r')->join('insumos as i', 'i.id', '=', 'r.insumo_id')->where('r.producto_id', $id)
                    ->get(['i.nombre', 'r.cantidad_por_100l'])->map(fn ($x) => "{$x->nombre} ".(float) $x->cantidad_por_100l)->join(', ');
                DB::table('recetas_insumo')->where('producto_id', $id)->delete();
                DB::table('recetas_insumo')->insert([
                    'producto_id' => $id, 'insumo_id' => $insumo->id, 'cantidad_por_100l' => $d['por_100l'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $q = 'Productos · '.trim($d['nombre']);
                Configuracion::anotar("$q · nombre", $sabor->nombre, trim($d['nombre']), $u);
                Configuracion::anotar("$q · lleva", $antes, "{$insumo->nombre} ".(float) $d['por_100l'], $u);
            }

            // al crear: los precios escritos (si no se escribe ninguno, se copian los de la base).
            // Después los precios se cambian en Configuración > Precios.
            $hoy = now('America/Lima')->toDateString();
            foreach ($creando ? array_filter($d['precios'] ?? [], fn ($p) => $p !== null && $p !== '') : [] as $tipo => $precio) {
                if (! in_array($tipo, ['MAYORISTA', 'DIRECTO', 'PLANTA'], true)) continue;
                DB::table('precios_venta')->where('producto_id', $id)->where('tipo_cliente', $tipo)->whereNull('vigente_hasta')
                    ->update(['vigente_hasta' => $hoy, 'updated_at' => now()]);
                DB::table('precios_venta')->insert([
                    'producto_id' => $id, 'tipo_cliente' => $tipo, 'precio' => $precio, 'vigente_desde' => $hoy,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return response()->json(['id' => $id, 'nombre' => trim($d['nombre'])]);
        });
    }
}
