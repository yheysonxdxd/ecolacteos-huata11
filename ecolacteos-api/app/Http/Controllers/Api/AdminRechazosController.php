<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rechazos de Calidad que no son adulteración: grasa o proteína baja, densidad,
 * leche caliente, pH o leche significada. La medida es capacitar al productor;
 * el admin ve quién, por qué, si se repite, y anota cuando ya se le capacitó.
 */
class AdminRechazosController extends Controller
{
    /** Desde cuántos rechazos en 30 días se avisa que el productor se repite. */
    private const REPITE = 3;

    /** GET /api/v1/admin/rechazos?semana=2026-10-05 (cualquier día de la semana; por defecto esta semana) */
    public function semana(Request $r)
    {
        $hoy = Carbon::today('America/Lima');
        $estaSemana = $hoy->copy()->startOfWeek(Carbon::MONDAY);
        $inicio = $r->query('semana') ? Carbon::parse($r->query('semana'))->startOfWeek(Carbon::MONDAY) : $estaSemana;
        if ($inicio->gt($estaSemana)) $inicio = $estaSemana;
        $fin = $inicio->copy()->endOfWeek(Carbon::SUNDAY);

        $filas = $this->consulta()
            ->whereBetween('e.fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->orderByDesc('e.fecha')->orderBy('p.nombre')
            ->get();

        // cuántas veces lo rechazaron en los 30 días hasta el último día de la semana
        $desde30 = $fin->copy()->subDays(29)->toDateString();
        $veces = $this->consulta()->whereIn('s.proveedor_id', $filas->pluck('proveedor_id')->unique())
            ->whereBetween('e.fecha', [$desde30, $fin->toDateString()])
            ->select('s.proveedor_id')->selectRaw('COUNT(*) n')->groupBy('s.proveedor_id')->pluck('n', 'proveedor_id');

        $lista = $filas->map(function ($f) use ($veces) {
            $causas = json_decode($f->causas ?? '[]', true) ?: [];
            $n = (int) ($veces[$f->proveedor_id] ?? 1);
            return [
                'id' => (int) $f->id, 'fecha' => $f->fecha, 'proveedor_id' => (int) $f->proveedor_id,
                'proveedor' => $f->proveedor, 'dni' => $f->dni, 'comunidad' => $f->comunidad, 'vehiculo' => $f->vehiculo,
                'litros' => (float) $f->litros, 'muestra' => $f->muestra,
                // false: Calidad la anotó pero igual se recibió (se pagó)
                'rechazada' => $f->estado_calidad === 'RECHAZADO',
                'causas' => $causas, 'grupos' => array_values(array_unique(array_map([self::class, 'grupo'], $causas))),
                'analista' => $f->analista, 'veces_30_dias' => $n, 'repite' => $n >= self::REPITE,
                'capacitado_en' => $f->capacitado_en, 'capacitado_por' => $f->capacitador, 'nota' => $f->capacitacion_nota,
            ];
        })->values();

        $porCausa = $lista->flatMap(fn ($f) => $f['grupos'])->countBy()->sortDesc()
            ->map(fn ($n, $causa) => ['causa' => $causa, 'veces' => $n])->values();

        return response()->json([
            'inicio' => $inicio->toDateString(), 'fin' => $fin->toDateString(),
            'anterior' => $inicio->copy()->subWeek()->toDateString(),
            'siguiente' => $inicio->lt($estaSemana) ? $inicio->copy()->addWeek()->toDateString() : null,
            'hasta' => $hoy->toDateString(),
            'resumen' => [
                'rechazos' => $lista->count(),
                'litros_rechazados' => round($lista->where('rechazada', true)->sum('litros'), 2),
                'proveedores' => $lista->pluck('proveedor_id')->unique()->count(),
                'por_causa' => $porCausa,
            ],
            // de todas las semanas: productores que todavía esperan su capacitación
            'por_capacitar' => $this->consulta()->whereNull('s.capacitado_en')->distinct()->count('s.proveedor_id'),
            'repite_desde' => self::REPITE,
            'filas' => $lista,
        ]);
    }

    /**
     * POST /api/v1/admin/rechazos/{id}/capacitado { nota? }
     * Una charla vale para todos sus rechazos pendientes hasta ese día.
     */
    public function capacitado(Request $r, int $id)
    {
        $d = $r->validate(['nota' => ['nullable', 'string', 'max:200']], ['nota.max' => 'La nota es muy larga (máximo 200 letras).']);
        $s = $this->consulta()->where('s.id', $id)->first();
        abort_unless($s, 404, 'Ese rechazo no existe.');

        $ids = $this->consulta()->where('s.proveedor_id', $s->proveedor_id)->where('e.fecha', '<=', $s->fecha)
            ->whereNull('s.capacitado_en')->pluck('s.id');
        DB::table('sanciones')->whereIn('id', $ids)->update([
            'capacitado_en' => now(), 'capacitado_por' => $r->user()->id,
            'capacitacion_nota' => trim((string) ($d['nota'] ?? '')) ?: null, 'updated_at' => now(),
        ]);

        return response()->json(['marcados' => $ids->count(), 'proveedor' => $s->proveedor]);
    }

    /** Sanciones de capacitación con su entrega, análisis, proveedor y quién capacitó. */
    private function consulta()
    {
        return DB::table('sanciones as s')
            ->join('analisis_calidad as a', 'a.id', '=', 's.analisis_id')
            ->join('entregas as e', 'e.id', '=', 'a.entrega_id')
            ->join('proveedores as p', 'p.id', '=', 's.proveedor_id')
            ->join('comunidades as c', 'c.id', '=', 'p.comunidad_id')
            ->join('vehiculos as v', 'v.id', '=', 'e.vehiculo_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.analista_id')
            ->leftJoin('users as k', 'k.id', '=', 's.capacitado_por')
            ->where('s.medida', 'CAPACITACION')
            ->select(['s.id', 's.proveedor_id', 's.capacitado_en', 's.capacitacion_nota', 'e.fecha', 'e.litros', 'e.estado_calidad',
                'a.causas', 'a.muestra', 'p.nombre as proveedor', 'p.dni', 'c.nombre as comunidad', 'v.codigo as vehiculo',
                'u.name as analista', 'k.name as capacitador']);
    }

    /** "temperatura 8.4 °C sobre 6 °C" → "Leche caliente", para el resumen por causa. */
    public static function grupo(string $causa): string
    {
        $c = mb_strtolower($causa);
        return match (true) {
            str_starts_with($c, 'grasa') => 'Grasa baja',
            str_starts_with($c, 'proteína') => 'Proteína baja',
            str_starts_with($c, 'densidad') => 'Densidad fuera de rango',
            str_starts_with($c, 'temperatura') => 'Leche caliente',
            str_starts_with($c, 'ph') => 'pH (acidez)',
            str_contains($c, 'significada') => 'Leche significada (al sol)',
            str_starts_with($c, 'agua') => 'Agua añadida',
            default => 'Otra',
        };
    }
}
