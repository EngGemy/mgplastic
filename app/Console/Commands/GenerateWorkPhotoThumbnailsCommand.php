<?php

namespace App\Console\Commands;

use App\Models\PlumberWorkPhoto;
use App\Services\ImageThumbnailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class GenerateWorkPhotoThumbnailsCommand extends Command
{
    protected $signature = 'work-photos:thumbnails
                            {--force : Regenerate even when thumbnail_path exists}
                            {--optimize : Also re-encode oversized full images (max 1600px)}';

    protected $description = 'Generate thumbnail_path for plumber work images (and optionally optimize originals)';

    public function handle(ImageThumbnailService $images): int
    {
        if (! Schema::hasColumn((new PlumberWorkPhoto)->getTable(), 'thumbnail_path')) {
            $this->error('thumbnail_path column missing — run migrations first.');

            return self::FAILURE;
        }

        $query = PlumberWorkPhoto::query()
            ->whereNotNull('image')
            ->where(function ($q) {
                $q->whereNull('video_path')->orWhere('video_path', '');
            });

        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('thumbnail_path')->orWhere('thumbnail_path', '');
            });
        }

        $bar = $this->output->createProgressBar($query->count());
        $bar->start();

        $done = 0;
        $failed = 0;

        $query->orderBy('id')->chunkById(50, function ($rows) use ($images, $bar, &$done, &$failed) {
            foreach ($rows as $row) {
                /** @var PlumberWorkPhoto $row */
                try {
                    $source = $row->image;
                    if (! $source || ! Storage::disk('public')->exists($source)) {
                        $failed++;
                        $bar->advance();

                        continue;
                    }

                    if ($this->option('optimize')) {
                        $optimized = $images->optimizeExisting($source);
                        if ($optimized && $optimized !== $source) {
                            $row->image = $optimized;
                            $source = $optimized;
                        }
                    }

                    $thumb = $images->makeThumbnail($source, 'work_photos/thumbnails');
                    if ($thumb) {
                        if ($row->thumbnail_path && $row->thumbnail_path !== $thumb) {
                            Storage::disk('public')->delete($row->thumbnail_path);
                        }
                        $row->thumbnail_path = $thumb;
                        $row->save();
                        $done++;
                    } else {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn("id={$row->id}: ".$e->getMessage());
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. thumbs={$done} failed={$failed}");

        return self::SUCCESS;
    }
}
