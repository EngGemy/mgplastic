<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Services\WholesalerPointsSyncService;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileNetworkOrdersApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_retail_trader_places_order_to_wholesaler_and_gets_notified_on_confirm(): void
    {
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
            'phone' => '0911111111',
        ]);
        User::factory()->create(['role' => 'super_admin']);
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
            'phone' => '0922222222',
        ]);
        $product = Product::factory()->create(['points_per_unit' => 2]);

        // Confirm/ship now require wholesaler stock — seed incoming inventory first.
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
            'points_per_unit' => 2,
            'total_points' => 40,
        ]);
        app(WholesalerPointsSyncService::class)->syncForWholesaler($wholesaler);

        $this->assertGreaterThan(
            0,
            (int) app(\App\Services\NetworkInventoryService::class)
                ->stockForWholesaler($wholesaler->fresh())
                ->sum('available_qty'),
            'Wholesaler inventory must be seeded before confirm/ship'
        );

        Sanctum::actingAs($retail);

        $place = $this->postJson('/api/v1/mobile/trader/orders', [
            'supplier_id' => $wholesaler->id,
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 3],
            ],
            'note' => 'طلب تجريبي',
        ]);

        $place->assertCreated()
            ->assertJsonPath('data.channel', OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL)
            ->assertJsonPath('data.status', OrderStatus::PLACED)
            ->assertJsonPath('data.available_actions', fn ($actions) => in_array('cancel', $actions, true));

        $orderId = (int) $place->json('data.id');

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $wholesaler->id,
        ]);

        Sanctum::actingAs($wholesaler);

        $this->getJson('/api/v1/mobile/distributor/orders?scope=incoming')
            ->assertOk()
            ->assertJsonPath('data.0.id', $orderId);

        $confirm = $this->postJson("/api/v1/mobile/distributor/orders/{$orderId}/confirm", [
            'note' => 'تم القبول',
        ]);

        $confirm->assertOk()
            ->assertJsonPath('data.status', OrderStatus::CONFIRMED);

        $actions = $confirm->json('data.available_actions') ?? [];
        $this->assertContains('ship', $actions);
        $this->assertNotContains('deliver', $actions);

        Sanctum::actingAs($retail);
        $detail = $this->getJson("/api/v1/mobile/trader/orders/{$orderId}")->assertOk();
        $this->assertContains('receive', $detail->json('data.available_actions') ?? []);

        $this->getJson('/api/v1/mobile/notifications?order_id='.$orderId)
            ->assertOk()
            ->assertJsonPath('data.items.0.order_id', $orderId)
            ->assertJsonPath('data.items.0.entity_type', 'order');
    }

    public function test_wholesaler_places_factory_order_and_can_cancel(): void
    {
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);
        User::factory()->create(['role' => 'super_admin', 'is_approved' => true, 'is_active' => true]);
        $product = Product::factory()->create(['points_per_unit' => 1]);

        Sanctum::actingAs($wholesaler);

        $place = $this->postJson('/api/v1/mobile/distributor/orders', [
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 10],
            ],
        ]);

        $place->assertCreated()
            ->assertJsonPath('data.channel', OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE)
            ->assertJsonPath('data.status', OrderStatus::PLACED);

        $orderId = (int) $place->json('data.id');

        $this->getJson('/api/v1/mobile/distributor/orders?scope=to_factory')
            ->assertOk()
            ->assertJsonPath('data.0.id', $orderId);

        $this->getJson('/api/v1/mobile/distributor/products')
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'pagination']]);

        $this->postJson("/api/v1/mobile/distributor/orders/{$orderId}/cancel", [
            'reason' => 'تغيير الكمية',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::CANCELLED);
    }

    public function test_retail_buyer_receives_order_supplier_cannot_deliver(): void
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

        Sanctum::actingAs($retail);
        $orderId = (int) $this->postJson('/api/v1/mobile/trader/orders', [
            'supplier_id' => $wholesaler->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->json('data.id');

        Sanctum::actingAs($wholesaler);
        $this->postJson("/api/v1/mobile/distributor/orders/{$orderId}/confirm")->assertOk();
        $this->postJson("/api/v1/mobile/distributor/orders/{$orderId}/deliver")
            ->assertForbidden();

        Sanctum::actingAs($retail);
        $this->postJson("/api/v1/mobile/trader/orders/{$orderId}/receive")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::DELIVERED);

        $this->getJson('/api/v1/mobile/trader/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.unread', fn ($n) => $n >= 1);
    }
}
