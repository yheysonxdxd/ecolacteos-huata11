<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe cada grupo de rutas de /api/v1 al rol que le corresponde.
 * ADMIN puede entrar a todo. Las rutas que no están en el mapa (login, yo,
 * logout, sync/*) quedan abiertas a cualquier usuario con sesión.
 *
 * Se aplica a todo el grupo "api" (bootstrap/app.php), así que no hace falta
 * tocar routes/api.php. Si no hay usuario, deja pasar: auth:sanctum responde 401.
 */
class VerificarRol
{
    /** Primer segmento después de api/v1/ => roles permitidos (además de ADMIN). */
    private const PERMISOS = [
        'admin'     => [],
        'planta'    => ['OPERARIO'],
        'compras'   => ['COMPRAS'],
        'calidad'   => ['CALIDAD'],
        'acopio'    => ['ACOPIADOR'],
        'vehiculos' => ['ACOPIADOR'],
        'productor' => ['PRODUCTOR'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $segmentos = $request->segments(); // ['api', 'v1', 'admin', ...]
        if (($segmentos[0] ?? null) !== 'api' || ($segmentos[1] ?? null) !== 'v1') {
            return $next($request);
        }

        $grupo = $segmentos[2] ?? null;
        if (! array_key_exists($grupo, self::PERMISOS)) {
            return $next($request);
        }

        $usuario = $request->user('sanctum');
        if (! $usuario) {
            return $next($request);
        }

        $permitidos = [...self::PERMISOS[$grupo], 'ADMIN'];
        if (! in_array($usuario->rol, $permitidos, true)) {
            return response()->json([
                'message' => 'Tu rol no tiene permiso para esta sección.',
            ], 403);
        }

        return $next($request);
    }
}
