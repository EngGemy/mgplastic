<?php

namespace App\Console\Commands;

use App\Services\ForceLogoutService;
use Illuminate\Console\Command;

class PurgeOrphanAuthCommand extends Command
{
    protected $signature = 'auth:purge-orphans';

    protected $description = 'Delete Sanctum tokens and biometric devices for users that no longer exist';

    public function handle(ForceLogoutService $logout): int
    {
        $result = $logout->purgeOrphans();

        $this->info(sprintf(
            'Purged orphan auth: tokens=%d biometric=%d',
            $result['tokens'],
            $result['biometric'],
        ));

        return self::SUCCESS;
    }
}
