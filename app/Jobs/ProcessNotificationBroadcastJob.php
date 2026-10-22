<?php

namespace App\Jobs;

use App\Models\NotificationBroadcast;
use App\Services\AdminNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessNotificationBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $broadcastId) {}

    public function handle(): void
    {
        $broadcast = NotificationBroadcast::query()->find($this->broadcastId);
        if (! $broadcast || $broadcast->sent_at) {
            return;
        }

        AdminNotificationService::executeBroadcast($broadcast);
    }
}
