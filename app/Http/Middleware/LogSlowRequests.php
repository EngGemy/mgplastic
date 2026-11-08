<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogSlowRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $queries = 0;
        $queryMs = 0.0;

        DB::listen(function ($query) use (&$queries, &$queryMs) {
            $queries++;
            $queryMs += (float) $query->time;
        });

        /** @var Response $response */
        $response = $next($request);

        $durationMs = (microtime(true) - $started) * 1000;

        // Non-breaking measurement header for mobile/devtools (body unchanged).
        if ($request->is('api/*')) {
            $response->headers->set('X-Response-Time-Ms', (string) (int) round($durationMs));
        }

        // Logging happens in terminate so the client is not delayed further.
        $request->attributes->set('_perf_started', $started);
        $request->attributes->set('_perf_queries', $queries);
        $request->attributes->set('_perf_query_ms', $queryMs);
        $request->attributes->set('_perf_duration_ms', $durationMs);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $started = $request->attributes->get('_perf_started');
        if (! is_numeric($started)) {
            return;
        }

        $durationMs = (float) $request->attributes->get(
            '_perf_duration_ms',
            (microtime(true) - (float) $started) * 1000
        );
        $peakMb = memory_get_peak_usage(true) / 1048576;
        $queries = (int) $request->attributes->get('_perf_queries', 0);
        $queryMs = (float) $request->attributes->get('_perf_query_ms', 0);

        $apiThreshold = max(100, (int) config('app.api_slow_ms', 1000));
        $isApi = $request->is('api/*');
        $shouldLog = $isApi
            ? ($durationMs >= $apiThreshold || $peakMb >= 128)
            : ($durationMs >= 3000 || $peakMb >= 128);

        if (! $shouldLog) {
            return;
        }

        Log::channel('performance')->warning('slow_request', [
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round($durationMs),
            'peak_memory_mb' => round($peakMb, 1),
            'query_count' => $queries,
            'query_time_ms' => (int) round($queryMs),
        ]);
    }
}
