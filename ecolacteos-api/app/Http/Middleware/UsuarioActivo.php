<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un trabajador desactivado (users.activo = false) queda fuera al instante:
 * el login ya lo rechazaba, pero un token emitido antes seguía sirviendo.
 * Se borran sus tokens y se responde 401 (la app vuelve al login).
 */
class UsuarioActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user('sanctum');

        if ($usuario && ! $usuario->activo) {
            $usuario->tokens()->delete();

            return response()->json(['message' => 'Tu usuario está desactivado. Habla con el administrador.'], 401);
        }

        return $next($request);
    }
}
