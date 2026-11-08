<?php

namespace App\Services\Ocr;

use App\Models\OcrBatch;
use App\Models\OcrImage;
use App\Models\Product;
use App\Models\User;
use App\Services\InvoiceCartSheetService;
use App\Services\NetworkInventoryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class MobileOcrPresenter
{
    public const PREFS_TTL_SECONDS = 86400;

    public function __construct(
        protected OcrBatchService $batches,
        protected NetworkInventoryService $inventory,
        protected InvoiceCartSheetService $cartSheet,
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

        // Keep batch.distributor_id in sync when the seller picks/changes the customer.
        if (array_key_exists('customer_user_id', $prefs)) {
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
    public function present(OcrBatch $batch, ?User $viewer = null): array
    {
        $batch->refresh();
        $batch->load(['images' => fn ($q) => $q->whereNull('replaced_by_id')->orderBy('position')]);

        $viewer ??= $batch->user;
        $stockByProduct = $this->stockQtyByProduct($viewer);

        $prefs = $this->prefs($batch);
        $built = $this->batches->buildPosDraft($batch);
        $draft = is_array($built['draft'] ?? null) ? $built['draft'] : null;

        if (is_array($draft)) {
            $draft['system_default'] = $prefs['system_default'];
            $draft['customer_user_id'] = $prefs['customer_user_id'];
            $draft['lines'] = $this->decorateLinesWithStock((array) ($draft['lines'] ?? []), $stockByProduct);
            $draft['excluded'] = array_values((array) ($draft['excluded'] ?? []));
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
        $cartLines = $step === 'added'
            ? $this->rebuildCartLines($batch, $stockByProduct)
            : null;

        return [
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'step' => $step,
            'settled' => $settled,
            'reading' => $pending,
            'cart_added_at' => optional($batch->cart_added_at)?->toIso8601String(),
            'idempotency_token' => $batch->idempotency_token,
            'prefs' => $prefs,
            'poll' => [
                'interval_ms' => (int) config('ocr_resilience.poll_interval_ms', 2000),
                'backoff_after_ms' => (int) config('ocr_resilience.poll_backoff_after_ms', 20000),
                'backoff_interval_ms' => (int) config('ocr_resilience.poll_backoff_interval_ms', 5000),
            ],
            'limits' => [
                'max_images' => (int) config('ocr_resilience.max_images_per_batch', 10),
            ],
            'lexicon_families' => $this->lexiconFamiliesCatalog(),
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
            'cart_lines' => $cartLines,
            'gemini_enabled' => app(\App\Services\Ai\GeminiClient::class)->enabled(),
        ];
    }

    /**
     * Build cart-ready lines (same shape as apply) and attach available_qty.
     *
     * @param  array<int, int>  $stockByProduct
     * @param  list<array<string, mixed>>  $draftLines  optional pre-approved lines (apply path)
     * @return list<array<string, mixed>>
     */
    public function buildCartLinesFromApproved(array $draftLines, array $stockByProduct = []): array
    {
        $lines = [];
        foreach ($draftLines as $line) {
            $approved = ! empty($line['approved'])
                || in_array(($line['review_status'] ?? ''), ['approved', 'added'], true);
            if (! $approved) {
                continue;
            }

            $productId = (int) ($line['matched_product_id'] ?? $line['resolved_product_id'] ?? 0);
            $qty = (int) round((float) ($line['qty'] ?? 0));
            if ($productId < 1 || $qty < 1) {
                continue;
            }

            $product = Product::query()->with('translations')->find($productId);
            if (! $product) {
                continue;
            }

            $cartLine = $this->decorateCartLine($this->cartSheet->cartLine($product, $qty), $stockByProduct);
            $id = (string) $productId;
            if (isset($lines[$id])) {
                if (! empty($cartLine['boxes'])) {
                    $lines[$id]['boxes'] = (int) $lines[$id]['boxes'] + (int) $cartLine['boxes'];
                    $lines[$id]['quantity'] = (int) $lines[$id]['boxes'] * (int) $lines[$id]['pieces_per_box'];
                } else {
                    $lines[$id]['quantity'] = (int) $lines[$id]['quantity'] + (int) $cartLine['quantity'];
                }
                $lines[$id] = $this->decorateCartLine($lines[$id], $stockByProduct);
            } else {
                $lines[$id] = $cartLine;
            }
        }

        return array_values($lines);
    }

    /**
     * @param  array<int, int>  $stockByProduct
     * @return list<array<string, mixed>>
     */
    public function rebuildCartLines(OcrBatch $batch, array $stockByProduct = []): array
    {
        $built = $this->batches->buildPosDraft($batch);
        $draftLines = (array) ($built['draft']['lines'] ?? []);

        // Prefer lines already marked added; fall back to approved if status drifted.
        $added = array_values(array_filter(
            $draftLines,
            fn ($l) => is_array($l) && (($l['review_status'] ?? '') === 'added' || ! empty($l['added']))
        ));
        if ($added === []) {
            $added = array_values(array_filter(
                $draftLines,
                fn ($l) => is_array($l) && (($l['review_status'] ?? '') === 'approved' || ! empty($l['approved']))
            ));
        }

        return $this->buildCartLinesFromApproved($added, $stockByProduct);
    }

    /**
     * @return array<int, int> product_id => available_qty (pieces)
     */
    public function stockQtyByProduct(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $stock = match (true) {
            $user->isWholesaleDistributor() => $this->inventory->stockForWholesaler($user),
            $user->isRetailTrader() => $this->inventory->stockForRetailTrader($user),
            default => collect(),
        };

        $map = [];
        foreach ($stock as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            if ($pid < 1) {
                continue;
            }
            $map[$pid] = (int) ($row['available_qty'] ?? 0);
        }

        return $map;
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

        $minutes = max(1, (int) config('ocr_uploads.signed_preview_minutes', 30));
        $expires = now()->addMinutes($minutes);
        $signed = null;
        try {
            $signed = URL::temporarySignedRoute(
                'ocr.images.preview',
                $expires,
                ['image' => $img->id],
            );
        } catch (\Throwable) {
            $signed = null;
        }

        return [
            'id' => $img->id,
            'position' => $img->position,
            'status' => $img->status,
            'lines_count' => $img->lines_count,
            'error_code' => $img->last_error_code,
            // Legacy public URL (storage symlink). Prefer signed for app display.
            'preview_url' => $img->path !== '' ? Storage::disk('public')->url($img->path) : null,
            'preview_signed_url' => $signed,
            'preview_signed_expires_at' => $signed ? $expires->toIso8601String() : null,
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
            if (! empty($line['approved']) || in_array(($line['review_status'] ?? ''), ['approved', 'added'], true)) {
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

    /**
     * @return list<array{key:string,label:string}>
     */
    public function lexiconFamiliesCatalog(): array
    {
        $out = [];
        foreach ((array) config('ocr_lexicon.families', []) as $key => $meta) {
            $out[] = [
                'key' => (string) $key,
                'label' => (string) ($meta['catalog_name'] ?? $key),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, int>  $stockByProduct
     * @return list<array<string, mixed>>
     */
    protected function decorateLinesWithStock(array $lines, array $stockByProduct): array
    {
        return array_map(function (array $line) use ($stockByProduct) {
            $productId = (int) ($line['matched_product_id'] ?? 0);
            $available = $productId > 0 ? (int) ($stockByProduct[$productId] ?? 0) : 0;
            $ppb = (int) ($line['pieces_per_box'] ?? 0);
            $qty = (float) ($line['qty'] ?? 0);
            $requestedPieces = $ppb > 0 ? (int) round($qty * $ppb) : (int) round($qty);

            $line['available_qty'] = $available;
            $line['in_stock'] = $productId > 0 && $available > 0;
            $line['qty_exceeds_stock'] = $productId > 0 && $requestedPieces > $available;

            if (! empty($line['candidates']) && is_array($line['candidates'])) {
                $line['candidates'] = array_map(function ($c) use ($stockByProduct) {
                    if (! is_array($c)) {
                        return $c;
                    }
                    $pid = (int) ($c['product_id'] ?? 0);
                    $c['available_qty'] = $pid > 0 ? (int) ($stockByProduct[$pid] ?? 0) : 0;
                    $c['in_stock'] = $pid > 0 && $c['available_qty'] > 0;

                    return $c;
                }, $line['candidates']);
            }

            return $line;
        }, $lines);
    }

    /**
     * @param  array<string, mixed>  $cartLine
     * @param  array<int, int>  $stockByProduct
     * @return array<string, mixed>
     */
    protected function decorateCartLine(array $cartLine, array $stockByProduct): array
    {
        $pid = (int) ($cartLine['product_id'] ?? 0);
        $available = $pid > 0 ? (int) ($stockByProduct[$pid] ?? 0) : 0;
        $cartLine['available_qty'] = $available;
        $cartLine['in_stock'] = $pid > 0 && $available > 0;
        $cartLine['qty_exceeds_stock'] = $pid > 0 && (int) ($cartLine['quantity'] ?? 0) > $available;

        return $cartLine;
    }

    protected function prefsKey(int $batchId): string
    {
        return 'ocr:mobile:prefs:'.$batchId;
    }
}
