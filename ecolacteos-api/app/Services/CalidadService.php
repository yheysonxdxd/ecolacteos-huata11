<?php

namespace App\Services;

use App\Models\AnalisisCalidad;
use App\Models\Entrega;
use App\Models\Parametro;
use App\Models\Proveedor;
use App\Models\Sancion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reglas de calidad y sanciones. La app Kotlin replica evaluar() para dar
 * veredicto offline, pero este resultado es el que vale.
 */
class CalidadService
{
    public function rangos(): array
    {
        return [
            'grasa_min'      => (float) Parametro::valor('calidad.grasa_min', 3.0),
            'proteina_min'   => (float) Parametro::valor('calidad.proteina_min', 2.9),
            'densidad_min'   => (float) Parametro::valor('calidad.densidad_min', 1.028),
            'densidad_max'   => (float) Parametro::valor('calidad.densidad_max', 1.034),
            'temperatura_max'=> (float) Parametro::valor('calidad.temperatura_max', 6.0),
            'ph_min'         => (float) Parametro::valor('calidad.ph_min', 6.6),
            'ph_max'         => (float) Parametro::valor('calidad.ph_max', 6.8),
            'agua_adulter'   => (float) Parametro::valor('calidad.agua_adulteracion', 5.0),
        ];
    }

    /** Devuelve ['veredicto', 'causas', 'adulterada', 'significada']. */
    public function evaluar(array $m): array
    {
        $r = $this->rangos();
        $causas = [];

        $adulterada = isset($m['agua_anadida'])
            && $m['agua_anadida'] >= $r['agua_adulter'];

        if ($adulterada) {
            $causas[] = "agua añadida {$m['agua_anadida']} % (≥ {$r['agua_adulter']} %)";
        }
        if (isset($m['grasa']) && $m['grasa'] < $r['grasa_min']) {
            $causas[] = "grasa {$m['grasa']} % bajo {$r['grasa_min']} %";
        }
        if (isset($m['proteina']) && $m['proteina'] < $r['proteina_min']) {
            $causas[] = "proteína {$m['proteina']} % bajo {$r['proteina_min']} %";
        }
        if (isset($m['densidad'])
            && ($m['densidad'] < $r['densidad_min'] || $m['densidad'] > $r['densidad_max'])) {
            $causas[] = "densidad fuera de {$r['densidad_min']}–{$r['densidad_max']}";
        }
        if (isset($m['temperatura']) && $m['temperatura'] > $r['temperatura_max']) {
            $causas[] = "temperatura {$m['temperatura']} °C sobre {$r['temperatura_max']} °C";
        }
        if (isset($m['ph']) && ($m['ph'] < $r['ph_min'] || $m['ph'] > $r['ph_max'])) {
            $causas[] = "pH {$m['ph']} fuera de {$r['ph_min']}–{$r['ph_max']}";
        }
        $significada = (bool) ($m['significada'] ?? false);
        if ($significada) {
            $causas[] = 'leche significada (expuesta al sol)';
        }

        return [
            'veredicto'   => $adulterada ? 'ADULTERADA' : (empty($causas) ? 'ACEPTADO' : 'RECHAZADO'),
            'causas'      => $causas,
            'adulterada'  => $adulterada,
            'significada' => $significada,
        ];
    }

    /**
     * Brecha del doble muestreo. Si el agua añadida sube >= 2 puntos entre ruta y
     * planta, el problema puede ser el manejo del bidón y no el proveedor: se
     * marca para revisión en vez de sancionar de una.
     */
    public function brechaMuestreo(Entrega $entrega): ?array
    {
        $campo  = $entrega->analisis->firstWhere('muestra', 'CAMPO');
        $planta = $entrega->analisis->firstWhere('muestra', 'PLANTA');
        if (! $campo || ! $planta) {
            return null;
        }
        $delta = (float) $planta->agua_anadida - (float) $campo->agua_anadida;
        $tol = (float) Parametro::valor('calidad.brecha_agua_tolerancia', 2.0);

        return [
            'delta_agua'        => round($delta, 2),
            'fuera_tolerancia'  => abs($delta) >= $tol,
            'delta_temperatura' => round((float) $planta->temperatura - (float) $campo->temperatura, 2),
            'delta_densidad'    => round((float) $planta->densidad - (float) $campo->densidad, 4),
        ];
    }

    /**
     * Cierra el diagnóstico: fija el veredicto, congela el precio y aplica la
     * sanción que corresponda. Todo en una transacción.
     */
    public function resolver(AnalisisCalidad $analisis, bool $aceptar, int $analistaId): array
    {
        return DB::transaction(function () use ($analisis, $aceptar, $analistaId) {
            $ev = $this->evaluar($analisis->only([
                'grasa', 'proteina', 'densidad', 'temperatura',
                'agua_anadida', 'ph', 'significada',
            ]));

            $analisis->update([
                'veredicto' => $ev['veredicto'],
                'causas'    => $ev['causas'],
            ]);

            $entrega   = $analisis->entrega;
            $proveedor = $entrega->proveedor;
            $precio    = (float) Parametro::valor('precio.leche_litro', 1.80);
            $sancion   = null;

            if (! $ev['adulterada'] && $aceptar && empty($ev['causas'])) {
                $entrega->update([
                    'estado_calidad' => 'ACEPTADO',
                    'precio_litro'   => $precio,
                    'monto'          => round($entrega->litros * $precio, 2),
                ]);

                return ['entrega' => $entrega->fresh(), 'sancion' => null, 'evaluacion' => $ev];
            }

            $tipo = $ev['adulterada'] ? 'ADULTERACION'
                : ($ev['significada'] ? 'SIGNIFICADA' : 'ACIDEZ');

            $previas = Sancion::where('proveedor_id', $proveedor->id)
                ->where('tipo', $tipo)->count();
            $nivel = $previas + 1;

            if ($tipo === 'ADULTERACION') {
                if ($nivel >= 2) {
                    // segunda vez: baja definitiva
                    $medida  = 'BAJA_DEFINITIVA';
                    $aplicado = null;
                    $proveedor->update([
                        'estado'      => 'BAJA',
                        'motivo_baja' => 'Segunda adulteración detectada (' .
                            $analisis->agua_anadida . ' % de agua añadida)',
                    ]);
                    $entrega->update(['estado_calidad' => 'RECHAZADO', 'monto' => 0]);
                } else {
                    // primera vez: se paga esa entrega a precio reducido
                    $factor  = (float) Parametro::valor('precio.factor_adulteracion', 0.667);
                    $medida  = 'PRECIO_REDUCIDO';
                    $aplicado = round($precio * $factor, 2);
                    $entrega->update([
                        'estado_calidad' => 'ACEPTADO',
                        'precio_litro'   => $aplicado,
                        'monto'          => round($entrega->litros * $aplicado, 2),
                    ]);
                }
            } else {
                // acidez y leche significada: capacitación, sin descuento
                $medida   = 'CAPACITACION';
                $aplicado = null;
                $entrega->update([
                    'estado_calidad' => $aceptar ? 'ACEPTADO' : 'RECHAZADO',
                    'precio_litro'   => $aceptar ? $precio : null,
                    'monto'          => $aceptar ? round($entrega->litros * $precio, 2) : 0,
                ]);
            }

            $sancion = Sancion::create([
                'uuid'            => (string) Str::uuid(),
                'proveedor_id'    => $proveedor->id,
                'analisis_id'     => $analisis->id,
                'tipo'            => $tipo,
                'nivel'           => $nivel,
                'medida'          => $medida,
                'precio_aplicado' => $aplicado,
                'detalle'         => implode(' · ', $ev['causas']),
                'resuelta_por'    => $analistaId,
            ]);

            return [
                'entrega'    => $entrega->fresh(),
                'sancion'    => $sancion,
                'evaluacion' => $ev,
            ];
        });
    }
}
