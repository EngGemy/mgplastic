<?php

namespace App\Console\Commands;

use App\Models\OcrImage;
use App\Services\Ocr\OcrStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class OcrMigrateUploadsPrivateCommand extends Command
{
    protected $signature = 'ocr:migrate-uploads-private {--apply : Actually move files (default is dry-run)}';

    protected $description = 'Move OCR uploads from public disk to private (local) disk';

    public function handle(OcrStorage $ocrStorage): int
    {
        $apply = (bool) $this->option('apply');
        $public = Storage::disk(OcrStorage::DISK_PUBLIC_LEGACY);
        $private = $ocrStorage->privateDisk();

        $moved = 0;
        $skipped = 0;
        $missing = 0;

        OcrImage::query()
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($images) use ($apply, $public, $private, &$moved, &$skipped, &$missing) {
                foreach ($images as $image) {
                    $path = (string) $image->path;
                    $onPrivate = $private->exists($path);
                    $onPublic = $public->exists($path);

                    if ($onPrivate) {
                        if ($onPublic && $apply) {
                            $public->delete($path);
                            $this->line("cleaned public duplicate #{$image->id} {$path}");
                        }
                        $skipped++;

                        continue;
                    }

                    if (! $onPublic) {
                        $missing++;
                        $this->warn("missing #{$image->id} {$path}");

                        continue;
                    }

                    if (! $apply) {
                        $this->line("[dry] would move #{$image->id} {$path}");
                        $moved++;

                        continue;
                    }

                    $bytes = $public->get($path);
                    $private->put($path, $bytes);
                    $public->delete($path);
                    $moved++;
                    $this->info("moved #{$image->id} {$path}");
                }
            });

        $mode = $apply ? 'applied' : 'dry-run';
        $this->info("[{$mode}] moved={$moved} skipped_already_private={$skipped} missing={$missing}");
        if (! $apply) {
            $this->comment('Re-run with --apply to perform the migration.');
        }

        return self::SUCCESS;
    }
}
