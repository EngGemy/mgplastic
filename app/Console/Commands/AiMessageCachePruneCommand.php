<?php

namespace App\Console\Commands;

use App\Models\AiMessageCache;
use Illuminate\Console\Command;

class AiMessageCachePruneCommand extends Command
{
    protected $signature = 'ai:prune-message-cache';

    protected $description = 'Delete expired AI message cache rows';

    public function handle(): int
    {
        $n = AiMessageCache::query()->where('expires_at', '<', now())->delete();
        $this->info("Pruned {$n} AI cache rows");

        return self::SUCCESS;
    }
}