<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderService;
use Illuminate\Console\Command;

class RemindersDispatchCommand extends Command
{
    protected $signature = 'reminders:dispatch {kind : weekly|daily}';

    protected $description = 'Dispatch weekly owner or daily plumber WhatsApp reminders';

    public function handle(ReminderService $reminders): int
    {
        $kind = $this->argument('kind');
        if ($kind === 'weekly') {
            $reminders->dispatchWeekly();
            $this->info('Weekly reminders queued');
        } elseif ($kind === 'daily') {
            $reminders->dispatchDailyPlumber();
            $this->info('Daily plumber reminders queued');
        } else {
            $this->error('kind must be weekly or daily');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
