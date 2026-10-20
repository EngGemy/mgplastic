<?php

namespace App\Console\Commands;

use App\Services\Reputation\StreakService;
use Illuminate\Console\Command;

class StreakProcessCommand extends Command
{
    protected $signature = 'streak:process-weekly';

    protected $description = 'Update plumber streaks (freeze / reset)';

    public function handle(StreakService $streaks): int
    {
        $streaks->processWeekly();
        $this->info('Streaks processed');

        return self::SUCCESS;
    }
}
