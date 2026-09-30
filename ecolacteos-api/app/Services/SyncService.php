<?php

namespace App\Services;

use App\Models\AnalisisCalidad;
use App\Models\Entrega;
use App\Models\Proveedor;
use App\Models\SolicitudCambioZona;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sincronización del outbox de la app de campo.
 *
 * El cliente acumula operaciones offline, cada una con su propio uuid. push()
 * las aplica de forma idempotente: un uuid ya visto se responde "DUPLICADO"
 * sin escribir nada, así que reintentar tras un corte de señal es seguro.
 */
class SyncService
{
    public function __construct(
        private CalidadService $calidad,
        private AcopioService $acopio,
    ) {}

    public function push(array $operaciones, int $userId): array
    {
        $resultados = [];

        foreach ($operaciones as $op) {
            try {
                $resultados[] = DB::transaction(
                    fn () => $this->aplicar($op, $userId)
                );
            } catch (\Throwable $e) {
                // Un registro malo no debe bloquear el resto del lote.
                $resultados[] = [
                    'uuid'   => $op['uuid'] ?? null,
                    'estado' => 'ERROR',
                    'error'  => $e->getMessage(),
                ];
            }
        }

        return ['resultados' => $resultados, 'cursor' => now()->toIso8601String()];
    }

    private function aplicar(array $op, int $userId): array
    {
        $uuid = $op['uuid'];
        $ok = fn ($id = null, $extra = []) => array_merge(
            ['uuid' => $uuid, 'estado' => 'APLICADO', 'id' => $id], $extra
        );
        $dup = fn ($id) => ['uuid' => $uuid, 'estado' => 'DUPLICADO', 'id' => $id];

        switch ($op['tipo']) {
            case 'ENTREGA':
                if ($e = Entrega::where('uuid', $uuid)->first()) {
                    return $dup($e->id);
                }
                if ($corregida = app(CorreccionEntrega::class)->intentar($op, $userId)) {
                    return $corregida;
                }
                $prov = Proveedor::findOrFail($op['proveedor_id']);
                $e = Entrega::create([
                    'uuid'           => $uuid,
                    'reemplaza_uuid' => $op['reemplaza_uuid'] ?? null,
                    'proveedor_id'   => $prov->id,
                    'vehiculo_id'    => $prov->vehiculo_id,
                    'fecha'          => $op['fecha'],
                    'litros'         => $op['ausente'] ?? false ? 0 : $op['litros'],
                    'ausente'        => $op['ausente'] ?? false,
                    'registrado_por' => $userId,
                    'registrado_en'  => $op['registrado_en'],
                    'recibido_en'    => now(),
                ]);
                AceptacionAutomatica::aplicar($e); // calidad es por muestreo

                return $ok($e->id, [
                    'aviso_desvio' => $this->acopio->desvioPromedio($prov, (float) $e->litros),
                ]);

            case 'ANALISIS':
                if ($a = AnalisisCalidad::where('uuid', $uuid)->first()) {
                    return $dup($a->id);
                }
                $a = AnalisisCalidad::create(array_merge(
                    ['uuid' => $uuid, 'analista_id' => $userId],
                    collect($op)->only([
                        'entrega_id', 'muestra', 'grasa', 'proteina', 'densidad',
                        'temperatura', 'agua_anadida', 'ph', 'significada',
                        'fuente', 'lactoscan_serie', 'observaciones', 'tomada_en',
                    ])->all()
                ));
                $res = $this->calidad->resolver($a, $op['aceptar'] ?? false, $userId);

                return $ok($a->id, [
                    'veredicto' => $res['evaluacion']['veredicto'],
                    'sancion'   => $res['sancion']?->only(['tipo', 'nivel', 'medida']),
                ]);

            case 'PROVEEDOR':
                if ($p = Proveedor::where('uuid', $uuid)->first()) {
                    return $dup($p->id);
                }
                // mismo DNI = misma persona (otro acopiador ya la registró)
                if ($p = Proveedor::where('dni', $op['dni'] ?? null)->first()) {
                    return array_merge($dup($p->id), ['nombre' => $p->nombre]);
                }
                $p = Proveedor::create(array_merge(
                    ['uuid' => $uuid, 'registrado_por' => $userId],
                    collect($op)->only([
                        'nombre', 'dni', 'comunidad_id', 'vehiculo_id',
                        'promedio_litros', 'vacas_ordeno', // ambos opcionales
                    ])->all()
                ));

                return $ok($p->id);

            case 'UBICACION':
                $p = Proveedor::findOrFail($op['proveedor_id']);
                $p->ubicaciones()->create([
                    'comunidad_id'   => $op['comunidad_id'],
                    'vehiculo_id'    => $op['vehiculo_id'],
                    'vigente_desde'  => $op['vigente_desde'] ?? now()->toDateString(),
                    'registrado_por' => $userId,
                ]);
                $p->update([
                    'comunidad_id'             => $op['comunidad_id'],
                    'vehiculo_id'              => $op['vehiculo_id'],
                    'ubicacion_actualizada_en' => now()->toDateString(),
                ]);

                return $ok($p->id);

            case 'CONFIRMACION':
                $e = Entrega::findOrFail($op['entrega_id']);
                $e->update(['confirmada_proveedor' => true, 'confirmada_en' => now()]);

                return $ok($e->id);

            case 'SOLICITUD_ZONA':
                if ($s = SolicitudCambioZona::where('uuid', $uuid)->first()) {
                    return $dup($s->id);
                }
                // se pide con 2-3 días de anticipación
                $s = SolicitudCambioZona::create([
                    'uuid'                 => $uuid,
                    'proveedor_id'         => $op['proveedor_id'],
                    'comunidad_origen_id'  => $op['comunidad_origen_id'],
                    'comunidad_destino_id' => $op['comunidad_destino_id'],
                    'motivo'               => $op['motivo'] ?? null,
                    'solicitada_para'      => $op['solicitada_para']
                        ?? now()->addDays(3)->toDateString(),
                ]);

                return $ok($s->id);

            default:
                abort(422, "Tipo de operación desconocido: {$op['tipo']}");
        }
    }

    /** Todo lo que cambió después del cursor, para refrescar el cache local. */
    public function pull(?string $desde): array
    {
        $d = $desde ? Carbon::parse($desde) : Carbon::today()->subDays(30);

        return [
            'cursor'      => now()->toIso8601String(),
            'proveedores' => Proveedor::with('comunidad:id,nombre')
                ->where('updated_at', '>', $d)->get(),
            'entregas'    => Entrega::where('updated_at', '>', $d)->get(),
            'analisis'    => AnalisisCalidad::where('updated_at', '>', $d)->get(),
            'solicitudes' => SolicitudCambioZona::where('updated_at', '>', $d)->get(),
        ];
    }
}
