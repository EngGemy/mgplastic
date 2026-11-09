<?php

namespace App\Listeners;

use App\Models\SystemHealthState;
use Illuminate\Console\Events\CommandStarting;

/**
 * Record queue:restart time in DB so health checks apply a short grace window
 * while workers recycle (avoids false CRITICAL "no heartbeat" after deploy).
 */
class NoteQueueRestart
{
    public function handle(CommandStarting $event): void
    {
        if ($event->command !== 'queue:restart') {
            return;
        }

        SystemHealthState::putString(
            SystemHealthState::KEY_QUEUE_RESTARTED_AT,
            now()->toIso8601String(),
        );
    }
}
