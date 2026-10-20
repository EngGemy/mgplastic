<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneWhatsAppCardsCommand extends Command
{
    protected $signature = 'whatsapp:prune-cards {--days=7 : Delete card JPEGs older than N days}';

    protected $description = 'Delete old generated WhatsApp brand cards from public storage';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days)->getTimestamp();
        $disk = Storage::disk('public');
        $deleted = 0;

        foreach ($disk->files('whatsapp-cards') as $file) {
            if (! str_ends_with(strtolower($file), '.jpg') && ! str_ends_with(strtolower($file), '.jpeg')) {
                continue;
            }
            try {
                if ($disk->lastModified($file) < $cutoff) {
                    $disk->delete($file);
                    $deleted++;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        $this->info("Deleted {$deleted} card files older than {$days} days");

        return self::SUCCESS;
    }
}
