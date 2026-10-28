<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Compras;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Gastos de planta que no son insumos (reparaciones, mantenimiento, combustible, luz/agua/gas, transporte)
 * y los equipos a los que se les hacen: cada equipo con su historial, lo gastado en el año y,
 * si se le puso cada cuántos días, el aviso de mantenimiento. También las fotos de gastos y pérdidas.
 */
class GastosController extends Controller
{
    public const CATEGORIAS = [
        'REPARACION' => 'Reparación',
        'MANTENIMIENTO' => 'Mantenimiento o limpieza',
        'COMBUSTIBLE' => 'Combustible',
        'SERVICIOS' => 'Luz, agua o gas',
        'TRANSPORTE' => 'Transporte o flete',
        'OTRO' => 'Otro gasto',
    ];

    /** Días antes del mantenimiento en que ya se avisa. */
    private const DIAS_AVISO_MANTENIMIENTO = 7;

    /** GET /api/v1/compras/gastos?mes=2026-10|actual|todo — los gastos del mes, por categoría, contra el mes anterior, y los equipos. */
    public function index(Request $r)
    {
        [$desde, $hasta, $mes] = Compras::mes($r->query('mes', 'actual'));
        $q = DB::table('gastos_planta as g')->leftJoin('equipos as e', 'e.id', '=', 'g.equipo_id')->leftJoin('users as u', 'u.id', '=', 'g.registrado_por')
            ->when($desde, fn ($q) => $q->whereBetween('g.fecha', [$desde, $hasta]));
        $gastos = (clone $q)->orderByDesc('g.fecha')->orderByDesc('g.id')
            ->get(['g.id', 'g.fecha', 'g.categoria', 'g.equipo_id', 'e.nombre as equipo', 'g.monto', 'g.detalle', 'g.foto', 'u.name as usuario', 'g.registrado_por'])
            ->map(fn ($g) => [
                'id' => $g->id, 'fecha' => $g->fecha, 'categoria' => $g->categoria, 'categoria_texto' => self::CATEGORIAS[$g->categoria] ?? $g->categoria,
                'equipo_id' => $g->equipo_id, 'equipo' => $g->equipo, 'monto' => (float) $g->monto, 'detalle' => $g->detalle,
                'tiene_foto' => (bool) $g->foto, 'usuario' => $g->usuario, 'registrado_por' => $g->registrado_por,
            ]);
        $mes['gastado'] = round((float) $gastos->sum('monto'), 2);
        $mes['por_categoria'] = $gastos->groupBy('categoria')->map(fn ($g, $c) => ['categoria' => $c, 'texto' => self::CATEGORIAS[$c] ?? $c,
            'monto' => round((float) $g->sum('monto'), 2), 'veces' => $g->count()])->sortByDesc('monto')->values();
        $mes['anterior_gastado'] = null;
        if ($mes['anterior']) {
            [$aDesde, $aHasta] = Compras::mes($mes['anterior']);
            $mes['anterior_gastado'] = round((float) DB::table('gastos_planta')->whereBetween('fecha', [$aDesde, $aHasta])->sum('monto'), 2);
        }

        return response()->json(['gastos' => $gastos, 'periodo' => $mes, 'equipos' => self::equipos(), 'categorias' => self::CATEGORIAS]);
    }

    /**
     * Los equipos con lo que se les gastó en el año, su última reparación y su mantenimiento:
     * mantenimiento.dias = días que faltan (negativo: ya se pasó); null si no tiene cada cuántos días.
     */
    public static function equipos(): array
    {
        // un carro agregado después (en Configuración → Vehículos) aparece solo como equipo
        DB::table('vehiculos')->whereNotIn('id', DB::table('equipos')->whereNotNull('vehiculo_id')->select('vehiculo_id'))->get()
            ->each(fn ($v) => DB::table('equipos')->insertOrIgnore(['nombre' => ($v->tipo === 'MOTOCAR' ? 'Motocar ' : 'Carro ').$v->codigo,
                'tipo' => 'VEHICULO', 'vehiculo_id' => $v->id, 'created_at' => now(), 'updated_at' => now()]));
        $hoy = now('America/Lima')->startOfDay();
        $anio = $hoy->copy()->startOfYear()->toDateString();
        $porEquipo = DB::table('gastos_planta')->whereNotNull('equipo_id')->orderBy('fecha')->get()->groupBy('equipo_id');

        return DB::table('equipos')->orderByDesc('activo')->orderBy('tipo')->orderBy('nombre')->get()->map(function ($e) use ($porEquipo, $anio, $hoy) {
            $g = $porEquipo[$e->id] ?? collect();
            $ultimoMant = $g->whereIn('categoria', ['MANTENIMIENTO', 'REPARACION'])->last();
            $mant = null;
            if ($e->mantenimiento_dias) {
                // si nunca se le hizo, se cuenta desde que se agregó el equipo
                $base = Carbon::parse($ultimoMant->fecha ?? $e->created_at, 'America/Lima')->startOfDay();
                $toca = $base->copy()->addDays($e->mantenimiento_dias);
                $mant = ['toca_el' => $toca->toDateString(), 'dias' => (int) round($hoy->diffInDays($toca, false)),
                    'desde_registro' => ! $ultimoMant];
            }

            return [
                'id' => $e->id, 'nombre' => $e->nombre, 'tipo' => $e->tipo, 'vehiculo_id' => $e->vehiculo_id,
                'mantenimiento_dias' => $e->mantenimiento_dias, 'activo' => (bool) $e->activo,
                'gastado_anio' => round((float) $g->where('fecha', '>=', $anio)->sum('monto'), 2),
                'reparaciones_anio' => $g->where('fecha', '>=', $anio)->where('categoria', 'REPARACION')->count(),
                'ultimo' => ($u = $g->last()) ? ['fecha' => $u->fecha, 'categoria' => $u->categoria, 'detalle' => $u->detalle, 'monto' => (float) $u->monto] : null,
                'mantenimiento' => $mant,
                'avisar' => $mant !== null && $e->activo && $mant['dias'] <= self::DIAS_AVISO_MANTENIMIENTO,
            ];
        })->all();
    }

    /** POST /api/v1/compras/gastos { fecha, categoria, equipo_id?, monto, detalle?, foto? } */
    public function guardar(Request $r)
    {
        $data = $r->validate([
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Lima')->toDateString()],
            'categoria' => ['required', Rule::in(array_keys(self::CATEGORIAS))],
            'equipo_id' => ['nullable', 'exists:equipos,id'],
            'monto' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'detalle' => ['nullable', 'string', 'max:200', Rule::requiredIf(fn () => $r->input('categoria') === 'OTRO')],
            'foto' => ['nullable', 'string'],
        ], [
            'fecha.before_or_equal' => 'La fecha no puede ser después de hoy.',
            'categoria.required' => 'Elige qué tipo de gasto es.',
            'monto.required' => 'Escribe cuánto se pagó.',
            'monto.gt' => 'El monto debe ser mayor a 0.',
            'detalle.required' => 'Escribe en qué se gastó.',
        ]);
        // una reparación o un mantenimiento es de un equipo
        abort_if(in_array($data['categoria'], ['REPARACION', 'MANTENIMIENTO'], true) && empty($data['equipo_id']), 422,
            '¿A qué equipo se le hizo? Elígelo (o agrégalo si no está en la lista).');

        $ruta = empty($data['foto']) ? null : Compras::guardarFoto((object) ['codigo' => 'GASTO-'.Str::random(6)], $data['foto']);
        $id = DB::table('gastos_planta')->insertGetId([
            'uuid' => (string) Str::uuid(), 'fecha' => $data['fecha'], 'categoria' => $data['categoria'], 'equipo_id' => $data['equipo_id'] ?? null,
            'monto' => round((float) $data['monto'], 2), 'detalle' => isset($data['detalle']) ? trim($data['detalle']) ?: null : null,
            'foto' => $ruta, 'registrado_por' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'monto' => round((float) $data['monto'], 2)], 201);
    }

    /** POST /api/v1/compras/gastos/{id}/borrar — por si se anotó mal: el administrador, o quien lo anotó el mismo día. */
    public function borrar(Request $r, int $id)
    {
        $g = DB::table('gastos_planta')->find($id);
        abort_if(! $g, 404, 'Ese gasto no existe.');
        $esSuyoDeHoy = $g->registrado_por === $r->user()->id && Carbon::parse($g->created_at)->isToday();
        abort_unless($r->user()->rol === 'ADMIN' || $esSuyoDeHoy, 403, 'Solo el administrador puede borrar un gasto de otro día o de otra persona.');
        DB::table('gastos_planta')->where('id', $id)->delete();
        if ($g->foto) Storage::disk('local')->delete($g->foto);

        return response()->json(['ok' => true]);
    }

    /** POST /api/v1/compras/equipos[/{id}] { nombre, tipo?, mantenimiento_dias?, activo? } */
    public function equipo(Request $r, ?int $id = null)
    {
        $data = $r->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:80', Rule::unique('equipos', 'nombre')->ignore($id)],
            'tipo' => ['nullable', 'in:PLANTA,VEHICULO'],
            'mantenimiento_dias' => ['nullable', 'integer', 'min:1', 'max:730'],
            'activo' => ['boolean'],
        ], [
            'nombre.required' => 'Escribe el nombre del equipo (ej.: Pasteurizadora, Tina quesera).',
            'nombre.unique' => 'Ya hay un equipo con ese nombre.',
            'mantenimiento_dias.max' => 'Pon cada cuántos días le toca (hasta 730, dos años).',
        ]);
        $fila = ['nombre' => trim($data['nombre']), 'mantenimiento_dias' => $data['mantenimiento_dias'] ?? null, 'updated_at' => now()]
            + (array_key_exists('activo', $data) ? ['activo' => $data['activo']] : [])
            + (isset($data['tipo']) ? ['tipo' => $data['tipo']] : []);
        if ($id) {
            abort_if(! DB::table('equipos')->where('id', $id)->exists(), 404, 'Ese equipo no existe.');
            DB::table('equipos')->where('id', $id)->update($fila);
        } else {
            $id = DB::table('equipos')->insertGetId($fila + ['tipo' => $data['tipo'] ?? 'PLANTA', 'created_at' => now()]);
        }

        return response()->json(['id' => $id], 201);
    }

    /** GET /api/v1/compras/fotos/{tipo}/{id} — enlace firmado (30 min) para ver la foto de un gasto o de una pérdida. */
    public function enlaceFoto(string $tipo, int $id)
    {
        abort_if(! $this->rutaFoto($tipo, $id), 404, 'No tiene foto.');

        return response()->json(['url' => URL::temporarySignedRoute('foto.archivo', now()->addMinutes(30), ['tipo' => $tipo, 'id' => $id], absolute: false)]);
    }

    /** GET /api/v1/fotos/{tipo}/{id}?expires=…&signature=… */
    public function verFoto(string $tipo, int $id)
    {
        $ruta = $this->rutaFoto($tipo, $id);
        abort_if(! $ruta || ! Storage::disk('local')->exists($ruta), 404);

        return Storage::disk('local')->response($ruta, "$tipo-$id.".pathinfo($ruta, PATHINFO_EXTENSION));
    }

    private function rutaFoto(string $tipo, int $id): ?string
    {
        $tabla = ['gasto' => 'gastos_planta', 'perdida' => 'mermas'][$tipo] ?? null;

        return $tabla ? DB::table($tabla)->where('id', $id)->value('foto') : null;
    }
}
