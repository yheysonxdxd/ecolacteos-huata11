<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tipos de precio (tabla tipos_precio): los tres de siempre (Mayorista, Cliente
 * directo, Proveedor de leche) y los que agregue el administrador. Cada cliente
 * tiene uno y cada producto un precio por tipo.
 */
class TiposPrecio
{
    /** Los tres que usa el sistema: no se quitan. */
    public const FIJOS = ['MAYORISTA', 'DIRECTO', 'PLANTA'];

    /** [{ codigo, nombre, icono, ayuda, fijo, activo, clientes }] en su orden. */
    public static function todos(bool $soloActivos = true): array
    {
        $clientes = DB::table('clientes')->where('activo', true)->selectRaw('tipo, COUNT(*) as n')->groupBy('tipo')->pluck('n', 'tipo');

        return DB::table('tipos_precio')->when($soloActivos, fn ($q) => $q->where('activo', true))
            ->orderBy('orden')->orderBy('nombre')->get()
            ->map(fn ($t) => ['codigo' => $t->codigo, 'nombre' => $t->nombre, 'icono' => $t->icono, 'ayuda' => $t->ayuda,
                'fijo' => (bool) $t->fijo, 'activo' => (bool) $t->activo, 'clientes' => (int) ($clientes[$t->codigo] ?? 0)])
            ->all();
    }

    /** codigo => nombre de los activos (MAYORISTA => Mayorista…). */
    public static function nombres(): array
    {
        return collect(self::todos())->pluck('nombre', 'codigo')->all();
    }

    public static function nombre(?string $codigo): ?string
    {
        return $codigo ? (DB::table('tipos_precio')->where('codigo', $codigo)->value('nombre') ?? $codigo) : null;
    }

    /** Los que se pueden elegir para un cliente: todos menos el de proveedor de leche (lo pone el sistema). */
    public static function paraClientes(): array
    {
        return array_values(array_diff(array_keys(self::nombres()), ['PLANTA']));
    }

    /** Crea uno nuevo; el código sale del nombre ("Bodega del mercado" → BODEGA_DEL_MERCADO). */
    public static function crear(string $nombre, ?string $icono, ?string $ayuda): array
    {
        $nombre = trim($nombre);
        abort_if(mb_strlen($nombre) < 3, 422, 'Escribe el nombre del precio (ej. Bodega, Feria, Restaurante).');
        abort_if(DB::table('tipos_precio')->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->exists(), 422, "Ya hay un precio «{$nombre}».");
        $base = substr(Str::upper(Str::slug($nombre, '_')), 0, 16) ?: 'PRECIO';
        $codigo = $base;
        for ($i = 2; DB::table('tipos_precio')->where('codigo', $codigo)->exists(); $i++) $codigo = $base . '_' . $i;
        DB::table('tipos_precio')->insert([
            'codigo' => $codigo, 'nombre' => $nombre, 'icono' => $icono ?: null, 'ayuda' => $ayuda ?: null,
            'orden' => (int) DB::table('tipos_precio')->max('orden') + 1, 'fijo' => false, 'activo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['codigo' => $codigo, 'nombre' => $nombre];
    }
}
