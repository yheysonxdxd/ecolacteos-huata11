<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
        // no hay página de login web: a la API sin sesión no se la redirige
        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*') ? null : '/');
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\LimitarLogin::class,
            \App\Http\Middleware\VerificarRol::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
        // la API siempre responde JSON (sin sesión: 401, no un 500 por "Route [login]")
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'));
    })->create();
