<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Frena a quien prueba contraseñas en POST /api/v1/login.
 * Solo cuentan los intentos fallidos: 5 por DNI y por IP cada minuto.
 * Un login correcto limpia el contador de ese DNI.
 */
class LimitarLogin
{
    private const MAX_INTENTOS = 5;
    private const SEGUNDOS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('post') || ! $request->is('api/v1/login')) {
            return $next($request);
        }

        $clave = 'login:' . $request->input('dni', '') . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($clave, self::MAX_INTENTOS)) {
            $espera = RateLimiter::availableIn($clave);

            return response()->json([
                'message' => "Demasiados intentos. Espera {$espera} segundos e inténtalo de nuevo.",
            ], 429)->header('Retry-After', $espera);
        }

        $respuesta = $next($request);

        if ($respuesta->getStatusCode() === 200) {
            RateLimiter::clear($clave);
        } else {
            RateLimiter::hit($clave, self::SEGUNDOS);
        }

        return $respuesta;
    }
}
