<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceDistributionItem;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductPointsRateChange;
use App\Models\User;
use App\Services\DistributionService;
use App\Services\InvoiceReturnService;
use App\Services\NetworkInventoryService;
use App\Services\ProductPointsRateService;
use App\Services\WholesalerPointsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Proves points policy end-to-end:
 * - Stock sales award at **lot receive rate** (invoice_item.points_per_unit)
 *   so old/new stock can be split into separate invoices with correct qty×ppu.
 * - RETURNS claw back awarded distribution points_value (not re-price).
 */
class CatalogPointsSaleReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_distribution_awards_lot_receive_rate_not_later_catalog(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $wholesaler = User::factory()->create(['role' => 'wholesale_distributor', 'is_approved' => true, 'is_active' => true]);
        $retailer = User::factory()->create(['role' => 'retail_trader', 'is_approved' => true, 'is_active' => true]);
        $plumber = User::factory()->create(['role' => 'plumber', 'is_active' => true]);

        $this->actingAs($superAdmin);

        // Receive snapshot on invoice = 5; catalog later becomes 12.
        $product = Product::factory()->create(['points_per_unit' => 5.0]);
        $invoice = Invoice::factory()->create(['status' => 'approved']);
        $invoiceItem = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 100,
            'points_per_unit' => 5.0,
            'total_points' => 500,
        ]);

        $product->points_per_unit = 12.0;
        $product->pointsRateAudited = true;
        $product->save();

        $service = app(DistributionService::class);

        $dist1 = $service->createDistribution($invoice, $superAdmin, $wholesaler, 1, [
            ['invoice_item_id' => $invoiceItem->id, 'quantity' => 100],
        ]);
        $service->confirmDistribution($dist1);

        $dist2 = $service->createDistribution($invoice, $wholesaler, $retailer, 2, [
            ['invoice_item_id' => $invoiceItem->id, 'quantity' => 40],
        ], $dist1->id);
        $service->confirmDistribution($dist2);

        $dist3 = $service->createDistribution($invoice, $retailer, $plumber, 3, [
            ['invoice_item_id' => $invoiceItem->id, 'quantity' => 10],
        ], $dist2->id);

        $distItem = $dist3->items->first();

        // 10 × lot receive 5 = 50 — NOT 10 × later catalog 12 = 120
        $this->assertSame(50, (int) $distItem->points_value);
        $this->assertSame(5.0, (float) $invoiceItem->fresh()->points_per_unit);

        $service->confirmDistribution($dist3->fresh(['items']));
        $this->assertSame(50, (int) $plumber->wallet()->fresh()->balance_points);
    }

    public function test_return_claws_back_awarded_points_not_receive_invoice_rate(): void
    {
        [$wholesale, $retail, $outgoing, $sourceItem, $distribution] = $this->seedTier2WithMismatchedRates(
            receivePpu: 5.0,
            awardedPointsValue: 48, // 4 × catalog 12
            qty: 4,
        );

        // Invoice still shows old receive rate
        $this->assertSame(5.0, (float) $sourceItem->points_per_unit);

        $service = app(InvoiceReturnService::class);
        $this->assertSame(
            48,
            $service->maxReturnablePoints($distribution->fresh(['items'])),
            'Before return: max clawback must be awarded points, not 4×receive=20',
        );

        $return = $service->returnOutgoingInvoice($outgoing, [
            ['invoice_item_id' => $sourceItem->id, 'quantity' => 4],
        ], $retail);

        // Must claw back awarded 48, not 4×5=20
        $this->assertSame(48, (int) $return->total_points);
        $this->assertSame(48, (int) $return->items->sum('points_value'));
    }

    public function test_partial_return_then_remainder_uses_exact_net_points(): void
    {
        [$wholesale, $retail, $outgoing, $sourceItem, $distribution] = $this->seedTier2WithMismatchedRates(
            receivePpu: 5.0,
            awardedPointsValue: 100,
            qty: 10,
        );

        $service = app(InvoiceReturnService::class);

        $first = $service->returnOutgoingInvoice($outgoing, [
            ['invoice_item_id' => $sourceItem->id, 'quantity' => 3],
        ], $retail);
        $this->assertSame(30, (int) $first->total_points); // floor(100*3/10)

        // Proper approve so pending reservation clears and returned_quantity updates
        $service->approve($first, $wholesale, 'اختبار موافقة');

        $distItem = $distribution->items()->first();
        $this->assertSame(3, (int) $distItem->returned_quantity);
        // net = floor(100 * 7 / 10) = 70
        $this->assertSame(70, (int) $distItem->netPoints());

        $second = $service->returnOutgoingInvoice($outgoing->fresh(['sourceDistribution.items']), [
            ['invoice_item_id' => $sourceItem->id, 'quantity' => 7],
        ], $retail);

        // Last remaining qty takes exact leftover net points (70), so 30+70=100 with no leak
        $this->assertSame(70, (int) $second->total_points);
        $this->assertSame(100, (int) $first->total_points + (int) $second->total_points);
    }

    public function test_network_stock_points_follow_lot_receive_rate(): void
    {
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);
        User::factory()->create(['role' => 'super_admin']);

        $product = Product::factory()->create(['points_per_unit' => 12.0]);

        $invoice = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'incoming',
            'wholesale_distributor_id' => $wholesaler->id,
        ]);

        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 20,
            'points_per_unit' => 5.0, // old receive lot
            'total_points' => 100,
        ]);

        app(WholesalerPointsSyncService::class)->syncForWholesaler($wholesaler);

        $inventory = app(NetworkInventoryService::class);
        $row = $inventory->stockForWholesaler($wholesaler)->first();

        $this->assertNotNull($row);
        $this->assertSame(5.0, (float) $row['points_per_unit']);
        $this->assertSame(5.0, (float) $row['slots'][0]['receive_points_per_unit']);
        $this->assertSame(100, (int) $row['available_points']); // 20 × 5 lot

        $network = $inventory->networkRemainingForProduct((int) $product->id);
        $this->assertSame(20, (int) $network['wholesale_remaining_qty']);
        $this->assertNotEmpty($network['wholesale_by_trader']);
        $this->assertSame($wholesaler->id, (int) $network['wholesale_by_trader'][0]['user_id']);
        $this->assertSame(20, (int) $network['wholesale_by_trader'][0]['qty']);
    }

    public function test_apply_change_writes_history_and_notify_path(): void
    {
        User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = Product::factory()->create(['points_per_unit' => 5.0]);

        $change = app(ProductPointsRateService::class)->applyChange(
            $product,
            12.0,
            $admin,
            'feature test rate hike',
            notifyAdmins: false,
        );

        $this->assertSame(5.0, (float) $change->old_points_per_unit);
        $this->assertSame(12.0, (float) $change->new_points_per_unit);
        $this->assertSame(12.0, (float) $product->fresh()->points_per_unit);
        $this->assertDatabaseHas('product_points_rate_changes', [
            'product_id' => $product->id,
            'note' => 'feature test rate hike',
        ]);
        $this->assertSame(1, ProductPointsRateChange::query()->where('product_id', $product->id)->count());
    }

    public function test_plumber_return_api_uses_awarded_points_after_rate_mismatch(): void
    {
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'parent_distributor_id' => $retail->id,
        ]);
        $product = Product::factory()->create(['points_per_unit' => 12.0]);

        $invoice = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'outgoing',
        ]);
        $invoiceItem = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'points_per_unit' => 5.0, // receive/archive
            'total_points' => 25,
        ]);

        $distribution = InvoiceDistribution::query()->create([
            'invoice_id' => $invoice->id,
            'from_user_id' => $retail->id,
            'to_user_id' => $plumber->id,
            'tier' => 3,
            'status' => 'points_awarded',
            'confirmed_at' => now(),
            'points_awarded_at' => now(),
        ]);

        InvoiceDistributionItem::query()->create([
            'distribution_id' => $distribution->id,
            'invoice_item_id' => $invoiceItem->id,
            'quantity' => 5,
            'points_value' => 60, // awarded at catalog 12
            'returned_quantity' => 0,
        ]);

        $plumber->wallet()->creditPoints(60, [
            'reason' => 'points_awarded',
            'distribution_id' => $distribution->id,
        ], $retail, 'seed award at catalog');

        Sanctum::actingAs($plumber);

        $this->getJson('/api/v1/mobile/plumber/distributions/'.$distribution->id.'/return-preview')
            ->assertOk()
            ->assertJsonPath('data.can_return', true)
            ->assertJsonPath('data.returnable_points_preview', 60)
            ->assertJsonPath('data.product_points_preview', 60)
            ->assertJsonPath('data.lines.0.returnable_points', 60)
            ->assertJsonPath('data.lines.0.awarded_points', 60);

        $this->postJson('/api/v1/mobile/plumber/distributions/'.$distribution->id.'/returns', [
            'lines' => [['invoice_item_id' => $invoiceItem->id, 'quantity' => 5]],
            'note' => 'مرتجع كامل بعد تغيير السعر',
        ])
            ->assertCreated()
            ->assertJsonPath('data.total_quantity', 5)
            ->assertJsonPath('data.total_points', 60);
    }

    /**
     * @return array{0:User,1:User,2:Invoice,3:InvoiceItem,4:InvoiceDistribution}
     */
    protected function seedTier2WithMismatchedRates(float $receivePpu, int $awardedPointsValue, int $qty): array
    {
        $wholesale = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);
        User::factory()->create(['role' => 'super_admin']);
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['points_per_unit' => 12.0]);

        $sourceInvoice = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'incoming',
            'wholesale_distributor_id' => $wholesale->id,
        ]);
        $sourceItem = InvoiceItem::factory()->create([
            'invoice_id' => $sourceInvoice->id,
            'product_id' => $product->id,
            'quantity' => $qty + 10,
            'points_per_unit' => $receivePpu,
            'total_points' => (int) floor(($qty + 10) * $receivePpu),
        ]);
        app(WholesalerPointsSyncService::class)->syncForWholesaler($wholesale);

        $distribution = InvoiceDistribution::query()->create([
            'invoice_id' => $sourceInvoice->id,
            'from_user_id' => $wholesale->id,
            'to_user_id' => $retail->id,
            'tier' => 2,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        InvoiceDistributionItem::query()->create([
            'distribution_id' => $distribution->id,
            'invoice_item_id' => $sourceItem->id,
            'quantity' => $qty,
            'points_value' => $awardedPointsValue,
            'returned_quantity' => 0,
        ]);

        $outgoing = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'outgoing',
            'wholesale_distributor_id' => $wholesale->id,
            'source_distribution_id' => $distribution->id,
            'points_awarded' => $awardedPointsValue,
        ]);

        return [
            $wholesale,
            $retail,
            $outgoing->fresh('sourceDistribution.items'),
            $sourceItem,
            $distribution->fresh('items'),
        ];
    }
}
