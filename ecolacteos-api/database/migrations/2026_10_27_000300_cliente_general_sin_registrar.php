<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El cliente general para vender sin registrar se llamaba "Vecino de Huata", y así salía en la nota
 * y en las listas aunque quien compró fuera de afuera. Ahora se llama "Cliente sin registrar":
 * en la nota sale el nombre de quien compró (ventas.comprador_nombre) y nada más.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('clientes')->where('nombre', 'Vecino de Huata')->where('tipo', 'DIRECTO')->whereNull('ruc')
            ->update(['nombre' => 'Cliente sin registrar', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('clientes')->where('nombre', 'Cliente sin registrar')->where('tipo', 'DIRECTO')->whereNull('ruc')
            ->update(['nombre' => 'Vecino de Huata', 'updated_at' => now()]);
    }
};
