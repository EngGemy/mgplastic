<?php

namespace App\Console\Commands;

use App\Models\ProductCategory;
use App\Services\ImageThumbnailService;
use Illuminate\Console\Command;

class GenerateCategoryThumbsCommand extends Command
{
    protected $signature = 'categories:thumbs
                            {--force : Regenerate even when image_thumb exists}
                            {--width=400 : Max edge in pixels}';

    protected $description = 'Generate ~400px WebP/JPEG thumbnails for product category images';

    public function handle(ImageThumbnailService $thumbs): int
    {
        $force = (bool) $this->option('force');
        $width = max(120, (int) $this->option('width'));
        $done = 0;
        $skipped = 0;
        $failed = 0;

        ProductCategory::query()
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->orderBy('id')
            ->chunkById(50, function ($cats) use ($thumbs, $force, $width, &$done, &$skipped, &$failed) {
                foreach ($cats as $cat) {
                    if (! $force && filled($cat->image_thumb)) {
                        $skipped++;

                        continue;
                    }
                    $path = $thumbs->makeCategoryThumb((string) $cat->image, $width);
                    if ($path === null) {
                        $failed++;
                        $this->warn("Failed #{$cat->id}: {$cat->image}");

                        continue;
                    }
                    $cat->forceFill(['image_thumb' => $path])->saveQuietly();
                    $done++;
                }
            });

        $this->info("Generated: {$done}, skipped: {$skipped}, failed: {$failed}");

        return self::SUCCESS;
    }
}
