<?php

namespace App\Services\Ocr;

use App\Models\OcrBatch;
use App\Models\OcrImage;
use App\Models\Product;
use App\Models\User;
use App\Services\InvoiceCartSheetService;
use App\Services\NetworkInventoryService;
use Illuminate\Support\Facades\Cache;

class MobileOcrPresenter
{
    public const PREFS_TTL_SECONDS = 86400;

    public function __construct(
        protected OcrBatchService $batches,
        protected NetworkInventoryService $inventory,
        protected InvoiceCartSheetService $cartSheet,
        protected OcrStorage $ocrStorage,
        protected OcrQtyUnitResolver $qtyUnits,
        protected OcrLineUiAction $uiAction,
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
     * @return array<string, mixed>
     */
    public function present(OcrBatch $batch, ?User $viewer = null): array
    {
        $batch->refresh();
        $batch->load(['images' => fn ($q) => $q->whereNull('replaced_by_id')->orderBy('position')]);

        $viewer ??= $batch->user;
        // Seller POS stock (wholesaler or retail trader) — NOT factory warehouse.
        $stockByProduct = $this->stockQtyByProduct($viewer);

        $prefs = $this->prefs($batch);
        $built = $this->batches->buildPosDraft($batch);
        $draft = is_array($built['draft'] ?? null) ? $built['draft'] : null;

        if (is_array($draft)) {
            $draft['system_default'] = $prefs['system_default'];
            $draft['customer_user_id'] = $prefs['customer_user_id'];
            $draft['lines'] = $this->decorateLines((array) ($draft['lines'] ?? []), $stockByProduct);
            $draft['excluded'] = array_values((array) ($draft['excluded'] ?? []));
        }

        $hasImages = $batch->images->isNotEmpty();
        $pending = $batch->images->contains(
            fn (OcrImage $i) => in_array($i->status, ['queued', 'reading', 'retrying'], true)
        );
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
        $partition = $step === 'added'
            ? $this->rebuildCartPartition($batch, $stockByProduct)
            : ['cart_lines' => null, 'unavailable_lines' => null];

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
            'stock_source' => 'seller_pos',
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
            'cart_lines' => $partition['cart_lines'],
            'unavailable_lines' => $partition['unavailable_lines'],
            'gemini_enabled' => app(\App\Services\Ai\GeminiClient::class)->enabled(),
        ];
    }

    /**
     * @param  array<int, int>  $stockByProduct
     * @param  list<array<string, mixed>>  $draftLines
     * @return array{cart_lines:list<array<string,mixed>>, unavailable_lines:list<array<string,mixed>>}
     */
    public function buildCartPartitionFromApproved(array $draftLines, array $stockByProduct = []): array
    {
        $available = [];
        $unavailable = [];

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

            $qtyUnit = (string) ($line['qty_unit'] ?? 'piece');
            $cartLine = $this->cartLineForUnit($product, $qty, $qtyUnit);
            $cartLine = $this->decorateCartLine($cartLine, $stockByProduct);
            $cartLine['qty_unit'] = $qtyUnit;
            $cartLine['qty_unit_source'] = $line['qty_unit_source'] ?? 'default';
            $cartLine['ocr_line_id'] = $line['ocr_line_id'] ?? null;

            if (! ($cartLine['in_stock'] ?? false)) {
                $cartLine['reason'] = 'out_of_stock';
                $unavailable[$productId] = $this->mergeUnavailable($unavailable[$productId] ?? null, $cartLine);
            } else {
                $available[$productId] = $this->mergeAvailable($available[$productId] ?? null, $cartLine, $stockByProduct);
            }
        }

        return [
            'cart_lines' => array_values($available),
            'unavailable_lines' => array_values($unavailable),
        ];
    }

    /**
     * @deprecated use buildCartPartitionFromApproved
     *
     * @param  array<int, int>  $stockByProduct
     * @return list<array<string, mixed>>
     */
    public function buildCartLinesFromApproved(array $draftLines, array $stockByProduct = []): array
    {
        return $this->buildCartPartitionFromApproved($draftLines, $stockByProduct)['cart_lines'];
    }

    /**
     * @param  array<int, int>  $stockByProduct
     * @return array{cart_lines:list<array<string,mixed>>, unavailable_lines:list<array<string,mixed>>}
     */
    public function rebuildCartPartition(OcrBatch $batch, array $stockByProduct = []): array
    {
        $built = $this->batches->buildPosDraft($batch);
        $draftLines = (array) ($built['draft']['lines'] ?? []);

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

        return $this->buildCartPartitionFromApproved($added, $stockByProduct);
    }

    /**
     * @return array<int, int> product_id => available_qty (pieces) from seller POS stock
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

        $signed = $this->ocrStorage->signedPreviewUrl($img);

        return [
            'id' => $img->id,
            'position' => $img->position,
            'status' => $img->status,
            'lines_count' => $img->lines_count,
            'error_code' => $img->last_error_code,
            'preview_url' => $signed,
            'preview_expires_at' => $signed ? $this->ocrStorage->signedPreviewExpiresAt() : null,
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
    protected function decorateLines(array $lines, array $stockByProduct): array
    {
        return array_map(function (array $line) use ($stockByProduct) {
            $ppb = (int) ($line['pieces_per_box'] ?? 0);
            if (! isset($line['qty_unit'])) {
                $line = $this->qtyUnits->apply($line, $ppb > 0 ? $ppb : null);
            }

            $productId = (int) ($line['matched_product_id'] ?? 0);
            $available = $productId > 0 ? (int) ($stockByProduct[$productId] ?? 0) : 0;
            $qty = (float) ($line['qty'] ?? 0);
            $unit = (string) ($line['qty_unit'] ?? 'piece');
            $requestedPieces = ($unit === 'box' && $ppb > 0)
                ? (int) round($qty * $ppb)
                : (int) round($qty);

            $line['available_qty'] = $available;
            $line['in_stock'] = $productId > 0 && $available > 0;
            $line['qty_exceeds_stock'] = $productId > 0 && $requestedPieces > $available;
            $line['ui_action'] = $this->uiAction->for($line);

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
     * @return array<string, mixed>
     */
    protected function cartLineForUnit(Product $product, int $amount, string $qtyUnit): array
    {
        $pieces = (int) ($product->pieces_per_box ?? 0);

        if ($qtyUnit === 'box' && $pieces > 0) {
            return $this->cartSheet->cartLine($product, $amount);
        }

        // Explicit pieces (or non-boxed product): never invent boxes.
        return [
            'product_id' => (int) $product->id,
            'name' => localized_name($product, 'name', 'منتج #'.$product->id),
            'quantity' => $amount,
            'boxes' => null,
            'pieces_per_box' => $pieces > 0 ? $pieces : null,
            'points_per_unit' => (float) ($product->points_per_unit ?? 0),
            'image' => $product->main_image,
        ];
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

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $incoming
     * @param  array<int, int>  $stockByProduct
     * @return array<string, mixed>
     */
    protected function mergeAvailable(?array $existing, array $incoming, array $stockByProduct): array
    {
        if ($existing === null) {
            return $incoming;
        }
        if (! empty($incoming['boxes']) && ! empty($existing['boxes'])) {
            $existing['boxes'] = (int) $existing['boxes'] + (int) $incoming['boxes'];
            $existing['quantity'] = (int) $existing['boxes'] * (int) ($existing['pieces_per_box'] ?? 1);
        } else {
            $existing['quantity'] = (int) $existing['quantity'] + (int) $incoming['quantity'];
        }

        return $this->decorateCartLine($existing, $stockByProduct);
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    protected function mergeUnavailable(?array $existing, array $incoming): array
    {
        if ($existing === null) {
            return $incoming;
        }
        $existing['quantity'] = (int) ($existing['quantity'] ?? 0) + (int) ($incoming['quantity'] ?? 0);
        if (! empty($incoming['boxes'])) {
            $existing['boxes'] = (int) ($existing['boxes'] ?? 0) + (int) $incoming['boxes'];
        }

        return $existing;
    }

    protected function prefsKey(int $batchId): string
    {
        return 'ocr:mobile:prefs:'.$batchId;
    }
}
