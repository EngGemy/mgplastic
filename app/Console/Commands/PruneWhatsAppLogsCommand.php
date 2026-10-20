<?php

namespace App\Console\Commands;

use App\Models\WhatsAppLog;
use Illuminate\Console\Command;

class PruneWhatsAppLogsCommand extends Command
{
    protected $signature = 'whatsapp:prune-logs {--days=60 : Delete logs older than N days}';

    protected $description = 'Delete old whatsapp_logs rows';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = WhatsAppLog::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
        $this->info("Deleted {$deleted} whatsapp_logs older than {$days} days");

        return self::SUCCESS;
    }
}
