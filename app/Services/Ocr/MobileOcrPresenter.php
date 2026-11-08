<?php

namespace App\Services\Ocr;

use App\Models\OcrBatch;
use App\Models\OcrImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class MobileOcrPresenter
{
    public const PREFS_TTL_SECONDS = 86400;

    public function __construct(
        protected OcrBatchService $batches,
    ) {}

    /**
     * @return array{system_default:?string, customer_user_id:?int, remember_for_customer:bool}
     */
    public function prefs(OcrBatch $batch): array
    {
        $cached = Cache::get($this->prefsKey($batch->id), []);

        return [
            'system_default' => in_array($cached['system_default'] ?? null, ['us', 'eu'], true)
                ? $cached['system_default']
                : null,
            'customer_user_id' => isset($cached['customer_user_id'])
                ? (int) $cached['customer_user_id']
                : ($batch->distributor_id ? (int) $batch->distributor_id : null),
            'remember_for_customer' => (bool) ($cached['remember_for_customer'] ?? false),
        ];
    }

    /**
     * @param  array{system_default?:?string, customer_user_id?:?int, remember_for_customer?:bool}  $prefs
     */
    public function putPrefs(OcrBatch $batch, array $prefs): array
    {
        $current = $this->prefs($batch);
        if (array_key_exists('system_default', $prefs)) {
            $value = $prefs['system_default'];
            $current['system_default'] = in_array($value, ['us', 'eu'], true) ? $value : null;
        }
        if (array_key_exists('customer_user_id', $prefs)) {
            $current['customer_user_id'] = $prefs['customer_user_id'] !== null
                ? (int) $prefs['customer_user_id']
                : null;
        }
        if (array_key_exists('remember_for_customer', $prefs)) {
            $current['remember_for_customer'] = (bool) $prefs['remember_for_customer'];
        }

        Cache::put($this->prefsKey($batch->id), $current, self::PREFS_TTL_SECONDS);

        if ($current['customer_user_id'] && ! $batch->distributor_id) {
            $batch->distributor_id = $current['customer_user_id'];
            $batch->save();
        }

        return $current;
    }

    public function clearPrefs(int $batchId): void
    {
        Cache::forget($this->prefsKey($batchId));
    }

    /**
     * Full mobile payload for a batch (poll / show).
     *
     * @return array<string, mixed>
     */
    public function present(OcrBatch $batch): array
    {
        $batch->refresh();
        $batch->load(['images' => fn ($q) => $q->whereNull('replaced_by_id')->orderBy('position')]);

        $prefs = $this->prefs($batch);
        $built = $this->batches->buildPosDraft($batch);
        $draft = is_array($built['draft'] ?? null) ? $built['draft'] : null;

        if (is_array($draft)) {
            $draft['system_default'] = $prefs['system_default'];
            $draft['customer_user_id'] = $prefs['customer_user_id'];
        }

        $hasImages = $batch->images->isNotEmpty();
        $pending = $batch->images->contains(
            fn (OcrImage $i) => in_array($i->status, ['queued', 'reading', 'retrying'], true)
        );
        // Empty batch is not "settled review" — wait for first upload.
        $settled = $hasImages && $batch->isSettled();

        $step = match (true) {
            $batch->status === 'added' => 'added',
            $batch->status === 'abandoned' => 'abandoned',
            $pending => 'polling',
            $hasImages && is_array($draft) => 'review',
            $settled => 'review',
            default => 'draft',
        };

        $stats = $this->statsFromDraft($draft);

        return [
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'step' => $step,
            'settled' => $settled,
            'reading' => $pending,
            'cart_added_at' => optional($batch->cart_added_at)?->toIso8601String(),
            'prefs' => $prefs,
            'poll' => [
                'interval_ms' => (int) config('ocr_resilience.poll_interval_ms', 2000),
                'backoff_after_ms' => (int) config('ocr_resilience.poll_backoff_after_ms', 20000),
                'backoff_interval_ms' => (int) config('ocr_resilience.poll_backoff_interval_ms', 5000),
            ],
            'limits' => [
                'max_images' => (int) config('ocr_resilience.max_images_per_batch', 10),
            ],
            'images' => $batch->images->map(fn (OcrImage $img) => $this->presentImage($img))->values()->all(),
            'draft' => $draft,
            'merge_suggestions' => $built['merge_suggestions'] ?? [],
            'accounting' => $built['accounting'] ?? [
                'matched' => 0,
                'needs_review' => 0,
                'excluded' => 0,
                'lines_read' => 0,
            ],
            'stats' => $stats,
            'gemini_enabled' => app(\App\Services\Ai\GeminiClient::class)->enabled(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentImage(OcrImage $img): array
    {
        $countdown = null;
        if ($img->status === 'retrying' && $img->next_retry_at) {
            $countdown = $img->next_retry_at->isFuture()
                ? (int) now()->diffInSeconds($img->next_retry_at)
                : 0;
        }

        return [
            'id' => $img->id,
            'position' => $img->position,
            'status' => $img->status,
            'lines_count' => $img->lines_count,
            'error_code' => $img->last_error_code,
            'preview_url' => Storage::disk('public')->url($img->path),
            'countdown_seconds' => $countdown,
            'quality_warning' => (bool) $img->quality_warning,
            'attempts' => $img->attempts,
            'read_version' => $img->read_version,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $draft
     * @return array{pending:int, ready:int, approved:int, excluded:int}
     */
    public function statsFromDraft(?array $draft): array
    {
        $pending = $ready = $approved = 0;
        foreach ((array) ($draft['lines'] ?? []) as $line) {
            if (! empty($line['approved']) || ($line['review_status'] ?? '') === 'approved') {
                $approved++;

                continue;
            }
            $flags = (array) ($line['needs_review'] ?? []);
            $decision = (string) ($line['match_decision'] ?? '');
            if ($flags === [] && ! empty($line['matched_product_id']) && in_array($decision, ['auto', 'alias', 'manual'], true)) {
                $ready++;
            } else {
                $pending++;
            }
        }

        return [
            'pending' => $pending,
            'ready' => $ready,
            'approved' => $approved,
            'excluded' => count((array) ($draft['excluded'] ?? [])),
        ];
    }

    protected function prefsKey(int $batchId): string
    {
        return 'ocr:mobile:prefs:'.$batchId;
    }
}
