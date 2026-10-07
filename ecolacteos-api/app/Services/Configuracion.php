<?php

namespace App\Services;

use App\Models\Parametro;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el administrador puede cambiar desde la web (Configuración), con su
 * nombre en palabras de todos los días, sus límites y el historial de cambios.
 * El precio de la leche va aparte: rige desde una fecha y cada entrega usa el de su día.
 */
class Configuracion
{
    /**
     * clave => [grupo, etiqueta, ayuda, tipo (texto|numero|ruc|porcentaje), unidad, min, max, por defecto]
     * "porcentaje" se guarda como fracción (0.667) y se muestra como 66,7 %.
     */
    public const CAMPOS = [
        'empresa.nombre'    => ['empresa', 'Nombre de la planta', 'Sale arriba en las notas de venta.', 'texto', null, 3, 120, 'Planta de Lácteos Ecolácteos Huata'],
        'empresa.ruc'       => ['empresa', 'RUC', '11 dígitos. Déjalo vacío si todavía no tienen.', 'ruc', null, null, null, ''],
        'empresa.direccion' => ['empresa', 'Dirección', null, 'texto', null, 0, 160, 'Huata, Puno'],
        'empresa.telefono'  => ['empresa', 'Teléfono', null, 'texto', null, 0, 30, ''],

        'precio.factor_adulteracion' => ['precios', 'Cuánto se paga por la leche adulterada', 'La 1.ª vez que a un productor se le detecta agua, esa entrega se paga a este porcentaje del precio.', 'porcentaje', '%', 0, 100, 0.667],

        'calidad.grasa_min'       => ['calidad', 'Grasa mínima', null, 'numero', '%', 1, 8, 3.0],
        'calidad.proteina_min'    => ['calidad', 'Proteína mínima', null, 'numero', '%', 1, 6, 2.9],
        'calidad.densidad_min'    => ['calidad', 'Densidad mínima', null, 'numero', 'g/ml', 1.0, 1.1, 1.028],
        'calidad.densidad_max'    => ['calidad', 'Densidad máxima', null, 'numero', 'g/ml', 1.0, 1.1, 1.034],
        'calidad.ph_min'          => ['calidad', 'pH mínimo', null, 'numero', null, 5, 8, 6.6],
        'calidad.ph_max'          => ['calidad', 'pH máximo', null, 'numero', null, 5, 8, 6.8],
        'calidad.temperatura_max' => ['calidad', 'Temperatura máxima al llegar', null, 'numero', '°C', 0, 30, 6.0],
        'calidad.agua_adulteracion'      => ['calidad', 'Agua añadida que ya es adulteración', 'Desde este porcentaje de agua el Lactoscan la marca como adulterada.', 'numero', '%', 0, 50, 5.0],
        'calidad.brecha_agua_tolerancia' => ['calidad', 'Diferencia de agua aceptada entre campo y planta', 'Si la muestra de planta tiene más agua que la de campo por encima de esto, se avisa.', 'numero', '%', 0, 20, 2.0],

        'conciliacion.tolerancia_pct' => ['acopio', 'Diferencia aceptada entre campo y planta', 'En la conciliación, si los litros que llegan a planta difieren más que esto de lo anotado en campo, el vehículo sale en rojo.', 'numero', '%', 0, 20, 1.5],
        'acopio.desvio_promedio_pct'  => ['acopio', 'Aviso de entrega rara', 'Al anotar litros, se avisa si un productor entrega más o menos que su promedio en este porcentaje.', 'numero', '%', 1, 200, 20],
    ];

    public const GRUPOS = ['empresa' => 'Empresa', 'precios' => 'Precios', 'calidad' => 'Calidad', 'acopio' => 'Acopio y planta'];

    /** Precio por litro de la leche que rige en [fecha] (por defecto hoy). */
    public static function precioLeche(Carbon|string|null $fecha = null): float
    {
        $dia = $fecha ? Carbon::parse($fecha)->toDateString() : Carbon::today('America/Lima')->toDateString();
        $p = DB::table('precios_leche')->where('vigente_desde', '<=', $dia)->orderByDesc('vigente_desde')->value('precio');

        return (float) ($p ?? Parametro::valor('precio.leche_litro', 1.80));
    }

    /** Los campos de un grupo con su valor actual, listos para la pantalla. */
    public static function campos(): array
    {
        $valores = DB::table('parametros')->pluck('valor', 'clave');

        return collect(self::CAMPOS)->map(function ($c, $clave) use ($valores) {
            [$grupo, $etiqueta, $ayuda, $tipo, $unidad, $min, $max, $defecto] = $c;
            $v = $valores[$clave] ?? $defecto;
            return [
                'clave' => $clave, 'grupo' => $grupo, 'etiqueta' => $etiqueta, 'ayuda' => $ayuda, 'tipo' => $tipo,
                'unidad' => $unidad, 'min' => $min, 'max' => $max,
                'valor' => $tipo === 'porcentaje' ? round((float) $v * 100, 1) : ($tipo === 'numero' ? (float) $v : (string) $v),
            ];
        })->values()->all();
    }

    /**
     * Revisa y guarda los valores que cambiaron; devuelve cuántos. Lanza 422 con un
     * mensaje claro si algo no cuadra (fuera de rango, mínimo mayor que el máximo…).
     *
     * @param  array<string, mixed>  $nuevos  clave => valor como se ve en pantalla
     */
    public static function guardar(array $nuevos, ?int $usuarioId): int
    {
        $guardar = [];
        foreach ($nuevos as $clave => $valor) {
            abort_unless(isset(self::CAMPOS[$clave]), 422, "No se puede cambiar «{$clave}».");
            [, $etiqueta, , $tipo, $unidad, $min, $max] = self::CAMPOS[$clave];
            $valor = is_string($valor) ? trim($valor) : $valor;

            if ($tipo === 'texto') {
                $largo = mb_strlen((string) $valor);
                abort_if($largo < $min, 422, "Escribe {$etiqueta}.");
                abort_if($largo > $max, 422, "{$etiqueta}: máximo {$max} letras.");
                $guardar[$clave] = (string) $valor;
            } elseif ($tipo === 'ruc') {
                $doc = preg_replace('/\D/', '', (string) $valor);
                abort_if($doc !== '' && ! Compras::documentoValido('RUC', $doc), 422, 'El RUC lleva 11 dígitos y empieza en 10, 15, 16, 17 o 20.');
                $guardar[$clave] = $doc;
            } else {
                $n = is_numeric(str_replace(',', '.', (string) $valor)) ? (float) str_replace(',', '.', (string) $valor) : null;
                abort_if($n === null, 422, "{$etiqueta}: escribe un número.");
                $u = $unidad ? " {$unidad}" : '';
                abort_if($n < $min || $n > $max, 422, "{$etiqueta}: tiene que estar entre {$min} y {$max}{$u}.");
                $guardar[$clave] = (string) ($tipo === 'porcentaje' ? round($n / 100, 4) : $n);
            }
        }

        // que los mínimos no queden por encima de los máximos
        $ver = fn ($k) => (float) ($guardar[$k] ?? Parametro::valor($k, self::CAMPOS[$k][7]));
        abort_if($ver('calidad.densidad_min') >= $ver('calidad.densidad_max'), 422, 'La densidad mínima tiene que ser menor que la máxima.');
        abort_if($ver('calidad.ph_min') >= $ver('calidad.ph_max'), 422, 'El pH mínimo tiene que ser menor que el máximo.');

        $antes = DB::table('parametros')->pluck('valor', 'clave');
        $cambios = 0;
        DB::transaction(function () use ($guardar, $antes, $usuarioId, &$cambios) {
            foreach ($guardar as $clave => $valor) {
                $viejo = $antes[$clave] ?? (string) self::CAMPOS[$clave][7]; // si nunca se guardó, regía el de por defecto
                if ($viejo !== null && is_numeric($viejo) && is_numeric($valor) && (float) $viejo === (float) $valor) continue;
                if ((string) $viejo === $valor) continue;
                Parametro::updateOrCreate(['clave' => $clave], ['valor' => $valor]); // limpia su caché
                DB::table('configuracion_historial')->insert([
                    'clave' => $clave, 'valor_antes' => $viejo, 'valor_nuevo' => $valor, 'usuario_id' => $usuarioId, 'created_at' => now(),
                ]);
                $cambios++;
            }
        });

        return $cambios;
    }

    /**
     * Anota un cambio en el historial. Para almacén, vehículos y comunidades la
     * clave ya es el texto que se muestra ("Almacén · Azúcar blanca · stock mínimo").
     */
    public static function anotar(string $clave, $antes, $despues, ?int $usuarioId): void
    {
        if ((string) $antes === (string) $despues) return;
        DB::table('configuracion_historial')->insert([
            'clave' => mb_substr($clave, 0, 80), 'valor_antes' => $antes === null ? null : mb_substr((string) $antes, 0, 250),
            'valor_nuevo' => $despues === null ? null : mb_substr((string) $despues, 0, 250), 'usuario_id' => $usuarioId, 'created_at' => now(),
        ]);
    }

    /** Nombre para mostrar en el historial: "Grasa mínima", "Precio de la leche"… */
    public static function etiqueta(string $clave): string
    {
        return match (true) {
            $clave === 'precio.leche_litro' => 'Precio de la leche por litro',
            str_starts_with($clave, 'venta.') => 'Precio de venta',
            default => self::CAMPOS[$clave][1] ?? $clave,
        };
    }
}
