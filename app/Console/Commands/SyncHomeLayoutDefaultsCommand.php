<?php

namespace App\Console\Commands;

use App\Services\HomeMobileLayoutService;
use Illuminate\Console\Command;

class SyncHomeLayoutDefaultsCommand extends Command
{
    protected $signature = 'home-layout:sync-defaults {--force : Wipe existing sections and reseed from samples}';

    protected $description = 'Seed / reset home layout sections to match docs/home-layout/sample-*.json';

    public function handle(HomeMobileLayoutService $layout): int
    {
        $layout->ensureSeeded();
        $count = $layout->syncDefaultSections((bool) $this->option('force'));

        $this->info($this->option('force')
            ? "تمت إعادة زرع {$count} قسماً من العينات الافتراضية."
            : "الأقسام موجودة مسبقاً ({$count}). استخدم --force لإعادة الزرع.");

        return self::SUCCESS;
    }
}
