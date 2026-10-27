<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Nombre de quien tiene un DNI (8 dígitos) o RUC (11), para no escribirlo a mano al vender.
 * Primero se busca en lo que ya tiene la planta (clientes, proveedores de leche, trabajadores);
 * si no está, se pregunta a RENIEC / SUNAT por Decolecta (api.decolecta.com, 1 000 consultas
 * gratis al mes). Sin DECOLECTA_TOKEN en el .env solo se busca en lo de la planta.
 */
class ConsultaDocumento
{
    /**
     * ['numero', 'nombre' (null si no se encontró), 'fuente' => cliente|proveedor|trabajador|reniec|sunat|null,
     *  'cliente_id' (si ya es cliente), 'sin_token' (no se pudo preguntar a RENIEC/SUNAT), 'error'?]
     */
    public static function buscar(string $numero): array
    {
        $r = ['numero' => $numero, 'nombre' => null, 'fuente' => null, 'cliente_id' => null, 'sin_token' => false];

        if ($c = DB::table('clientes')->where('ruc', $numero)->first(['id', 'nombre'])) {
            return ['nombre' => $c->nombre, 'fuente' => 'cliente', 'cliente_id' => $c->id] + $r;
        }
        if (strlen($numero) === 8) {
            if ($n = DB::table('proveedores')->where('dni', $numero)->value('nombre')) return ['nombre' => $n, 'fuente' => 'proveedor'] + $r;
            if ($n = DB::table('users')->where('dni', $numero)->value('name')) return ['nombre' => $n, 'fuente' => 'trabajador'] + $r;
        }

        $token = config('services.decolecta.token');
        if (! $token) return ['sin_token' => true] + $r;

        // lo mismo no se vuelve a preguntar (cada consulta cuenta para el límite del mes)
        $fuera = Cache::remember('documento.' . $numero, now()->addDays(30), fn () => self::preguntar($numero, $token));
        if (! $fuera) Cache::forget('documento.' . $numero); // un error o "no existe" no se guarda

        return ($fuera ?: ['error' => 'No se encontró ese ' . (strlen($numero) === 8 ? 'DNI en RENIEC' : 'RUC en SUNAT') . '. Escribe el nombre a mano.']) + $r;
    }

    /** Pregunta a Decolecta; null si no existe o no responde. */
    private static function preguntar(string $numero, string $token): ?array
    {
        $dni = strlen($numero) === 8;
        try {
            $resp = Http::withToken($token)->acceptJson()->timeout(8)
                ->get('https://api.decolecta.com/v1/' . ($dni ? 'reniec/dni' : 'sunat/ruc'), ['numero' => $numero]);
        } catch (\Throwable $e) {
            return null; // sin internet: se escribe a mano
        }
        if (! $resp->successful()) return null;
        $j = $resp->json();

        if ($dni) {
            // RENIEC lo da en mayúsculas y con apellidos primero: "Roxana Karina Delgado Huamani"
            $nombre = trim(implode(' ', array_filter([$j['first_name'] ?? '', $j['first_last_name'] ?? '', $j['second_last_name'] ?? ''])));
            return $nombre ? ['nombre' => Str::title(mb_strtolower($nombre)), 'fuente' => 'reniec'] : null;
        }
        // la razón social se deja como está en SUNAT ("COMERCIAL ALTIPLANO S.A.C.")
        return ! empty($j['razon_social']) ? ['nombre' => $j['razon_social'], 'fuente' => 'sunat',
            'direccion' => $j['direccion'] ?? null, 'estado' => $j['estado'] ?? null] : null;
    }
}
