<?php

namespace App\Services;

use App\Models\Entrega;
use Illuminate\Support\Facades\DB;

/**
 * Corrección de entregas desde la app de campo.
 *
 * La base de datos permite una sola entrega por proveedor por día. Si llega
 * una ENTREGA nueva (otro uuid) para un proveedor que ya tiene entrega ese día,
 * se toma como corrección: se actualizan litros/ausente/hora en la misma fila,
 * la fila pasa a tener el uuid nuevo (y reemplaza_uuid = el anterior) y el
 * cambio queda en entrega_correcciones.
 *
 * Solo se corrige mientras calidad no la haya analizado (estado PENDIENTE).
 */
class CorreccionEntrega
{
    /**
     * Devuelve el resultado para el push, o null si no aplica (no hay entrega
     * ese día y se debe crear normalmente).
     */
    public function intentar(array $op, int $userId): ?array
    {
        $uuid = $op['uuid'];

        // un uuid que ya fue reemplazado: reintento viejo, no se vuelve a aplicar
        $previa = DB::table('entrega_correcciones')
            ->where('uuid_nuevo', $uuid)->orWhere('uuid_anterior', $uuid)->first();
        if ($previa) {
            return ['uuid' => $uuid, 'estado' => 'DUPLICADO', 'id' => $previa->entrega_id];
        }

        $e = Entrega::where('proveedor_id', $op['proveedor_id'] ?? 0)
            ->whereDate('fecha', $op['fecha'] ?? null)
            ->lockForUpdate()
            ->first();
        if (! $e) {
            return null;
        }

        if ($e->estado_calidad !== 'PENDIENTE') {
            throw new \RuntimeException(
                "{$e->proveedor->nombre} ya fue analizada en planta ({$e->estado_calidad}); no se puede corregir desde la app."
            );
        }

        $ausente = (bool) ($op['ausente'] ?? false);
        $litros = $ausente ? 0 : (float) $op['litros'];

        DB::table('entrega_correcciones')->insert([
            'entrega_id'            => $e->id,
            'uuid_anterior'         => $e->uuid,
            'uuid_nuevo'            => $uuid,
            'litros_antes'          => $e->litros,
            'litros_despues'        => $litros,
            'ausente_antes'         => $e->ausente,
            'ausente_despues'       => $ausente,
            'registrado_en_antes'   => $e->registrado_en,
            'registrado_en_despues' => $op['registrado_en'] ?? null,
            'corregido_por'         => $userId,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $e->update([
            'reemplaza_uuid'       => $e->uuid,
            'uuid'                 => $uuid,
            'litros'               => $litros,
            'ausente'              => $ausente,
            'registrado_por'       => $userId,
            'registrado_en'        => $op['registrado_en'] ?? $e->registrado_en,
            'recibido_en'          => now(),
            // los litros cambiaron: el productor tiene que volver a confirmarlos
            'confirmada_proveedor' => false,
            'confirmada_en'        => null,
        ]);

        return ['uuid' => $uuid, 'estado' => 'APLICADO', 'id' => $e->id, 'corregida' => true];
    }
}
