<?php

namespace App\Listeners;

use App\Models\SystemHealthState;
use Illuminate\Support\Facades\Cache;

class RecordQueueWorkerHeartbeat
{
    public function handle(object $event): void
    {
        $throttle = max(5, (int) config('monitoring.queue_heartbeat_throttle_seconds', 30));
        $cacheKey = (string) config('monitoring.queue_heartbeat_key', 'monitoring:queue_worker_heartbeat');

        // Throttle writes — Looping fires very frequently.
        if (! Cache::add($cacheKey.':throttle', 1, $throttle)) {
            return;
        }

        $at = now()->toIso8601String();

        // Durable DB state (survives optimize:clear / cache:clear).
        SystemHealthState::putString(SystemHealthState::KEY_QUEUE_HEARTBEAT, $at);

        // Keep cache write as a fast path for same-process reads before migrate.
        Cache::put($cacheKey, $at, now()->addHours(24));
    }
}
