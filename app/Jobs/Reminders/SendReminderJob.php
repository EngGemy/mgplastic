<?php

namespace App\Jobs\Reminders;

use App\Models\User;
use App\Services\Reminders\ReminderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public int $userId,
        public string $kind,
    ) {
        $this->onQueue((string) config('services.waha.queue', 'whatsapp'));
    }

    public function handle(ReminderService $reminders): void
    {
        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }
        try {
            $reminders->sendFor($user, $this->kind);
        } catch (\Throwable) {
            // never break
        }
    }
}
