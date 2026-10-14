<?php

namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Models\InvoiceDistributionItem;
use App\Services\InvoiceAdjustmentService;
use App\Services\WholesaleInvoiceStatsService;
use App\Services\WholesalerNetworkService;
use App\Support\StorePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceAdjustmentPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_with_permission_can_change_quantity_and_delete_an_undistributed_invoice(): void
    {
        [$wholesaler, $invoice, $item] = $this->invoiceForWholesaler();

        $this->actingAs($wholesaler);
        $this->assertTrue(InvoiceResource::canAdjustInvoiceQuantities($invoice));
        $this->assertTrue(InvoiceResource::canDeleteInvoice($invoice));

        $updated = app(InvoiceAdjustmentService::class)->updateQuantity($item, 8);

        $this->assertSame(8, $updated->quantity);
        $this->assertSame(40, $updated->total_points);

        app(InvoiceAdjustmentService::class)->deleteInvoice($invoice->fresh());

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    public function test_changing_invoice_quantity_updates_stock_and_dashboard_together(): void
    {
        [$wholesaler, $invoice, $item] = $this->invoiceForWholesaler();
        User::factory()->create(['role' => 'super_admin']);
        $retail = User::factory()->create(['role' => 'retail_trader', 'is_active' => true]);

        app(InvoiceAdjustmentService::class)->updateQuantity($item, 10);

        $receipt = InvoiceDistributionItem::query()->where('invoice_item_id', $item->id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame(10, (int) $receipt->quantity);
        $this->assertSame(50, (int) $receipt->points_value);

        $distribution = InvoiceDistribution::query()->create([
            'invoice_id' => $invoice->id,
            'from_user_id' => $wholesaler->id,
            'to_user_id' => $retail->id,
            'tier' => 2,
            'status' => 'confirmed',
            'parent_distribution_id' => $receipt->distribution_id,
        ]);
        $distribution->items()->create([
            'invoice_item_id' => $item->id,
            'quantity' => 4,
            'points_value' => 20,
        ]);

        $summary = app(WholesalerNetworkService::class)->getSummary($wholesaler);

        $this->assertSame(20, $summary['distributed_points']);
        $this->assertSame(30, $summary['remaining_points']);
        $this->assertSame(50, $summary['factory_points']);
        $this->assertSame($summary['remaining_points'], $summary['balance_points']);
        $this->assertSame(6, $summary['product_units']);
        $this->assertSame(
            $summary['factory_points'],
            $summary['remaining_points'] + $summary['distributed_points']
        );

        $stats = WholesaleInvoiceStatsService::forDistributor($wholesaler->id);
        $this->assertSame($summary['factory_points'], $stats['total_points']);
        $this->assertSame(20, $stats['distributed']);
        $this->assertSame(30, $stats['remaining']);
        $this->assertSame(40, $stats['percent']);
        $this->assertLessThanOrEqual(100, $stats['percent']);

        $loaded = Invoice::query()->withDistributionStats()->findOrFail($invoice->id);
        $this->assertSame(20, $loaded->distributedPointsSum());
        $this->assertSame(40, $loaded->distributionPercent());
        $this->assertSame(30, $loaded->remainingPointsSum());

        Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'outgoing',
            'wholesale_distributor_id' => $wholesaler->id,
            'counterparty_user_id' => $retail->id,
            'points_awarded' => 20,
        ]);

        $withTraderInvoices = app(WholesalerNetworkService::class)->getSummary($wholesaler->fresh());

        $this->assertSame(70, $withTraderInvoices['factory_points']);
        $this->assertSame(20, $withTraderInvoices['distributed_points']);
        $this->assertSame(30, $withTraderInvoices['remaining_points']);
        $this->assertSame(
            70,
            WholesaleInvoiceStatsService::forDistributor($wholesaler->id)['total_points']
        );
    }

    public function test_view_only_permission_hides_edit_and_delete(): void
    {
        [$wholesaler, $invoice] = $this->invoiceForWholesaler();
        $wholesaler->forceFill([
            'permissions' => [StorePermissions::INVOICES_VIEW],
        ])->save();

        $this->actingAs($wholesaler->fresh());

        $this->assertFalse(InvoiceResource::canAdjustInvoiceQuantities($invoice));
        $this->assertFalse(InvoiceResource::canDeleteInvoice($invoice));
    }

    public function test_quantity_cannot_drop_below_what_was_distributed_and_delete_is_blocked(): void
    {
        [$wholesaler, $invoice, $item] = $this->invoiceForWholesaler();
        $retail = User::factory()->create(['role' => 'retail_trader']);

        $distribution = InvoiceDistribution::query()->create([
            'invoice_id' => $invoice->id,
            'from_user_id' => $wholesaler->id,
            'to_user_id' => $retail->id,
            'tier' => 2,
            'status' => 'confirmed',
        ]);
        $distribution->items()->create([
            'invoice_item_id' => $item->id,
            'quantity' => 4,
            'points_value' => 20,
        ]);

        $service = app(InvoiceAdjustmentService::class);

        try {
            $service->updateQuantity($item->fresh(), 3);
            $this->fail('Quantity below the distributed amount was accepted.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('4', $e->getMessage());
        }

        $this->expectException(\DomainException::class);
        $service->deleteInvoice($invoice->fresh());
    }

    /**
     * @return array{0: User, 1: Invoice, 2: InvoiceItem}
     */
    private function invoiceForWholesaler(): array
    {
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);
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
            'quantity' => 5,
            'points_per_unit' => 5,
            'total_points' => 25,
        ]);

        return [$wholesaler, $invoice, $item];
    }
}
