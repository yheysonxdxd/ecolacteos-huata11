<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Calidad pasa a trabajar por muestreo (AceptacionAutomatica): las entregas
 * que quedaron PENDIENTES sin análisis se aceptan con el precio vigente,
 * igual que las que lleguen desde ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        $precio = (float) (DB::table('parametros')->where('clave', 'precio.leche_litro')->value('valor') ?? 1.80);

        $ids = DB::table('entregas')->where('estado_calidad', 'PENDIENTE')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('analisis_calidad')
                ->whereColumn('analisis_calidad.entrega_id', 'entregas.id'))
            ->pluck('id');

        foreach ($ids as $id) {
            $e = DB::table('entregas')->find($id);
            DB::table('entregas')->where('id', $id)->update([
                'estado_calidad' => 'ACEPTADO',
                'precio_litro'   => $e->ausente ? null : $precio,
                'monto'          => $e->ausente ? 0 : round($e->litros * $precio, 2),
            ]);
        }
    }

    public function down(): void
    {
        // no se revierte: no se puede distinguir cuáles estaban pendientes
        // (para volver atrás, restaurar el respaldo del 29-set 23:30)
    }
};
