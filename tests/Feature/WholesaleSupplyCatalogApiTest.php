<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WholesaleSupplyCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_distributor_catalog_filters_box_vs_piece_and_hides_product_points(): void
    {
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);

        $boxed = Product::factory()->create([
            'pieces_per_box' => 12,
            'points_per_unit' => 5,
        ]);
        $loose = Product::factory()->create([
            'pieces_per_box' => null,
            'points_per_unit' => 2,
        ]);

        Sanctum::actingAs($wholesaler);

        $all = $this->getJson('/api/v1/mobile/distributor/products?per_page=50')
            ->assertOk()
            ->assertJsonPath('data.units.box', 1)
            ->assertJsonPath('data.units.piece', 1)
            ->assertJsonPath('data.pagination.has_more', false);

        $ids = collect($all->json('data.items'))->pluck('id');
        $this->assertTrue($ids->contains($boxed->id));
        $this->assertTrue($ids->contains($loose->id));
        $this->assertNull($all->json('data.items.0.points_per_unit'));

        $this->getJson('/api/v1/mobile/distributor/products?order_unit=box')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $boxed->id)
            ->assertJsonPath('data.items.0.order_unit', 'box')
            ->assertJsonPath('data.items.0.points_per_unit', null);

        $this->getJson('/api/v1/mobile/distributor/products?order_unit=piece')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $loose->id)
            ->assertJsonPath('data.items.0.order_unit', 'piece');
    }

    public function test_quote_returns_total_points_without_line_points(): void
    {
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);

        $boxed = Product::factory()->create([
            'pieces_per_box' => 10,
            'points_per_unit' => 2,
        ]);
        $loose = Product::factory()->create([
            'pieces_per_box' => null,
            'points_per_unit' => 3,
        ]);

        Sanctum::actingAs($wholesaler);

        $this->postJson('/api/v1/mobile/distributor/orders/quote', [
            'lines' => [
                ['product_id' => $boxed->id, 'boxes' => 2],
                ['product_id' => $loose->id, 'quantity' => 4],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.total_quantity', 24)
            ->assertJsonPath('data.total_points', 52) // 20*2 + 4*3
            ->assertJsonPath('data.items.0.points_per_unit', null)
            ->assertJsonPath('data.items.0.line_points', null)
            ->assertJsonPath('data.items.0.boxes', 2)
            ->assertJsonPath('data.items.1.order_unit', 'piece');
    }

    public function test_created_order_hides_line_points_exposes_total_only(): void
    {
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
            'is_central_warehouse' => true,
        ]);

        $boxed = Product::factory()->create([
            'pieces_per_box' => 12,
            'points_per_unit' => 1,
        ]);

        Sanctum::actingAs($wholesaler);

        $this->postJson('/api/v1/mobile/distributor/orders', [
            'source' => 'factory',
            'lines' => [
                ['product_id' => $boxed->id, 'boxes' => 2],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.total_quantity', 24)
            ->assertJsonPath('data.total_points', 24)
            ->assertJsonPath('data.items.0.points_per_unit', null)
            ->assertJsonPath('data.items.0.line_points', null)
            ->assertJsonPath('data.items.0.order_unit', 'box')
            ->assertJsonPath('data.items.0.boxes', 2);
    }
}
