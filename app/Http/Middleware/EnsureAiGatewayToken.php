<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAiGatewayToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('ai.gateway_token', '');

        if ($configured === '') {
            return response()->json([
                'ok' => false,
                'error' => 'AI gateway is not configured (AI_GATEWAY_TOKEN missing).',
            ], 503);
        }

        $token = $request->bearerToken()
            ?? $request->header('X-AI-Gateway-Token')
            ?? '';

        if (! hash_equals($configured, (string) $token)) {
            return response()->json([
                'ok' => false,
                'error' => 'Unauthorized',
                'hint' => 'Send Authorization: Bearer <AI_GATEWAY_TOKEN>',
            ], 401)->withHeaders([
                'WWW-Authenticate' => 'Bearer realm="mg-plastic-mcp"',
            ]);
        }

        return $next($request);
    }
}
