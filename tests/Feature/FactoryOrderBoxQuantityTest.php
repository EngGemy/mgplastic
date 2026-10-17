<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactoryOrderBoxQuantityTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_order_converts_boxes_to_pieces(): void
    {
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
            'is_central_warehouse' => true,
        ]);

        $boxed = Product::factory()->create(['pieces_per_box' => 12, 'points_per_unit' => 1]);
        $loose = Product::factory()->create(['pieces_per_box' => null, 'points_per_unit' => 1]);

        $order = app(OrderService::class)->place(
            requester: $wholesaler,
            channel: OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE,
            lines: [
                ['product_id' => $boxed->id, 'boxes' => 3],
                ['product_id' => $loose->id, 'quantity' => 5],
            ],
        );

        $this->assertSame(41, (int) $order->total_quantity); // 3*12 + 5
        $this->assertSame(36, (int) $order->items->firstWhere('product_id', $boxed->id)->quantity);
        $this->assertSame(5, (int) $order->items->firstWhere('product_id', $loose->id)->quantity);
    }

    public function test_factory_order_rejects_non_multiple_piece_qty_for_boxed_product(): void
    {
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);

        $boxed = Product::factory()->create(['pieces_per_box' => 10, 'points_per_unit' => 1]);

        $this->expectException(\DomainException::class);

        app(OrderService::class)->place(
            requester: $wholesaler,
            channel: OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE,
            lines: [
                ['product_id' => $boxed->id, 'quantity' => 7],
            ],
        );
    }

    public function test_factory_order_accepts_piece_qty_that_is_box_multiple(): void
    {
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);

        $boxed = Product::factory()->create(['pieces_per_box' => 10, 'points_per_unit' => 1]);

        $order = app(OrderService::class)->place(
            requester: $wholesaler,
            channel: OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE,
            lines: [
                ['product_id' => $boxed->id, 'quantity' => 20],
            ],
        );

        $this->assertSame(20, (int) $order->total_quantity);
    }
}
