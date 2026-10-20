<?php

namespace App\Console\Commands;

use App\Jobs\Reputation\RecomputeScoreJob;
use App\Models\User;
use App\Services\Warranty\WarrantyService;
use Illuminate\Console\Command;

class ReputationRecomputeCommand extends Command
{
    protected $signature = 'reputation:recompute {--chunk=100}';

    protected $description = 'Recompute plumber scores in chunks + expire warranties';

    public function handle(WarrantyService $warranties): int
    {
        if (! config('reputation.enabled', true)) {
            $this->warn('Reputation disabled');

            return self::SUCCESS;
        }

        $chunk = max(10, (int) $this->option('chunk'));
        User::query()->where('role', 'plumber')->orderBy('id')->chunkById($chunk, function ($users) {
            foreach ($users as $user) {
                RecomputeScoreJob::dispatchSync($user->id, 'nightly');
            }
        });

        $expired = $warranties->expireDue();
        $this->info("Expired warranties: {$expired}");

        return self::SUCCESS;
    }
}
