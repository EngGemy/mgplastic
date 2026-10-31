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

        // Logging happens in terminate so the client is not delayed.
        $request->attributes->set('_perf_started', $started);
        $request->attributes->set('_perf_queries', $queries);
        $request->attributes->set('_perf_query_ms', $queryMs);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $started = $request->attributes->get('_perf_started');
        if (! is_numeric($started)) {
            return;
        }

        $durationMs = (microtime(true) - (float) $started) * 1000;
        $peakMb = memory_get_peak_usage(true) / 1048576;
        $queries = (int) $request->attributes->get('_perf_queries', 0);
        $queryMs = (float) $request->attributes->get('_perf_query_ms', 0);

        if ($durationMs < 3000 && $peakMb < 128) {
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
