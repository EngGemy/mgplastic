<?php

namespace App\Console\Commands;

use App\Services\Ocr\InvoiceOcrPipeline;
use Illuminate\Console\Command;

/**
 * Alias + golden-set runner (ocr:eval). Keeps ocr:evaluate for BC.
 */
class OcrEvalCommand extends Command
{
    protected $signature = 'ocr:eval {folder? : Folder with images + sibling .json expected files}';

    protected $description = 'Evaluate OCR golden-set accuracy (product/qty/latency)';

    public function handle(InvoiceOcrPipeline $pipeline): int
    {
        $folder = $this->argument('folder')
            ?: base_path('tests/Fixtures/ocr-golden');

        if (! is_dir($folder)) {
            $this->warn('Golden folder missing: '.$folder.' — create tests/Fixtures/ocr-golden');

            return self::SUCCESS;
        }

        return $this->call('ocr:evaluate', ['folder' => $folder]);
    }
}
