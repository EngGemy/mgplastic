<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Cache;

class RecordQueueWorkerHeartbeat
{
    public function handle(object $event): void
    {
        $key = (string) config('monitoring.queue_heartbeat_key', 'monitoring:queue_worker_heartbeat');
        $throttle = max(5, (int) config('monitoring.queue_heartbeat_throttle_seconds', 30));

        // Throttle writes — Looping fires very frequently.
        if (! Cache::add($key.':throttle', 1, $throttle)) {
            return;
        }

        Cache::put($key, now()->toIso8601String(), now()->addHours(24));
    }
}
