<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sesiones abiertas de la app (tokens Sanctum), para el administrador.
 * Sirve para sacar a alguien que perdió el celular o dejó de trabajar.
 * Solo ADMIN: las rutas van bajo admin/ (VerificarRol).
 */
class SesionesController extends Controller
{
    /** GET /api/v1/admin/sesiones — trabajadores con sesiones vigentes. */
    public function index()
    {
        $minutos = config('sanctum.expiration');
        $desde = $minutos ? now()->subMinutes($minutos) : null;

        $filas = DB::table('personal_access_tokens as t')
            ->join('users as u', 'u.id', '=', 't.tokenable_id')
            ->where('t.tokenable_type', User::class)
            ->when($desde, fn ($q) => $q->where('t.created_at', '>=', $desde))
            ->groupBy('u.id', 'u.name', 'u.rol')
            ->orderBy('u.name')
            ->get([
                'u.id', 'u.name as nombre', 'u.rol',
                DB::raw('COUNT(*) as sesiones'),
                DB::raw('MAX(COALESCE(t.last_used_at, t.created_at)) as ultimo_uso'),
            ]);

        return response()->json(['usuarios' => $filas]);
    }

    /** POST /api/v1/admin/usuarios/{id}/cerrar-sesiones — cierra todas sus sesiones. */
    public function cerrar(Request $r, int $id)
    {
        $u = User::findOrFail($id);
        $cerradas = $u->tokens()->delete();

        return response()->json([
            'cerradas' => $cerradas,
            'propia'   => $u->id === $r->user()->id,
        ]);
    }
}
