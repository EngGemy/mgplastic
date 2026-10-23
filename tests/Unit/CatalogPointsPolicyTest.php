<?php

namespace Tests\Unit;

use App\Models\InvoiceDistributionItem;
use App\Models\Product;
use App\Services\Points\CatalogPointsPolicy;
use Tests\TestCase;

class CatalogPointsPolicyTest extends TestCase
{
    public function test_award_for_sale_uses_catalog_rate_not_fallback(): void
    {
        $policy = new CatalogPointsPolicy;
        $product = new Product;
        $product->points_per_unit = 12.5;

        // Catalog 12.5 wins over receive fallback 5.0
        $this->assertSame(25, $policy->awardForSale($product, 2, 5.0));
        $this->assertSame(0, $policy->awardForSale($product, 0, 5.0));
        $this->assertSame(12.5, $policy->catalogRate($product, 5.0));
    }

    public function test_award_for_lot_sale_uses_lot_rate_so_qty_times_ppu_matches(): void
    {
        $policy = new CatalogPointsPolicy;
        $product = new Product;
        $product->points_per_unit = 15.0; // later catalog — must NOT win over lot 10

        $this->assertSame(200, $policy->awardForLotSale($product, 20, 10.0));
        $this->assertSame(300, $policy->awardForLotSale($product, 20, 0.0)); // lot 0 → catalog
        $this->assertSame(0, $policy->awardForLotSale($product, 0, 10.0));
    }

    public function test_award_falls_back_when_product_missing(): void
    {
        $policy = new CatalogPointsPolicy;

        $this->assertSame(15, $policy->awardForSale(null, 3, 5.0));
        $this->assertSame(5.0, $policy->catalogRate(null, 5.0));
        $this->assertSame(0.0, $policy->catalogRate(null, null));
    }

    public function test_award_uses_zero_catalog_even_when_fallback_positive(): void
    {
        $policy = new CatalogPointsPolicy;
        $product = new Product;
        $product->points_per_unit = 0;

        // Live catalog is 0 → award 0 (do not silently use receive rate).
        $this->assertSame(0, $policy->awardForSale($product, 10, 5.0));
        $this->assertSame(0.0, $policy->catalogRate($product, 5.0));
    }

    public function test_clawback_reverses_awarded_not_invoice_rate(): void
    {
        $policy = new CatalogPointsPolicy;
        $item = new InvoiceDistributionItem([
            'quantity' => 10,
            'returned_quantity' => 0,
            'points_value' => 120, // sold at catalog 12, not receive rate 5
        ]);

        $this->assertSame(36, $policy->clawbackForReturn($item, 3));
        $this->assertSame(120, $policy->clawbackForReturn($item, 10));
        $this->assertSame(120, $policy->returnablePoints($item));
    }

    public function test_clawback_after_partial_return_uses_remaining_net(): void
    {
        $policy = new CatalogPointsPolicy;
        $item = new InvoiceDistributionItem([
            'quantity' => 10,
            'returned_quantity' => 3,
            'points_value' => 120,
        ]);

        // Already returned 3 → net points = floor(120*7/10)=84
        $this->assertSame(84, $item->netPoints());
        $this->assertSame(84, $policy->clawbackForReturn($item, 7));
        $this->assertSame(24, $policy->clawbackForReturn($item, 2));
    }

    public function test_clawback_caps_at_net_quantity(): void
    {
        $policy = new CatalogPointsPolicy;
        $item = new InvoiceDistributionItem([
            'quantity' => 10,
            'returned_quantity' => 8,
            'points_value' => 100,
        ]);

        // Asking for 50 but only 2 remain → clawback net points floor(100*2/10)=20
        $this->assertSame(20, $policy->clawbackForReturn($item, 50));
        $this->assertSame(0, $policy->clawbackForReturn($item, 0));
    }

    public function test_model_points_for_return_qty_delegates_to_policy(): void
    {
        $item = new InvoiceDistributionItem([
            'quantity' => 4,
            'returned_quantity' => 0,
            'points_value' => 48,
        ]);

        $this->assertSame(24, $item->pointsForReturnQty(2));
        $this->assertSame(48, $item->pointsForReturnQty(4));
    }
}
