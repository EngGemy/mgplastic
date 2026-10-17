<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceDistributionItem;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Services\InvoiceReturnService;
use App\Services\NetworkInventoryService;
use App\Services\OrderService;
use App\Services\WholesalerPointsSyncService;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderDeliverLockAndWarehouseReturnBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_deliver_or_receive_does_not_mint_stock_twice(): void
    {
        $wholesaler = User::factory()->create([
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
        $product = Product::factory()->create(['points_per_unit' => 1]);

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
            'points_per_unit' => 1,
            'total_points' => 20,
        ]);
        app(WholesalerPointsSyncService::class)->syncForWholesaler($wholesaler);

        $orders = app(OrderService::class);
        $order = $orders->place($retail, OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL, [
            ['product_id' => $product->id, 'quantity' => 4],
        ], ['supplier_id' => $wholesaler->id]);

        $orders->confirm($order, $wholesaler);

        $first = $orders->deliver($order->fresh(), $wholesaler);
        $this->assertSame(OrderStatus::DELIVERED, $first->status);

        $stockAfterFirst = (int) app(NetworkInventoryService::class)
            ->stockForRetailTrader($retail->fresh())
            ->first()['available_qty'];
        $this->assertSame(4, $stockAfterFirst);

        try {
            $orders->deliver($order->fresh(), $retail);
            $this->fail('Second deliver/receive should be rejected');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('التسليم', $e->getMessage());
        }

        $stockAfterSecond = (int) app(NetworkInventoryService::class)
            ->stockForRetailTrader($retail->fresh())
            ->first()['available_qty'];
        $this->assertSame(4, $stockAfterSecond);
    }

    public function test_warehouse_to_wholesale_distribution_return_is_blocked(): void
    {
        $warehouse = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_central_warehouse' => true,
            'is_approved' => true,
            'is_active' => true,
        ]);
        $buyer = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_central_warehouse' => false,
            'is_approved' => true,
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['points_per_unit' => 1]);

        $sourceInvoice = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'incoming',
            'wholesale_distributor_id' => $warehouse->id,
        ]);
        $sourceItem = InvoiceItem::factory()->create([
            'invoice_id' => $sourceInvoice->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'points_per_unit' => 1,
            'total_points' => 10,
        ]);
        app(WholesalerPointsSyncService::class)->syncForWholesaler($warehouse);

        $distribution = InvoiceDistribution::query()->create([
            'invoice_id' => $sourceInvoice->id,
            'from_user_id' => $warehouse->id,
            'to_user_id' => $buyer->id,
            'tier' => 2,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        InvoiceDistributionItem::query()->create([
            'distribution_id' => $distribution->id,
            'invoice_item_id' => $sourceItem->id,
            'quantity' => 5,
            'points_value' => 5,
            'returned_quantity' => 0,
        ]);

        $gate = app(InvoiceReturnService::class)->previewReturnGate($distribution->fresh(['fromUser', 'toUser', 'items']));
        $this->assertFalse($gate['can_return']);
        $this->assertStringContainsString('المخزن', (string) $gate['block_reason']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/المخزن/');

        app(InvoiceReturnService::class)->returnDistribution(
            $distribution->fresh(['items.invoiceItem', 'fromUser', 'toUser']),
            [['invoice_item_id' => $sourceItem->id, 'quantity' => 2]],
            $buyer,
        );
    }
}
