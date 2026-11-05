<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ImageThumbnailService;
use Illuminate\Console\Command;

class GenerateProductThumbsCommand extends Command
{
    protected $signature = 'products:thumbs
                            {--force : Regenerate even when image_thumb exists}
                            {--width=320 : Max edge in pixels}';

    protected $description = 'Generate ~320px WebP/JPEG thumbnails for product catalog/main images';

    public function handle(ImageThumbnailService $thumbs): int
    {
        $force = (bool) $this->option('force');
        $width = max(120, (int) $this->option('width'));
        $done = 0;
        $skipped = 0;
        $failed = 0;

        Product::query()
            ->orderBy('id')
            ->chunkById(50, function ($products) use ($thumbs, $force, $width, &$done, &$skipped, &$failed) {
                foreach ($products as $product) {
                    $source = $product->primaryImagePath();
                    if ($source === null) {
                        $skipped++;

                        continue;
                    }

                    if (! $force && filled($product->image_thumb)) {
                        $skipped++;

                        continue;
                    }

                    $path = $thumbs->makeProductThumb($source, $width);
                    if ($path === null) {
                        $failed++;
                        $this->warn("Failed #{$product->id}: {$source}");

                        continue;
                    }

                    $product->forceFill(['image_thumb' => $path])->saveQuietly();
                    $done++;
                }
            });

        $this->info("Generated: {$done}, skipped: {$skipped}, failed: {$failed}");

        return self::SUCCESS;
    }
}
