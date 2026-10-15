<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\WholesaleInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WholesaleInvoiceBoxQuantityTest extends TestCase
{
    use RefreshDatabase;

    public function test_boxes_expand_to_the_full_piece_quantity(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $wholesaler = User::factory()->create(['role' => 'wholesale_distributor']);
        $accessory = Product::factory()->create([
            'points_per_unit' => 5,
            'pieces_per_box' => 12,
        ]);
        $pipe = Product::factory()->create([
            'points_per_unit' => 2,
            'pieces_per_box' => null,
        ]);

        $invoice = app(WholesaleInvoiceService::class)->issueFromCart(
            $wholesaler,
            [
                [
                    'product_id' => $accessory->id,
                    'quantity' => 1,
                    'boxes' => 3,
                    'pieces_per_box' => 12,
                    'points_per_unit' => 5,
                ],
                [
                    'product_id' => $pipe->id,
                    'quantity' => 4,
                    'points_per_unit' => 2,
                ],
            ],
            $admin,
        );

        $boxed = $invoice->items->firstWhere('product_id', $accessory->id);
        $loose = $invoice->items->firstWhere('product_id', $pipe->id);

        $this->assertSame(36, (int) $boxed->quantity);
        $this->assertSame(3, (int) $boxed->box_count);
        $this->assertSame(12, (int) $boxed->pieces_per_box);
        $this->assertSame(180, (int) $boxed->total_points);
        $this->assertSame(4, (int) $loose->quantity);
        $this->assertNull($loose->box_count);
    }
}
