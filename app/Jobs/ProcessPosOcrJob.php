<?php

namespace App\Jobs;

use App\Services\Ocr\InvoiceOcrPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessPosOcrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public string $jobKey,
        public string $absolutePath,
    ) {
        $this->onQueue('default');
    }

    public function handle(InvoiceOcrPipeline $pipeline): void
    {
        Cache::put($this->statusKey(), [
            'status' => 'processing',
            'step' => 'reading',
            'message' => 'جاري قراءة الصورة…',
        ], 600);

        $result = $pipeline->run($this->absolutePath);

        Cache::put($this->statusKey(), [
            'status' => ($result['ok'] ?? false) ? 'ready' : 'failed',
            'step' => ($result['ok'] ?? false) ? 'review' : 'failed',
            'message' => ($result['ok'] ?? false) ? 'جاهز للمراجعة' : ($result['error'] ?? 'فشل'),
            'result' => $result,
        ], 600);
    }

    public function failed(?\Throwable $e): void
    {
        Log::channel('ocr')->error('[OCR] pos_job_failed', [
            'job_key' => $this->jobKey,
            'error' => $e?->getMessage(),
        ]);

        Cache::put($this->statusKey(), [
            'status' => 'failed',
            'step' => 'failed',
            'message' => 'فشل طابور القراءة',
            'result' => null,
        ], 600);
    }

    protected function statusKey(): string
    {
        return 'ocr:pos:job:'.$this->jobKey;
    }
}
