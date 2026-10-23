<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Services\DistributionService;
use App\Services\OutgoingInvoiceService;
use App\Services\RetailNetworkLinkService;
use App\Services\WholesalerPointsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutgoingInvoiceDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_tier2_outgoing_invoice_has_line_item_snapshots(): void
    {
        [$admin, $wholesaler, $retail, $product, $invoice, $item, $tier1] = $this->seedTier1Stock(10);

        $this->actingAs($wholesaler);
        $dist2 = app(DistributionService::class)->createDistribution(
            $invoice,
            $wholesaler,
            $retail,
            2,
            [['invoice_item_id' => $item->id, 'quantity' => 4]],
            $tier1->id,
        );

        $outgoing = Invoice::query()->where('source_distribution_id', $dist2->id)->first();
        $this->assertNotNull($outgoing);
        $this->assertSame('outgoing', $outgoing->invoice_flow);
        $this->assertSame('pending_review', $outgoing->status);
        $this->assertSame(1, $outgoing->items()->count());
        $this->assertSame(4, (int) $outgoing->items()->first()->quantity);
        $this->assertSame((int) $product->id, (int) $outgoing->items()->first()->product_id);
    }

    public function test_tier3_creates_sales_invoice_for_plumber(): void
    {
        [$admin, $wholesaler, $retail, $product, $invoice, $item, $tier1] = $this->seedTier1Stock(20);
        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'parent_distributor_id' => $retail->id,
        ]);

        $this->actingAs($wholesaler);
        $dist2 = app(DistributionService::class)->createDistribution(
            $invoice,
            $wholesaler,
            $retail,
            2,
            [['invoice_item_id' => $item->id, 'quantity' => 10]],
            $tier1->id,
        );
        app(DistributionService::class)->confirmDistribution($dist2->fresh(['items']));

        $this->actingAs($retail);
        $dist3 = app(DistributionService::class)->createDistribution(
            $invoice,
            $retail,
            $plumber,
            3,
            [['invoice_item_id' => $item->id, 'quantity' => 3]],
            $dist2->id,
        );

        $sale = Invoice::query()->where('source_distribution_id', $dist3->id)->first();
        $this->assertNotNull($sale, 'retail→plumber must create a sales invoice document');
        $this->assertSame('outgoing', $sale->invoice_flow);
        $this->assertSame((int) $plumber->id, (int) $sale->plumber_id);
        $this->assertSame((int) $retail->id, (int) $sale->issued_by);
        $this->assertSame(1, $sale->items()->count());
        $this->assertSame(3, (int) $sale->items()->sum('quantity'));
    }

    public function test_sync_backfills_missing_items_on_legacy_outgoing(): void
    {
        [$admin, $wholesaler, $retail, $product, $invoice, $item, $tier1] = $this->seedTier1Stock(5);

        $this->actingAs($wholesaler);
        $dist2 = app(DistributionService::class)->createDistribution(
            $invoice,
            $wholesaler,
            $retail,
            2,
            [['invoice_item_id' => $item->id, 'quantity' => 2]],
            $tier1->id,
        );

        $outgoing = Invoice::query()->where('source_distribution_id', $dist2->id)->firstOrFail();
        $outgoing->items()->delete();
        $this->assertSame(0, $outgoing->items()->count());

        app(OutgoingInvoiceService::class)->syncLineItemsFromDistribution($outgoing->fresh());
        $this->assertSame(1, $outgoing->fresh()->items()->count());
        $this->assertSame(2, (int) $outgoing->fresh()->items()->first()->quantity);
    }

    public function test_reconcile_fixes_qty_times_ppu_not_matching_total(): void
    {
        [$admin, $wholesaler, $retail, $product, $invoice, $item, $tier1] = $this->seedTier1Stock(20);

        // Lot receive rate 10 — catalog may differ later.
        $item->forceFill(['points_per_unit' => 10, 'total_points' => 200])->save();
        $product->forceFill(['points_per_unit' => 15])->save();

        $this->actingAs($wholesaler);
        $dist2 = app(DistributionService::class)->createDistribution(
            $invoice,
            $wholesaler,
            $retail,
            2,
            [['invoice_item_id' => $item->id, 'quantity' => 20]],
            $tier1->id,
        );

        $outgoing = Invoice::query()->where('source_distribution_id', $dist2->id)->firstOrFail();
        // Simulate the bug: wrong total stored while ppu shows lot rate.
        $line = $outgoing->items()->first();
        $line->forceFill(['points_per_unit' => 10, 'total_points' => 300])->save();
        $outgoing->forceFill(['points_awarded' => 300])->save();

        app(OutgoingInvoiceService::class)->reconcileLineTotals($outgoing->fresh(['items']));

        $line = $outgoing->fresh()->items()->first();
        $this->assertSame(10.0, (float) $line->points_per_unit);
        $this->assertSame(200, (int) $line->total_points); // 20 × 10
        $this->assertSame(200, (int) $outgoing->fresh()->points_awarded);
        $this->assertSame(200, (int) $dist2->fresh()->items()->first()->points_value);
    }

    /** @return array{0:User,1:User,2:User,3:Product,4:Invoice,5:InvoiceItem,6:\App\Models\InvoiceDistribution} */
    protected function seedTier1Stock(int $qty): array
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        app(RetailNetworkLinkService::class)->attach($wholesaler, $retail, $admin);

        $product = Product::factory()->create(['points_per_unit' => 5]);
        $invoice = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'incoming',
            'wholesale_distributor_id' => $wholesaler->id,
        ]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'points_per_unit' => 5,
            'total_points' => $qty * 5,
        ]);

        $this->actingAs($admin);
        $tier1 = app(DistributionService::class)->createDistribution(
            $invoice,
            $admin,
            $wholesaler,
            1,
            [['invoice_item_id' => $item->id, 'quantity' => $qty]],
        );
        app(DistributionService::class)->confirmDistribution($tier1);
        app(WholesalerPointsSyncService::class)->syncForWholesaler($wholesaler);

        return [$admin, $wholesaler, $retail, $product, $invoice, $item, $tier1->fresh()];
    }
}
