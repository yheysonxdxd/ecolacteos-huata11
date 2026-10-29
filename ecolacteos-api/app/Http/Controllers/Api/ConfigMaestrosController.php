<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TiposPrecio;
use App\Services\Configuracion;
use App\Services\Formulas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Configuración, parte 2: los datos fijos de la planta que antes solo se
 * cambiaban en la base. Productos (otro queso, yogurt de sabores), almacén
 * (insumos, su unidad y su stock mínimo) y vehículos con su ruta (qué
 * comunidades recoge y en qué orden).
 * El stock no se cambia aquí: entra con compras y sale con producción o
 * con "Corregir" en Almacén, para que todo quede explicado.
 */
class ConfigMaestrosController extends Controller
{
    /** GET /api/v1/admin/configuracion/maestros */
    public function index()
    {
        $comunidades = DB::table('comunidades')->get()->keyBy('id');
        $proveedores = DB::table('proveedores')->where('estado', 'ACTIVO')->selectRaw('comunidad_id, COUNT(*) n')
            ->groupBy('comunidad_id')->pluck('n', 'comunidad_id');
        $vehiculos = DB::table('vehiculos as v')->leftJoin('users as u', 'u.id', '=', 'v.acopiador_id')->orderBy('v.codigo')
            ->get(['v.*', 'u.name as acopiador']);
        $enRuta = $vehiculos->flatMap(fn ($v) => json_decode($v->orden_ruta ?? '[]', true) ?: [])->all();

        $fila = fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'proveedores' => (int) ($proveedores[$c->id] ?? 0), 'con_gps' => $c->latitud !== null];

        $recetas = DB::table('recetas_insumo')->selectRaw('producto_id, COUNT(*) n')->groupBy('producto_id')->pluck('n', 'producto_id');
        $precios = DB::table('precios_venta')->whereNull('vigente_hasta')->get()->groupBy('producto_id');

        return response()->json([
            // rinde por 100 L (como se habla en la planta); en la base va por litro
            'productos' => DB::table('productos')->orderBy('nombre')->get()
                ->sortBy(fn ($p) => [$p->activo ? 0 : 1, $p->base_id ?? $p->id, $p->base_id ? 1 : 0])->values()
                ->map(fn ($p) => [
                    'id' => $p->id, 'nombre' => $p->nombre, 'tipo' => $p->tipo, 'unidad' => $p->unidad,
                    'base_id' => $p->base_id, 'variante' => $p->variante,
                    'rinde_min' => round((float) $p->rendimiento_min * 100, 2), 'rinde_max' => round((float) $p->rendimiento_max * 100, 2),
                    'vida_util_dias' => (int) $p->vida_util_dias, 'activo' => (bool) $p->activo,
                    'insumos_en_formula' => (int) ($recetas[$p->id] ?? 0),
                    'precios' => ($precios[$p->id] ?? collect())->mapWithKeys(fn ($x) => [$x->tipo_cliente => (float) $x->precio]),
                ]),
            'insumos' => DB::table('insumos')->orderBy('nombre')->get(['id', 'nombre', 'unidad', 'stock', 'stock_minimo'])
                ->map(fn ($i) => ['id' => $i->id, 'nombre' => $i->nombre, 'unidad' => $i->unidad, 'stock' => (float) $i->stock, 'minimo' => (float) $i->stock_minimo]),
            'vehiculos' => $vehiculos->map(fn ($v) => [
                'id' => $v->id, 'codigo' => $v->codigo, 'tipo' => $v->tipo, 'activo' => (bool) $v->activo,
                'acopiador_id' => $v->acopiador_id, 'acopiador' => $v->acopiador, 'ruta_circular' => (bool) $v->ruta_circular,
                'ruta' => collect(json_decode($v->orden_ruta ?? '[]', true) ?: [])->map(fn ($id) => $comunidades[$id] ?? null)->filter()->map($fila)->values(),
            ]),
            // comunidades que no recoge ningún vehículo (no debería haber, pero si las hay se ven)
            'sueltas' => $comunidades->reject(fn ($c) => in_array($c->id, $enRuta))->map($fila)->values(),
            'acopiadores' => DB::table('users')->where('rol', 'ACOPIADOR')->where('activo', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->name]),
        ]);
    }

    /**
     * POST /api/v1/admin/configuracion/productos — nuevo o editar.
     * { id?, nombre, tipo, unidad, rinde_min, rinde_max (unidades por 100 L de leche), vida_util_dias, activo?,
     *   copiar_de? (producto cuya fórmula y pasos se copian, solo al crear), precios?: { MAYORISTA, DIRECTO, PLANTA } }
     */
    public function producto(Request $r)
    {
        $d = $r->validate([
            'id' => ['nullable', 'exists:productos,id'],
            'nombre' => ['required', 'string', 'min:3', 'max:60', Rule::unique('productos', 'nombre')->ignore($r->input('id'))],
            'tipo' => ['required', 'in:QUESO,YOGURT,OTRO'],
            'unidad' => ['required', 'string', 'min:2', 'max:20'],
            'rinde_min' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'rinde_max' => ['required', 'numeric', 'gte:rinde_min', 'max:1000'],
            'vida_util_dias' => ['required', 'integer', 'min:1', 'max:3650'],
            'activo' => ['sometimes', 'boolean'],
            'copiar_de' => ['nullable', 'exists:productos,id'],
            'precios' => ['sometimes', 'array'],
            'precios.*' => ['nullable', 'numeric', 'min:0.1', 'max:10000'],
        ], [
            'nombre.required' => 'Escribe el nombre del producto.', 'nombre.unique' => 'Ya hay un producto con ese nombre.',
            'unidad.required' => 'Escribe cómo se cuenta (ej.: quesos de 1 kg, botellas de 1 L).',
            'rinde_min.required' => '¿Cuánto sale de 100 L de leche? Escribe lo mínimo.',
            'rinde_max.gte' => 'Lo máximo que sale no puede ser menor que lo mínimo.',
            'vida_util_dias.required' => 'Escribe cuántos días dura el producto.',
        ]);
        $u = $r->user()->id;
        $fila = [
            'nombre' => trim($d['nombre']), 'tipo' => $d['tipo'], 'unidad' => trim($d['unidad']),
            'rendimiento_min' => round($d['rinde_min'] / 100, 4), 'rendimiento_max' => round($d['rinde_max'] / 100, 4),
            'vida_util_dias' => $d['vida_util_dias'], 'updated_at' => now(),
        ] + (array_key_exists('activo', $d) ? ['activo' => $d['activo']] : []);

        return DB::transaction(function () use ($d, $fila, $u) {
            if ($id = $d['id'] ?? null) {
                $antes = DB::table('productos')->find($id);
                if ($antes->base_id) {
                    // un sabor hereda de su base todo menos el nombre y si se produce
                    $base = DB::table('productos')->find($antes->base_id);
                    $fila = array_merge($fila, ['tipo' => $base->tipo, 'unidad' => $base->unidad, 'rendimiento_min' => $base->rendimiento_min,
                        'rendimiento_max' => $base->rendimiento_max, 'vida_util_dias' => $base->vida_util_dias]);
                    $d['rinde_min'] = (float) $base->rendimiento_min * 100; $d['rinde_max'] = (float) $base->rendimiento_max * 100;
                }
                DB::table('productos')->where('id', $id)->update($fila);
                $q = "Productos · {$fila['nombre']}";
                Configuracion::anotar("$q · nombre", $antes->nombre, $fila['nombre'], $u);
                Configuracion::anotar("$q · unidad", $antes->unidad, $fila['unidad'], $u);
                Configuracion::anotar("$q · rinde por 100 L",
                    (float) $antes->rendimiento_min * 100 . '–' . (float) $antes->rendimiento_max * 100, $d['rinde_min'] . '–' . $d['rinde_max'], $u);
                Configuracion::anotar("$q · días que dura", (int) $antes->vida_util_dias, (int) $fila['vida_util_dias'], $u);
                if (isset($fila['activo'])) {
                    Configuracion::anotar("$q · estado", $antes->activo ? 'se produce' : 'ya no se produce', $fila['activo'] ? 'se produce' : 'ya no se produce', $u);
                }
                // los sabores siguen a su base (mismo tipo, unidad, rendimiento y vida útil)
                DB::table('productos')->where('base_id', $id)->update(['tipo' => $fila['tipo'], 'unidad' => $fila['unidad'],
                    'rendimiento_min' => $fila['rendimiento_min'], 'rendimiento_max' => $fila['rendimiento_max'],
                    'vida_util_dias' => $fila['vida_util_dias'], 'updated_at' => now()]);
            } else {
                $id = DB::table('productos')->insertGetId($fila + ['activo' => $fila['activo'] ?? true, 'created_at' => now()]);
                Configuracion::anotar('Productos · producto nuevo', null, "{$fila['nombre']} ({$fila['unidad']})", $u);
                // un queso nuevo parecido a otro: se copia su fórmula completa (si es un sabor, la de su base
                // más lo suyo) y sus pasos, y luego se ajusta. Para un sabor se usa "Agregar sabor".
                if ($origen = $d['copiar_de'] ?? null) {
                    foreach (Formulas::recetas([$origen])[$origen] ?? [] as $x) {
                        DB::table('recetas_insumo')->insert([
                            'producto_id' => $id, 'insumo_id' => $x->id, 'cantidad_por_100l' => $x->cantidad_por_100l,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    $pasos = Formulas::pasos(DB::table('productos')->find($origen));
                    DB::table('productos')->where('id', $id)->update(['procedimiento' => json_encode($pasos, JSON_UNESCAPED_UNICODE)]);
                }
            }
            // precios que vengan escritos: rigen desde hoy (los demás se ponen luego en Precios)
            $hoy = now('America/Lima')->toDateString();
            foreach (array_filter($d['precios'] ?? [], fn ($p) => $p !== null) as $tipo => $precio) {
                if (! array_key_exists($tipo, TiposPrecio::nombres())) continue;
                $actual = DB::table('precios_venta')->where('producto_id', $id)->where('tipo_cliente', $tipo)->whereNull('vigente_hasta')->first();
                if ($actual && (float) $actual->precio === (float) $precio) continue;
                if ($actual && $actual->vigente_desde === $hoy) {
                    // cambiado dos veces el mismo día: se corrige ese mismo (igual que en Precios)
                    DB::table('precios_venta')->where('id', $actual->id)->update(['precio' => $precio, 'updated_at' => now()]);
                } else {
                    if ($actual) DB::table('precios_venta')->where('id', $actual->id)->update(['vigente_hasta' => $hoy, 'updated_at' => now()]);
                    DB::table('precios_venta')->insert([
                        'producto_id' => $id, 'tipo_cliente' => $tipo, 'precio' => $precio, 'vigente_desde' => $hoy,
                        'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                Configuracion::anotar("Productos · {$fila['nombre']} · precio $tipo", $actual ? (float) $actual->precio : null, (float) $precio, $u);
            }

            return response()->json(['id' => $id, 'nombre' => $fila['nombre']]);
        });
    }

    /** POST /api/v1/admin/configuracion/insumos { id?, nombre, unidad, minimo } — nuevo o editar. */
    public function insumo(Request $r)
    {
        $d = $r->validate([
            'id' => ['nullable', 'exists:insumos,id'],
            'nombre' => ['required', 'string', 'min:3', 'max:80', Rule::unique('insumos', 'nombre')->ignore($r->input('id'))],
            'unidad' => ['required', 'string', 'max:12'],
            'minimo' => ['required', 'numeric', 'min:0', 'max:100000'],
        ], [
            'nombre.required' => 'Escribe el nombre del insumo.', 'nombre.unique' => 'Ya hay un insumo con ese nombre.',
            'unidad.required' => 'Escribe la unidad (kg, L, un…).', 'minimo.required' => 'Escribe el stock mínimo (0 si no hace falta aviso).',
        ]);
        $u = $r->user()->id;
        $fila = ['nombre' => trim($d['nombre']), 'unidad' => trim($d['unidad']), 'stock_minimo' => $d['minimo'], 'updated_at' => now()];

        return DB::transaction(function () use ($d, $fila, $u) {
            if ($id = $d['id'] ?? null) {
                $antes = DB::table('insumos')->find($id);
                DB::table('insumos')->where('id', $id)->update($fila);
                $q = "Almacén · {$fila['nombre']}";
                Configuracion::anotar("$q · nombre", $antes->nombre, $fila['nombre'], $u);
                Configuracion::anotar("$q · unidad", $antes->unidad, $fila['unidad'], $u);
                Configuracion::anotar("$q · stock mínimo", (float) $antes->stock_minimo, (float) $fila['stock_minimo'], $u);
            } else {
                $id = DB::table('insumos')->insertGetId($fila + ['stock' => 0, 'created_at' => now()]);
                Configuracion::anotar("Almacén · insumo nuevo", null, "{$fila['nombre']} ({$fila['unidad']})", $u);
            }
            return response()->json(['id' => $id, 'nombre' => $fila['nombre']]);
        });
    }

    /** POST /api/v1/admin/configuracion/vehiculos { id?, codigo, tipo, acopiador_id?, activo? } */
    public function vehiculo(Request $r)
    {
        $r->merge(['codigo' => strtoupper(trim((string) $r->input('codigo')))]);
        $d = $r->validate([
            'id' => ['nullable', 'exists:vehiculos,id'],
            'codigo' => ['required', 'regex:/^[A-Z0-9-]{2,12}$/', Rule::unique('vehiculos', 'codigo')->ignore($r->input('id'))],
            'tipo' => ['required', 'in:CARRO,MOTOCAR'],
            'acopiador_id' => ['nullable', Rule::exists('users', 'id')->where('rol', 'ACOPIADOR')],
            'activo' => ['sometimes', 'boolean'],
        ], [
            'codigo.required' => 'Escribe el código del vehículo (ej.: C-04).', 'codigo.regex' => 'El código lleva letras, números y guion (ej.: C-04).',
            'codigo.unique' => 'Ya hay un vehículo con ese código.', 'acopiador_id.exists' => 'Elige un acopiador de la lista.',
        ]);
        $u = $r->user()->id;
        $nombre = fn ($id) => $id ? DB::table('users')->where('id', $id)->value('name') : 'sin acopiador';

        return DB::transaction(function () use ($d, $r, $u, $nombre) {
            if ($id = $d['id'] ?? null) {
                $antes = DB::table('vehiculos')->find($id);
                if ($r->has('activo') && ! $r->boolean('activo')) {
                    $con = DB::table('proveedores')->where('vehiculo_id', $id)->where('estado', 'ACTIVO')->count();
                    abort_if($con > 0, 422, "El {$antes->codigo} todavía recoge a $con proveedores: primero pasa sus comunidades a otro vehículo.");
                }
                DB::table('vehiculos')->where('id', $id)->update([
                    'codigo' => $d['codigo'], 'tipo' => $d['tipo'], 'acopiador_id' => $d['acopiador_id'] ?? null,
                    'ruta_circular' => $d['tipo'] === 'MOTOCAR', 'updated_at' => now(),
                ] + ($r->has('activo') ? ['activo' => $r->boolean('activo')] : []));
                $q = "Vehículo {$d['codigo']}";
                Configuracion::anotar("$q · código", $antes->codigo, $d['codigo'], $u);
                Configuracion::anotar("$q · tipo", $antes->tipo, $d['tipo'], $u);
                Configuracion::anotar("$q · acopiador", $nombre($antes->acopiador_id), $nombre($d['acopiador_id'] ?? null), $u);
                if ($r->has('activo')) Configuracion::anotar("$q · estado", $antes->activo ? 'activo' : 'sin usar', $r->boolean('activo') ? 'activo' : 'sin usar', $u);
            } else {
                // cada vehículo tiene su zona (el grupo de comunidades de su ruta)
                $zona = DB::table('zonas')->insertGetId(['nombre' => "Ruta {$d['codigo']}", 'created_at' => now(), 'updated_at' => now()]);
                $id = DB::table('vehiculos')->insertGetId([
                    'codigo' => $d['codigo'], 'tipo' => $d['tipo'], 'zona_id' => $zona, 'acopiador_id' => $d['acopiador_id'] ?? null,
                    'ruta_circular' => $d['tipo'] === 'MOTOCAR', 'orden_ruta' => '[]', 'activo' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                Configuracion::anotar('Vehículos · vehículo nuevo', null, "{$d['codigo']} (" . strtolower($d['tipo']) . ')', $u);
            }
            return response()->json(['id' => $id, 'codigo' => $d['codigo']]);
        });
    }

    /** POST /api/v1/admin/configuracion/vehiculos/{id}/ruta { orden: [comunidad_id, ...] } — el orden en que recoge. */
    public function ordenRuta(Request $r, int $id)
    {
        $v = DB::table('vehiculos')->find($id);
        abort_unless($v, 404, 'Vehículo no encontrado.');
        $actual = json_decode($v->orden_ruta ?? '[]', true) ?: [];
        $orden = array_map('intval', (array) $r->input('orden', []));
        $a = $actual; $b = $orden; sort($a); sort($b);
        abort_unless($a === $b, 422, 'El orden tiene que tener las mismas comunidades de la ruta.');

        DB::table('vehiculos')->where('id', $id)->update(['orden_ruta' => json_encode($orden), 'updated_at' => now()]);
        $nombres = DB::table('comunidades')->pluck('nombre', 'id');
        $texto = fn ($ids) => implode(' → ', array_map(fn ($c) => $nombres[$c] ?? '?', $ids));
        Configuracion::anotar("Vehículo {$v->codigo} · orden de la ruta", $texto($actual), $texto($orden), $r->user()->id);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v1/admin/configuracion/comunidades { id?, nombre, vehiculo_id }
     * Nueva comunidad (al final de la ruta del vehículo), cambiarle el nombre o
     * pasarla a otro vehículo: sus proveedores pasan con ella desde hoy.
     */
    public function comunidad(Request $r)
    {
        $d = $r->validate([
            'id' => ['nullable', 'exists:comunidades,id'],
            'nombre' => ['required', 'string', 'min:3', 'max:80', Rule::unique('comunidades', 'nombre')->ignore($r->input('id'))],
            'vehiculo_id' => ['required', Rule::exists('vehiculos', 'id')->where('activo', true)],
        ], [
            'nombre.required' => 'Escribe el nombre de la comunidad.', 'nombre.unique' => 'Ya hay una comunidad con ese nombre.',
            'vehiculo_id.required' => 'Elige qué vehículo la recoge.', 'vehiculo_id.exists' => 'Elige un vehículo que esté en uso.',
        ]);
        $u = $r->user()->id;
        $nombre = trim($d['nombre']);

        return DB::transaction(function () use ($d, $u, $nombre) {
            $vehiculos = DB::table('vehiculos')->lockForUpdate()->get()->keyBy('id');
            $nuevo = $vehiculos[$d['vehiculo_id']];
            $ruta = fn ($v) => json_decode($v->orden_ruta ?? '[]', true) ?: [];

            if ($id = $d['id'] ?? null) {
                $c = DB::table('comunidades')->find($id);
                $antes = $vehiculos->first(fn ($v) => in_array($id, $ruta($v)));
                DB::table('comunidades')->where('id', $id)->update(['nombre' => $nombre, 'zona_id' => $nuevo->zona_id, 'updated_at' => now()]);
                Configuracion::anotar("Comunidad {$nombre} · nombre", $c->nombre, $nombre, $u);
                if (! $antes || $antes->id !== $nuevo->id) {
                    if ($antes) {
                        DB::table('vehiculos')->where('id', $antes->id)->update(['orden_ruta' => json_encode(array_values(array_diff($ruta($antes), [$id]))), 'updated_at' => now()]);
                    }
                    DB::table('vehiculos')->where('id', $nuevo->id)->update(['orden_ruta' => json_encode([...$ruta($nuevo), (int) $id]), 'updated_at' => now()]);
                    // los proveedores de la comunidad los recoge desde hoy el vehículo nuevo
                    $n = DB::table('proveedores')->where('comunidad_id', $id)->update(['vehiculo_id' => $nuevo->id, 'updated_at' => now()]);
                    Configuracion::anotar("Comunidad {$nombre} · vehículo", $antes->codigo ?? 'ninguno', "{$nuevo->codigo} (con sus $n proveedores)", $u);
                }
            } else {
                // posición aproximada en el mapa: el centro de su ruta (luego se ubica bien en Mapa de Huata)
                $vecinas = DB::table('comunidades')->whereIn('id', $ruta($nuevo) ?: [0])->get(['croquis_x', 'croquis_y']);
                $id = DB::table('comunidades')->insertGetId([
                    'zona_id' => $nuevo->zona_id, 'nombre' => $nombre,
                    'croquis_x' => (int) round($vecinas->avg('croquis_x') ?? 200), 'croquis_y' => (int) round($vecinas->avg('croquis_y') ?? 157),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('vehiculos')->where('id', $nuevo->id)->update(['orden_ruta' => json_encode([...$ruta($nuevo), $id]), 'updated_at' => now()]);
                Configuracion::anotar('Comunidades · comunidad nueva', null, "{$nombre} (la recoge el {$nuevo->codigo})", $u);
            }
            return response()->json(['id' => $id, 'nombre' => $nombre, 'vehiculo' => $nuevo->codigo]);
        });
    }
}
