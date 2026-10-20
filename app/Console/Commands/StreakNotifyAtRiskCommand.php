<?php

namespace App\Console\Commands;

use App\Services\Reputation\StreakService;
use Illuminate\Console\Command;

class StreakNotifyAtRiskCommand extends Command
{
    protected $signature = 'streak:notify-at-risk';

    protected $description = 'Notify plumbers whose streak is at risk this week';

    public function handle(StreakService $streaks): int
    {
        $streaks->notifyAtRisk();
        $this->info('At-risk streak notifications dispatched');

        return self::SUCCESS;
    }
}
