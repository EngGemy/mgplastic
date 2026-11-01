<?php

namespace App\Console\Commands;

use App\Services\Ocr\InvoiceOcrPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class OcrEvaluateCommand extends Command
{
    protected $signature = 'ocr:evaluate {folder : Folder with images + sibling .json expected files}';

    protected $description = 'Run OCR golden-set evaluation (line accuracy, qty accuracy, latency)';

    public function handle(InvoiceOcrPipeline $pipeline): int
    {
        $folder = rtrim((string) $this->argument('folder'), DIRECTORY_SEPARATOR);
        if (! is_dir($folder)) {
            $this->error('Folder not found: '.$folder);

            return self::FAILURE;
        }

        $images = collect(File::files($folder))
            ->filter(fn ($f) => in_array(strtolower($f->getExtension()), ['jpg', 'jpeg', 'png', 'webp'], true))
            ->values();

        if ($images->isEmpty()) {
            $this->warn('No images found.');

            return self::SUCCESS;
        }

        $lineHits = 0;
        $lineTotal = 0;
        $qtyHits = 0;
        $qtyTotal = 0;
        $latencies = [];

        foreach ($images as $image) {
            $expectedPath = preg_replace('/\.(jpe?g|png|webp)$/i', '.json', $image->getPathname());
            $expected = is_file($expectedPath) ? json_decode((string) file_get_contents($expectedPath), true) : null;
            if (! is_array($expected)) {
                $this->warn('Skip (no JSON): '.$image->getFilename());

                continue;
            }

            $result = $pipeline->run($image->getPathname());
            $latencies[] = (int) ($result['elapsed_ms'] ?? 0);

            if (! ($result['ok'] ?? false)) {
                $this->error($image->getFilename().' FAILED: '.($result['error'] ?? ''));

                continue;
            }

            $gotLines = collect($result['draft']['lines'] ?? []);
            $expLines = collect($expected['lines'] ?? []);
            $lineTotal += $expLines->count();

            foreach ($expLines as $exp) {
                $expName = mb_strtolower(trim((string) ($exp['name'] ?? '')));
                $match = $gotLines->first(function ($g) use ($expName) {
                    $n = mb_strtolower(trim((string) ($g['name'] ?? $g['raw_text'] ?? '')));

                    return $n !== '' && ($n === $expName || str_contains($n, $expName) || str_contains($expName, $n));
                });
                if ($match) {
                    $lineHits++;
                    $qtyTotal++;
                    if ((int) round((float) ($match['qty'] ?? 0)) === (int) round((float) ($exp['qty'] ?? 0))) {
                        $qtyHits++;
                    }
                }
            }

            $this->line(sprintf(
                '%s — lines=%d model=%s %dms%s',
                $image->getFilename(),
                $gotLines->count(),
                $result['model'] ?? '-',
                $result['elapsed_ms'] ?? 0,
                ! empty($result['cached']) ? ' cached' : ''
            ));
        }

        $avg = $latencies === [] ? 0 : (int) round(array_sum($latencies) / count($latencies));
        $this->newLine();
        $this->info('Model: '.config('services.gemini.model'));
        $this->info(sprintf('Line accuracy: %d/%d (%.1f%%)', $lineHits, max(1, $lineTotal), 100 * $lineHits / max(1, $lineTotal)));
        $this->info(sprintf('Qty accuracy: %d/%d (%.1f%%)', $qtyHits, max(1, $qtyTotal), 100 * $qtyHits / max(1, $qtyTotal)));
        $this->info('Avg latency: '.$avg.' ms');

        return self::SUCCESS;
    }
}
