<?php

namespace App\Console\Commands;

use App\Models\OcrBatch;
use App\Models\OcrImage;
use App\Services\Ocr\OcrStorage;
use Illuminate\Console\Command;

class OcrPruneUploadsCommand extends Command
{
    protected $signature = 'ocr:prune-uploads {--days= : Override retention days} {--dry-run : List only}';

    protected $description = 'Delete OCR upload files for finished batches older than retention';

    public function handle(OcrStorage $ocrStorage): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('ocr_uploads.retention_days', 30)));
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $batchIds = OcrBatch::query()
            ->whereIn('status', ['added', 'abandoned'])
            ->where(function ($q) use ($cutoff) {
                $q->where('updated_at', '<', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNotNull('cart_added_at')->where('cart_added_at', '<', $cutoff);
                    });
            })
            ->pluck('id');

        $images = OcrImage::query()
            ->whereIn('batch_id', $batchIds)
            ->where('created_at', '<', $cutoff)
            ->get();

        $deletedFiles = 0;
        $cleared = 0;

        foreach ($images as $image) {
            $path = (string) $image->path;
            if ($path !== '' && $ocrStorage->exists($path)) {
                if ($dry) {
                    $this->line("[dry] {$path}");
                } else {
                    $ocrStorage->delete($path);
                }
                $deletedFiles++;
            }

            if (! $dry) {
                $image->path = '';
                $image->save();
                $cleared++;
            }
        }

        $this->info(($dry ? '[dry-run] ' : '')."Pruned {$deletedFiles} files / {$cleared} image rows (retention {$days}d)");

        return self::SUCCESS;
    }
}
