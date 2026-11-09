<?php

namespace App\Console\Commands;

use App\Models\OcrBatch;
use App\Models\OcrImage;
use Illuminate\Console\Command;

class OcrMaintainCommand extends Command
{
    protected $signature = 'ocr:maintain {--dry-run : Report only}';

    protected $description = 'Fail stuck OCR readings and auto-abandon stale unapplied batches';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $stuckMinutes = max(1, (int) config('ocr.reading_stuck_minutes', 5));
        $abandonDays = max(1, (int) config('ocr.auto_abandon_days', 7));

        $stuck = OcrImage::query()
            ->where('status', 'reading')
            ->where(function ($q) use ($stuckMinutes) {
                $q->where('started_at', '<', now()->subMinutes($stuckMinutes))
                    ->orWhere(function ($q2) use ($stuckMinutes) {
                        $q2->whereNull('started_at')
                            ->where('updated_at', '<', now()->subMinutes($stuckMinutes));
                    });
            })
            ->get();

        foreach ($stuck as $image) {
            $this->line(($dry ? '[dry] ' : '')."fail image #{$image->id} reading timeout");
            if (! $dry) {
                $image->status = 'failed';
                $image->last_error_code = 'OCR-TIMEOUT';
                $image->finished_at = now();
                $image->save();
                $image->batch?->refreshStatusFromImages();
            }
        }

        $stale = OcrBatch::query()
            ->whereIn('status', ['draft', 'reading', 'review'])
            ->where(function ($q) use ($abandonDays) {
                $q->where('last_activity_at', '<', now()->subDays($abandonDays))
                    ->orWhere(function ($q2) use ($abandonDays) {
                        $q2->whereNull('last_activity_at')
                            ->where('updated_at', '<', now()->subDays($abandonDays));
                    });
            })
            ->get();

        foreach ($stale as $batch) {
            $this->line(($dry ? '[dry] ' : '')."abandon batch #{$batch->id}");
            if (! $dry) {
                $batch->status = 'abandoned';
                $batch->save();
            }
        }

        $this->info(($dry ? '[dry-run] ' : '')."stuck={$stuck->count()} abandoned={$stale->count()}");

        return self::SUCCESS;
    }
}
