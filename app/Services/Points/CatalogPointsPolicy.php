<?php

namespace App\Services\Points;

use App\Models\InvoiceDistributionItem;
use App\Models\Product;

/**
 * Single source of truth for catalog points pricing across sales & returns.
 *
 * Policy (anti-abuse):
 * - NEW sales → always live catalog `product.points_per_unit` (unified rate),
 *   even when fulfilling stock received at an older invoice rate.
 * - RETURNS → reverse points actually awarded on that distribution line
 *   (`invoice_distribution_items.points_value`), never re-price at catalog
 *   or at the receive/invoice snapshot. Prevents gaming after a rate change.
 */
final class CatalogPointsPolicy
{
    /**
     * Points awarded for a NEW sale quantity at the live catalog rate.
     */
    public function awardForSale(?Product $product, int $quantity, ?float $fallbackPerUnit = null): int
    {
        $qty = max(0, $quantity);
        if ($qty <= 0) {
            return 0;
        }

        $ppu = $this->catalogRate($product, $fallbackPerUnit);

        return (int) floor($qty * $ppu);
    }

    /**
     * Live catalog points-per-unit (never negative).
     */
    public function catalogRate(?Product $product, ?float $fallbackPerUnit = null): float
    {
        if ($product !== null) {
            return max(0, (float) ($product->points_per_unit ?? 0));
        }

        return max(0, (float) ($fallbackPerUnit ?? 0));
    }

    /**
     * Points to claw back when returning $returnQty from an awarded distribution line.
     * Last remaining chunk takes leftover net points so partial returns don't drift.
     */
    public function clawbackForReturn(InvoiceDistributionItem $item, int $returnQty): int
    {
        $returnQty = max(0, $returnQty);
        $netQty = $item->netQuantity();

        if ($returnQty <= 0 || $netQty <= 0) {
            return 0;
        }

        if ($returnQty > $netQty) {
            $returnQty = $netQty;
        }

        // Full remaining qty → exact leftover awarded points (no floor remainder leak).
        if ($returnQty === $netQty) {
            return $item->netPoints();
        }

        $soldQty = max(1, (int) $item->quantity);

        return (int) floor((int) $item->points_value * $returnQty / $soldQty);
    }

    /**
     * Max points still returnable on a distribution line (net of prior returns).
     */
    public function returnablePoints(InvoiceDistributionItem $item): int
    {
        return $item->netPoints();
    }
}
