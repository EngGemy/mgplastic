<?php

namespace App\Services\Ocr;

use App\Models\OcrBatch;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Total reconciliation + unusual qty flags for heavy OCR usage.
 */
class OcrBatchInsights
{
    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function annotateDraft(OcrBatch $batch, array $draft, ?User $viewer = null): array
    {
        $receiptTotal = $this->extractReceiptTotal($draft);
        $computed = $this->computeMatchedTotal($draft, $batch);
        $needs = array_values((array) ($draft['needs_review'] ?? []));
        $flags = (array) ($batch->batch_flags ?? []);
        $batchNeeds = array_values((array) ($flags['needs_review'] ?? []));

        if ($receiptTotal !== null && $computed !== null && $computed > 0) {
            $pct = abs($receiptTotal - $computed) / $computed * 100;
            $threshold = (float) config('ocr.total_mismatch_pct', 5.0);
            if ($pct > $threshold) {
                $needs[] = 'total_mismatch';
                $batchNeeds[] = 'total_mismatch';
            }
        }

        $draft['receipt_total'] = $receiptTotal;
        $draft['computed_total'] = $computed;
        $draft['needs_review'] = array_values(array_unique($needs));

        $lines = [];
        foreach ((array) ($draft['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $lines[] = $this->flagUnusualQty($line, $viewer ?? $batch->user);
        }
        $draft['lines'] = $lines;

        $batch->receipt_total = $receiptTotal;
        $batch->computed_total = $computed;
        $flags['needs_review'] = array_values(array_unique($batchNeeds));
        $batch->batch_flags = $flags;
        $batch->save();

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    protected function extractReceiptTotal(array $draft): ?float
    {
        foreach (['receipt_total', 'grand_total', 'total', 'invoice_total'] as $key) {
            if (isset($draft[$key]) && is_numeric($draft[$key])) {
                return round((float) $draft[$key], 2);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    protected function computeMatchedTotal(array $draft, OcrBatch $batch): ?float
    {
        $sum = 0.0;
        $any = false;
        foreach ((array) ($draft['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $pid = (int) ($line['matched_product_id'] ?? 0);
            $qty = (float) ($line['qty'] ?? 0);
            if ($pid < 1 || $qty <= 0) {
                continue;
            }
            $product = Product::query()->find($pid);
            if (! $product) {
                continue;
            }
            $unitPrice = (float) ($product->price ?? $product->wholesale_price ?? 0);
            if ($unitPrice <= 0) {
                continue;
            }
            $ppb = (int) ($product->pieces_per_box ?? 0);
            $unit = (string) ($line['qty_unit'] ?? 'piece');
            $pieces = ($unit === 'box' && $ppb > 0) ? $qty * $ppb : $qty;
            $sum += $pieces * $unitPrice;
            $any = true;
        }

        return $any ? round($sum, 2) : null;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    protected function flagUnusualQty(array $line, ?User $user): array
    {
        $pid = (int) ($line['matched_product_id'] ?? 0);
        $qty = (float) ($line['qty'] ?? 0);
        $ppb = (int) ($line['pieces_per_box'] ?? 0);
        $unit = (string) ($line['qty_unit'] ?? 'piece');
        $pieces = (int) round(($unit === 'box' && $ppb > 0) ? $qty * $ppb : $qty);
        if ($pid < 1 || $pieces < 1 || ! $user) {
            return $line;
        }

        $maxHist = (int) OrderItem::query()
            ->whereHas('order', function ($q) use ($user) {
                $q->where('requester_id', $user->id)
                    ->where('created_at', '>=', now()->subDays(90));
            })
            ->where('product_id', $pid)
            ->max('quantity');

        $needs = array_values((array) ($line['needs_review'] ?? []));
        if ($maxHist > 0) {
            $mult = (float) config('ocr.qty_unusual_history_multiplier', 3.0);
            if ($pieces > $maxHist * $mult) {
                $needs[] = 'qty_unusual';
            }
        } else {
            $typical = max(10, (int) DB::table('order_items')->where('product_id', $pid)->avg('quantity') ?: 20);
            $mult = (float) config('ocr.qty_unusual_typical_multiplier', 5.0);
            if ($pieces > $typical * $mult) {
                $needs[] = 'qty_unusual';
            }
        }

        $line['needs_review'] = array_values(array_unique($needs));

        return $line;
    }
}
