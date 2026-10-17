<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Services\NetworkInventoryService;
use App\Services\OrderService;
use App\Services\WholesalerPointsSyncService;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WholesaleOrderSupplierDeliverTest extends TestCase
{
    use RefreshDatabase;

    public function test_wholesaler_can_mark_order_delivered_and_stock_moves_to_retail(): void
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
        $product = Product::factory()->create(['points_per_unit' => 1.5]);

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
            'points_per_unit' => 1.5,
            'total_points' => 30,
        ]);
        app(WholesalerPointsSyncService::class)->syncForWholesaler($wholesaler);

        $orders = app(OrderService::class);
        $order = $orders->place($retail, OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL, [
            ['product_id' => $product->id, 'quantity' => 5],
        ], ['supplier_id' => $wholesaler->id]);

        $orders->confirm($order, $wholesaler);

        $this->actingAs($wholesaler);
        $this->assertTrue(OrderResource::canMarkDeliveredAsSupplier($order->fresh()));

        $delivered = $orders->deliver($order->fresh(), $wholesaler);

        $this->assertSame(OrderStatus::DELIVERED, $delivered->status);
        $this->assertSame($wholesaler->id, (int) $delivered->delivered_by);

        $retailStock = app(NetworkInventoryService::class)->stockForRetailTrader($retail->fresh());
        $this->assertSame(5, (int) $retailStock->first()['available_qty']);
    }

    public function test_buyer_receive_helper_stays_available_after_confirm(): void
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
            'quantity' => 10,
            'points_per_unit' => 1,
            'total_points' => 10,
        ]);
        app(WholesalerPointsSyncService::class)->syncForWholesaler($wholesaler);

        $order = app(OrderService::class)->place($retail, OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL, [
            ['product_id' => $product->id, 'quantity' => 1],
        ], ['supplier_id' => $wholesaler->id]);

        app(OrderService::class)->confirm($order, $wholesaler);

        $this->actingAs($retail);
        $this->assertTrue(OrderResource::canMarkReceivedAsBuyer($order->fresh()));

        $this->actingAs($wholesaler);
        $this->assertTrue(OrderResource::canMarkDeliveredAsSupplier($order->fresh()));
    }

    public function test_confirm_fails_when_wholesaler_has_no_stock(): void
    {
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
        $product = Product::factory()->create(['points_per_unit' => 1]);

        $order = app(OrderService::class)->place($retail, OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL, [
            ['product_id' => $product->id, 'quantity' => 1],
        ], ['supplier_id' => $wholesaler->id]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('مخزون موزّع الجملة');
        app(OrderService::class)->confirm($order, $wholesaler);
    }
}
