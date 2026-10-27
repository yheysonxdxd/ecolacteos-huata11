<?php

use App\Services\Formulas;
use Illuminate\Database\Migrations\Migration;

/** Fórmula y pasos de Queso andino y Queso mantecoso (solo si todavía no tienen; ver Formulas::INICIALES). */
return new class extends Migration
{
    public function up(): void
    {
        Formulas::cargarIniciales();
    }

    public function down(): void
    {
        // no se borra: la planta pudo haber corregido las cantidades después
    }
};
