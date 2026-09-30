<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalisisCalidad;
use App\Models\Entrega;
use App\Models\Sancion;
use App\Services\CalidadService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CalidadController extends Controller
{
    public function __construct(private CalidadService $calidad) {}

    /** Entregas sin diagnóstico, para la pantalla "Cola". */
    public function cola()
    {
        return Entrega::with('proveedor:id,nombre,comunidad_id', 'proveedor.comunidad:id,nombre')
            ->where('ausente', false)
            ->where('estado_calidad', 'PENDIENTE')
            ->orderBy('registrado_en')
            ->get();
    }

    /** Rangos vigentes; la app los cachea para evaluar offline. */
    public function parametros()
    {
        return response()->json($this->calidad->rangos());
    }

    public function registrar(Request $r)
    {
        $data = $r->validate([
            'uuid'            => ['required', 'uuid'],
            'entrega_id'      => ['required', 'exists:entregas,id'],
            'muestra'         => ['required', 'in:CAMPO,PLANTA'],
            'grasa'           => ['nullable', 'numeric', 'between:0,15'],
            'proteina'        => ['nullable', 'numeric', 'between:0,10'],
            'densidad'        => ['nullable', 'numeric', 'between:0.9,1.2'],
            'temperatura'     => ['nullable', 'numeric', 'between:-5,40'],
            'agua_anadida'    => ['nullable', 'numeric', 'between:0,100'],
            'ph'              => ['nullable', 'numeric', 'between:3,9'],
            'significada'     => ['boolean'],
            'fuente'          => ['required', 'in:OCR,BLUETOOTH,MANUAL'],
            'lactoscan_serie' => ['nullable', 'string', 'max:20'],
            'observaciones'   => ['nullable', 'string'],
            'tomada_en'       => ['required', 'date'],
        ]);

        $analisis = AnalisisCalidad::updateOrCreate(
            ['uuid' => $data['uuid']],
            array_merge($data, ['analista_id' => $r->user()->id])
        );

        return response()->json([
            'analisis'   => $analisis,
            'evaluacion' => $this->calidad->evaluar($data), // veredicto en vivo
        ], 201);
    }

    /**
     * Importa los 6 parámetros del lactoscan sin digitar.
     *   - fuente=OCR: se sube la foto del ticket; el job la procesa y responde
     *     los valores para que el analista los confirme.
     *   - fuente=BLUETOOTH: la app ya leyó la trama del equipo y la manda aquí.
     * Nunca se guarda sin confirmación: si el ticket sale borroso el analista
     * corrige los valores antes de cerrar el diagnóstico.
     */
    public function importarLactoscan(Request $r)
    {
        $data = $r->validate([
            'fuente'          => ['required', 'in:OCR,BLUETOOTH'],
            'lactoscan_serie' => ['required', 'string', 'max:20'],
            'ticket'          => ['required_if:fuente,OCR', 'image', 'max:4096'],
            'trama'           => ['required_if:fuente,BLUETOOTH', 'string'],
        ]);

        if ($data['fuente'] === 'BLUETOOTH') {
            // trama tipo "FAT:3.60;PRO:3.20;DEN:1031;TMP:4.5;ADD:0.0;PH:6.70"
            $valores = collect(explode(';', $data['trama']))
                ->mapWithKeys(function ($par) {
                    [$k, $v] = array_pad(explode(':', $par), 2, null);

                    return [strtoupper(trim($k)) => (float) $v];
                });

            return response()->json([
                'confianza' => 1.0,
                'valores'   => [
                    'grasa'        => $valores['FAT'] ?? null,
                    'proteina'     => $valores['PRO'] ?? null,
                    'densidad'     => isset($valores['DEN']) ? $valores['DEN'] / 1000 : null,
                    'temperatura'  => $valores['TMP'] ?? null,
                    'agua_anadida' => $valores['ADD'] ?? null,
                    'ph'           => $valores['PH'] ?? null,
                ],
            ]);
        }

        $path = $r->file('ticket')->store('tickets');

        // Fase 1: OCR local (tesseract vía job). Devuelve los valores y su
        // confianza; la app los muestra editables antes de guardar.
        \App\Jobs\LeerTicketLactoscan::dispatchSync($path);

        return response()->json([
            'ticket_path' => $path,
            'confianza'   => null,
            'valores'     => cache("ocr:$path"),
            'aviso'       => 'Revisa los valores leídos antes de guardar.',
        ]);
    }

    /** Cierra el diagnóstico y aplica la política de sanciones. */
    public function resolver(Request $r, AnalisisCalidad $analisis)
    {
        $r->validate(['aceptar' => ['required', 'boolean']]);

        return response()->json(
            $this->calidad->resolver($analisis, $r->boolean('aceptar'), $r->user()->id)
        );
    }

    /** Comparación ruta vs. planta de una misma entrega. */
    public function brecha(Entrega $entrega)
    {
        return response()->json([
            'analisis' => $entrega->load('analisis')->analisis,
            'brecha'   => $this->calidad->brechaMuestreo($entrega),
        ]);
    }

    /**
     * Historial de infractores en tiempo real: reemplaza la lista impresa que
     * antes se revisaba dos veces al año.
     */
    public function infractores(Request $r)
    {
        return Sancion::with('proveedor:id,nombre,estado,comunidad_id', 'proveedor.comunidad:id,nombre')
            ->when($r->query('tipo'), fn ($q, $t) => $q->where('tipo', $t))
            ->when($r->query('desde'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->latest()
            ->paginate(50);
    }
}
