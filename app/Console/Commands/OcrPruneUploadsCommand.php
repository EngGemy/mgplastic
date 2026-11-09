<?php

namespace App\Console\Commands;

use App\Models\OcrBatch;
use App\Models\OcrImage;
use App\Services\Ocr\OcrStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OcrPruneUploadsCommand extends Command
{
    protected $signature = 'ocr:prune-uploads {--days= : Override unapplied retention days} {--dry-run : List only}';

    protected $description = 'Delete OCR upload files by outcome retention (linked 90d / unapplied 7d)';

    public function handle(OcrStorage $ocrStorage): int
    {
        $linkedDays = max(1, (int) config('ocr.retention_linked_days', 90));
        $unappliedDays = max(1, (int) ($this->option('days') ?: config('ocr.retention_unapplied_days', config('ocr_uploads.retention_days', 7))));
        $dry = (bool) $this->option('dry-run');

        $linkedIds = collect();
        if (DB::getSchemaBuilder()->hasColumn('orders', 'ocr_batch_id')) {
            $linkedIds = $linkedIds->merge(
                DB::table('orders')->whereNotNull('ocr_batch_id')->pluck('ocr_batch_id')
            );
        }
        if (DB::getSchemaBuilder()->hasColumn('invoices', 'ocr_batch_id')) {
            $linkedIds = $linkedIds->merge(
                DB::table('invoices')->whereNotNull('ocr_batch_id')->pluck('ocr_batch_id')
            );
        }
        $linkedIds = $linkedIds->unique()->filter()->values();

        $unappliedBatchIds = OcrBatch::query()
            ->whereIn('status', ['added', 'abandoned', 'draft', 'review'])
            ->when($linkedIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $linkedIds))
            ->where(function ($q) use ($unappliedDays) {
                $cutoff = now()->subDays($unappliedDays);
                $q->where('updated_at', '<', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNotNull('cart_added_at')->where('cart_added_at', '<', $cutoff);
                    });
            })
            ->pluck('id');

        $linkedBatchIds = OcrBatch::query()
            ->whereIn('id', $linkedIds)
            ->where('updated_at', '<', now()->subDays($linkedDays))
            ->pluck('id');

        $batchIds = $unappliedBatchIds->merge($linkedBatchIds)->unique()->values();

        $images = OcrImage::query()
            ->whereIn('batch_id', $batchIds)
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

        $this->info(($dry ? '[dry-run] ' : '')."Pruned {$deletedFiles} files / {$cleared} image rows (unapplied {$unappliedDays}d / linked {$linkedDays}d)");

        return self::SUCCESS;
    }
}
