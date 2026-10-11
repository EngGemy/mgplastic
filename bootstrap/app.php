<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            require __DIR__.'/../routes/mcp.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('api', \App\Http\Middleware\ResolveLocale::class);
        $middleware->alias([
            'role.api' => \App\Http\Middleware\EnsureApiRole::class,
            'ai.gateway' => \App\Http\Middleware\EnsureAiGatewayToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
