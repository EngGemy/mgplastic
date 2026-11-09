<?php

namespace App\Jobs;

use App\Models\OcrImage;
use App\Services\Ai\GeminiClient;
use App\Services\Ocr\InvoiceOcrCatalogMatcher;
use App\Services\Ocr\InvoiceOcrPipeline;
use App\Services\Ocr\InvoiceOcrValidator;
use App\Services\Ocr\OcrAttemptPolicy;
use App\Services\Ocr\OcrBatchService;
use App\Services\Ocr\OcrCircuitBreaker;
use App\Services\Ocr\OcrQuotaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessOcrImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // retries via release() — not Laravel attempts

    /** Explicit timeout below cPanel OCR worker (180) and DB_QUEUE_RETRY_AFTER (300). */
    public int $timeout = 150;

    public function __construct(
        public int $ocrImageId,
        public bool $isPasteText = false,
    ) {
        $this->timeout = max(60, (int) config('ocr.job_timeout_seconds', config('ocr_resilience.job_timeout_seconds', 150)));
        $this->onQueue('ocr');
    }

    public function handle(
        InvoiceOcrPipeline $pipeline,
        OcrAttemptPolicy $policy,
        OcrCircuitBreaker $breaker,
        OcrBatchService $batches,
        GeminiClient $gemini,
    ): void {
        $image = OcrImage::query()->with('batch')->find($this->ocrImageId);
        if (! $image || $image->status === 'canceled') {
            return;
        }

        if ($breaker->isOpen()) {
            $image->status = 'retrying';
            $image->last_error_code = config('ocr_resilience.error_codes.busy', 'OCR-BUSY');
            $image->next_retry_at = now()->addSeconds(15);
            $image->save();
            $this->scheduleRetry(15);

            return;
        }

        $parallel = max(1, (int) config('ocr_resilience.max_parallel_per_batch', 2));
        $active = OcrImage::query()
            ->where('batch_id', $image->batch_id)
            ->where('id', '!=', $image->id)
            ->whereIn('status', ['reading'])
            ->count();
        if ($active >= $parallel) {
            $this->scheduleRetry(2);

            return;
        }

        $budget = max(30, (int) config('ocr_resilience.retry_budget_seconds', 90));
        if ($image->started_at && $image->started_at->diffInSeconds(now()) >= $budget) {
            $this->failImage($image, config('ocr_resilience.error_codes.timeout', 'OCR-TIMEOUT'));
            $this->kickNext($batches, $image);

            return;
        }

        if (! $image->started_at) {
            $image->started_at = now();
        }

        $attemptIndex = (int) $image->attempts; // 0-based before increment
        $maxAttempts = $policy->maxAttempts();
        if ($attemptIndex >= $maxAttempts) {
            $this->failImage($image, $image->last_error_code ?: config('ocr_resilience.error_codes.failed', 'OCR-FAILED'));
            $this->kickNext($batches, $image);

            return;
        }

        $image->attempts = $attemptIndex + 1;
        $image->status = 'reading';
        $image->next_retry_at = null;
        $image->save();

        $fallbackIndex = (int) config('ocr_resilience.fallback_attempt_index', 2);
        $useFallback = $attemptIndex >= $fallbackIndex || (bool) Cache::get($this->forceFallbackKey($image->id));
        $model = $useFallback
            ? $gemini->resolveOcrFallbackModel($gemini->model())
            : $gemini->model();

        $absolute = app(\App\Services\Ocr\OcrStorage::class)->absolutePath($image->path);
        $started = microtime(true);
        $fromStored = false;

        // Idempotent Gemini: never call the model twice for the same stored result.
        if (is_array($image->result_json) && ($image->result_json['ok'] ?? false) && is_array($image->result_json['draft'] ?? null)) {
            $result = $image->result_json;
            $fromStored = true;
        } elseif ($this->isPasteText || str_ends_with(strtolower($image->path), '.txt')) {
            $result = $this->runPasteText($absolute, $batches, $image);
        } else {
            $capacity = app(OcrQuotaService::class)->assertGlobalGeminiCapacity();
            if (! $capacity['ok']) {
                $this->failImage($image, 'OCR-BUSY');
                $this->kickNext($batches, $image);

                return;
            }
            // Prefer SHA cache on retries too — skipCache only when no prior result.
            $result = $pipeline->run($absolute, $model, skipCache: false);
        }

        $elapsed = (int) round((microtime(true) - $started) * 1000);
        $httpStatus = $result['http_status'] ?? null;

        Log::channel('ocr')->info('[OCR] attempt', [
            'image_id' => $image->id,
            'attempt' => $image->attempts,
            'model' => $result['model'] ?? $model,
            'http_status' => $httpStatus,
            'duration_ms' => $elapsed,
            'from_stored' => $fromStored,
            'error_code' => ($result['ok'] ?? false) ? null : ($result['code'] ?? 'failed'),
        ]);

        if (! $fromStored && ! ($this->isPasteText || str_ends_with(strtolower($image->path), '.txt'))) {
            app(OcrQuotaService::class)->recordGemini(
                user: $image->batch?->user,
                batchId: $image->batch_id,
                imageId: $image->id,
                model: $result['model'] ?? $model,
                success: (bool) ($result['ok'] ?? false),
                durationMs: $elapsed,
                inputTokens: isset($result['input_tokens']) ? (int) $result['input_tokens'] : null,
                outputTokens: isset($result['output_tokens']) ? (int) $result['output_tokens'] : null,
            );
        }

        if ($result['ok'] ?? false) {
            $draft = $result['draft'] ?? ['lines' => []];
            $lineCount = count($draft['lines'] ?? []);
            $batches->persistDraftLines($image, $draft, keepApproved: true);
            $image->model_used = $result['model'] ?? $model;
            $image->width = $result['width'] ?? $image->width;
            $image->height = $result['height'] ?? $image->height;
            $image->result_json = [
                'ok' => true,
                'draft' => $draft,
                'model' => $result['model'] ?? $model,
                'width' => $image->width,
                'height' => $image->height,
            ];
            $image->status = $lineCount === 0 ? 'empty' : 'done';
            $image->last_error_code = null;
            $image->finished_at = now();
            $image->save();
            $breaker->recordSuccess();
            $image->batch?->refreshStatusFromImages();
            $image->batch?->touchActivity();
            $this->kickNext($batches, $image);

            return;
        }

        $decision = $policy->classify($result);
        $image->last_error_code = $decision['stable_code'];
        $image->model_used = $result['model'] ?? $model;
        $image->save();

        if ($decision['notify_auth']) {
            $this->notifyAdminOncePerDay('ocr_auth', 'OCR-AUTH: Gemini key rejected');
            $breaker->recordFailure();
            $this->failImage($image, $decision['stable_code']);
            $this->kickNext($batches, $image);

            return;
        }

        if ($decision['notify_model']) {
            $this->notifyAdminOncePerDay('ocr_model_404', 'OCR model 404 — switching to fallback');
            Cache::put($this->forceFallbackKey($image->id), 1, 600);
        }

        if ($decision['switch_fallback']) {
            Cache::put($this->forceFallbackKey($image->id), 1, 600);
        }

        $jsonOnceAlready = $decision['json_once']
            && Cache::get($this->jsonRetriedKey($image->id));

        if ($decision['json_once']) {
            Cache::put($this->jsonRetriedKey($image->id), 1, 600);
        }

        $budgetExhausted = $image->started_at && $image->started_at->diffInSeconds(now()) >= $budget;
        $canRetry = $decision['retryable']
            && ! $jsonOnceAlready
            && ! $budgetExhausted
            && $image->attempts < $maxAttempts;

        if (! $canRetry) {
            // Single failed attempt must NOT open the breaker — only consecutive across images.
            $breaker->recordFailure();
            $this->failImage($image, $decision['stable_code']);
            $this->kickNext($batches, $image);

            return;
        }

        $delay = $policy->delaySeconds($image->attempts, $result['retry_after'] ?? null);
        $image->status = 'retrying';
        $image->next_retry_at = now()->addSeconds($delay);
        $image->save();
        $image->batch?->refreshStatusFromImages();

        // Delayed retry without sleep() — cPanel cron worker safe (database queue).
        $this->scheduleRetry($delay);
    }

    /**
     * Prefer release($delay) on real queues; SyncQueue ignores release(), so re-dispatch.
     */
    protected function scheduleRetry(int $delay): void
    {
        if (config('queue.default') === 'sync') {
            self::dispatch($this->ocrImageId, $this->isPasteText);

            return;
        }

        $this->release(max(0, $delay));
    }

    /**
     * @return array{ok:bool, draft:?array, error:?string, code:?string, model:?string, elapsed_ms:int, http_status:?int, retry_after:?int, width:?int, height:?int}
     */
    protected function runPasteText(string $absolute, OcrBatchService $batches, OcrImage $image): array
    {
        $text = @file_get_contents($absolute);
        if (! is_string($text) || trim($text) === '') {
            return [
                'ok' => false,
                'draft' => null,
                'error' => 'نص فارغ',
                'code' => 'empty_text',
                'model' => 'paste',
                'elapsed_ms' => 0,
                'http_status' => null,
                'retry_after' => null,
                'width' => null,
                'height' => null,
            ];
        }

        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $row) {
            $row = trim($row);
            if ($row === '') {
                continue;
            }
            // Prefer "name … qty" trailing number when present.
            $qty = 1.0;
            $name = $row;
            if (preg_match('/^(.*\S)\s+([0-9٠-٩]+(?:[.,][0-9]+)?)\s*$/u', $row, $m)) {
                $name = trim($m[1]);
                $qty = (float) strtr($m[2], [
                    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                    '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                    ',' => '.',
                ]);
            }
            $lines[] = ['raw_text' => $row, 'name' => $name, 'qty' => $qty, 'confidence' => 0.75];
        }

        $draft = [
            'lines' => $lines,
            'source_type' => 'handwritten',
            'raw_line_count' => count($lines),
            'returned_line_count' => count($lines),
        ];

        $matcher = app(InvoiceOcrCatalogMatcher::class);
        $validator = app(InvoiceOcrValidator::class);
        $draft = $matcher->enrich($draft, null, $image->batch?->distributor_id);
        $draft = $validator->annotate($draft);

        return [
            'ok' => true,
            'draft' => $draft,
            'error' => null,
            'code' => null,
            'model' => 'paste',
            'elapsed_ms' => 0,
            'http_status' => 200,
            'retry_after' => null,
            'width' => null,
            'height' => null,
        ];
    }

    protected function failImage(OcrImage $image, string $code): void
    {
        $image->status = 'failed';
        $image->last_error_code = $code;
        $image->finished_at = now();
        $image->next_retry_at = null;
        $image->save();
        $image->batch?->refreshStatusFromImages();
    }

    protected function kickNext(OcrBatchService $batches, OcrImage $image): void
    {
        $next = OcrImage::query()
            ->where('batch_id', $image->batch_id)
            ->where('status', 'queued')
            ->orderBy('position')
            ->first();
        if ($next) {
            $batches->dispatchIfCapacity($next);
        }
    }

    protected function forceFallbackKey(int $imageId): string
    {
        return 'ocr:force_fallback:'.$imageId;
    }

    protected function jsonRetriedKey(int $imageId): string
    {
        return 'ocr:json_retried:'.$imageId;
    }

    protected function notifyAdminOncePerDay(string $key, string $message): void
    {
        $cacheKey = 'ocr:admin_notify:'.$key.':'.now()->toDateString();
        if (! Cache::add($cacheKey, 1, now()->endOfDay())) {
            return;
        }
        Log::channel('ocr')->error('[OCR] admin_notify', [
            'key' => $key,
            'message' => $message,
        ]);
        // Filament database notification omitted if no admin channel — log is enough for cPanel.
    }
}
