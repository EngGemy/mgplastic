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
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('api', \App\Http\Middleware\ResolveLocale::class);
        $middleware->alias([
            'role.api' => \App\Http\Middleware\EnsureApiRole::class,
            'ai.gateway' => \App\Http\Middleware\EnsureAiGatewayToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return response()->json([
                'status' => false,
                'message' => \App\Support\ArabicWait::message($seconds, 'المحاولة مرة أخرى'),
                'retry_after' => $seconds,
            ], 429);
        });

        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $model = class_basename($e->getModel() ?? '');
            $message = match ($model) {
                'Invoice' => 'الفاتورة غير موجودة',
                'InvoiceDistribution' => 'التوزيع غير موجود',
                'InvoiceReturn' => 'المرتجع غير موجود',
                'Order' => 'الطلب غير موجود',
                'User' => 'المستخدم غير موجود',
                'Product' => 'المنتج غير موجود',
                'WithdrawalRequest', 'Withdrawal' => 'طلب السحب غير موجود',
                default => 'السجل غير موجود',
            };

            return response()->json([
                'status' => false,
                'message' => $message,
            ], 404);
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $previous = $e->getPrevious();
            if ($previous instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return null; // let the ModelNotFoundException renderer handle it
            }

            return response()->json([
                'status' => false,
                'message' => 'المسار أو السجل غير موجود',
            ], 404);
        });
    })->create();
