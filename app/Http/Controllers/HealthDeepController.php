<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthDeepController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('services.health.deep_token', '');
        $provided = (string) $request->header('X-Health-Token', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['ok' => false, 'message' => 'forbidden'], 403);
        }

        $started = microtime(true);
        $dbMs = null;
        $dbOk = false;
        try {
            DB::select('select 1');
            $dbOk = true;
            $dbMs = (int) round((microtime(true) - $started) * 1000);
        } catch (\Throwable $e) {
            $dbMs = (int) round((microtime(true) - $started) * 1000);
        }

        $cacheOk = false;
        try {
            Cache::put('health:deep:ping', 1, 10);
            $cacheOk = Cache::get('health:deep:ping') === 1;
        } catch (\Throwable) {
            $cacheOk = false;
        }

        $queueBacklog = -1;
        $failedJobs = -1;
        try {
            $queue = (string) config('services.waha.queue', 'whatsapp');
            $queueBacklog = (int) DB::table('jobs')->where('queue', $queue)->count();
            $failedJobs = (int) DB::table('failed_jobs')->count();
        } catch (\Throwable) {
        }

        // Cached snapshot only — never call WAHA from HTTP.
        $waha = Cache::get('waha:health_snapshot', [
            'ok' => null,
            'reason' => 'unknown',
            'checked_at' => null,
        ]);
        $diskFree = @disk_free_space(base_path()) ?: 0;
        $diskTotal = @disk_total_space(base_path()) ?: 0;
        $diskFreePct = $diskTotal > 0 ? round(($diskFree / $diskTotal) * 100, 1) : null;

        return response()->json([
            'ok' => $dbOk && $cacheOk,
            'db_ok' => $dbOk,
            'db_ms' => $dbMs,
            'cache_ok' => $cacheOk,
            'queue_backlog_whatsapp' => $queueBacklog,
            'failed_jobs' => $failedJobs,
            'waha' => [
                'ok' => $waha['ok'] ?? null,
                'reason' => $waha['reason'] ?? 'unknown',
                'checked_at' => $waha['checked_at'] ?? null,
                'breaker' => $waha['breaker'] ?? null,
            ],
            'disk_free_pct' => $diskFreePct,
            'php' => PHP_VERSION,
            'took_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }
}
