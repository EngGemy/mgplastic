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
        $middleware->api(
            prepend: [
                \App\Http\Middleware\ResolveLocale::class,
            ],
            append: [
                // Reject deleted/inactive Sanctum users + purge orphan tokens.
                \App\Http\Middleware\EnsureApiUserIsValid::class,
            ],
        );
        $middleware->alias([
            'role.api' => \App\Http\Middleware\EnsureApiRole::class,
            'ai.gateway' => \App\Http\Middleware\EnsureAiGatewayToken::class,
            'api.user.valid' => \App\Http\Middleware\EnsureApiUserIsValid::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $bearer = $request->bearerToken();
            $code = 'unauthenticated';
            $message = 'Unauthenticated.';

            $status = 401;

            if (filled($bearer)) {
                try {
                    $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($bearer);
                } catch (\Throwable) {
                    $accessToken = null;
                }

                if ($accessToken) {
                    $tokenable = $accessToken->tokenable;
                    if ($tokenable instanceof \App\Models\User && ! $tokenable->is_phone_verified) {
                        $tokenable->tokens()->delete();
                        $code = 'otp_required';
                        $message = 'يجب توثيق الهاتف قبل استخدام التطبيق';
                        $status = 403;
                    } elseif (! $tokenable instanceof \App\Models\User
                        || ! $tokenable->is_active
                        || ! \App\Models\User::query()->whereKey($tokenable->id)->where('is_active', true)->exists()) {
                        $accessToken->delete();
                        if ($tokenable instanceof \App\Models\User) {
                            $tokenable->tokens()->delete();
                        }
                        $code = 'account_revoked';
                        $message = 'حسابك موقوف أو تم حذفه — سجّل الدخول من جديد';
                    }
                }
            }

            return response()->json([
                'status' => false,
                'message' => $message,
                'code' => $code,
                'requires_otp' => $code === 'otp_required',
            ], $status);
        });

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
