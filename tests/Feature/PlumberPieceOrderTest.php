<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\NetworkInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PlumberPieceOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_forces_piece_order_unit_and_exposes_sku(): void
    {
        $plumber = User::factory()->create(['role' => 'plumber', 'is_approved' => true, 'is_active' => true]);
        $boxed = Product::factory()->create(['pieces_per_box' => 12, 'points_per_unit' => 1]);
        ProductVariant::query()->create([
            'product_id' => $boxed->id,
            'catalog_code' => 'MG-00095',
        ]);

        $res = $this->actingAs($plumber, 'sanctum')
            ->getJson('/api/v1/mobile/plumber/products?per_page=50')
            ->assertOk()
            ->assertJsonPath('status', true);

        $item = collect($res->json('items') ?? $res->json('data.items') ?? [])
            ->firstWhere('product_id', $boxed->id);

        $this->assertNotNull($item);
        $this->assertSame('piece', $item['order_unit']);
        $this->assertSame(12, $item['pieces_per_box']);
        $this->assertSame('MG-00095', $item['sku']);
        $this->assertSame('MG-00095', $item['catalog_code']);
    }

    public function test_order_accepts_piece_qty_for_boxed_product_and_rejects_boxes(): void
    {
        $plumber = User::factory()->create(['role' => 'plumber', 'is_approved' => true, 'is_active' => true]);
        $shop = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $boxed = Product::factory()->create(['pieces_per_box' => 12, 'points_per_unit' => 1]);

        $this->bindStock([[
            'product_id' => $boxed->id,
            'name' => 'منتج',
            'available_qty' => 20,
            'available_points' => 0,
            'points_per_unit' => 1.0,
            'category_id' => null,
            'image' => null,
            'slots' => [[
                'invoice_id' => 1,
                'invoice_item_id' => 1,
                'parent_distribution_id' => 1,
                'available_qty' => 20,
                'points_per_unit' => 1.0,
            ]],
        ]]);

        $this->actingAs($plumber, 'sanctum')
            ->postJson('/api/v1/mobile/plumber/orders', [
                'retail_trader_id' => $shop->id,
                'lines' => [
                    ['product_id' => $boxed->id, 'quantity' => 5],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.quantity', 5);

        $this->actingAs($plumber, 'sanctum')
            ->postJson('/api/v1/mobile/plumber/orders', [
                'retail_trader_id' => $shop->id,
                'lines' => [
                    ['product_id' => $boxed->id, 'boxes' => 1, 'quantity' => 12],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_per_page_allows_500(): void
    {
        $plumber = User::factory()->create(['role' => 'plumber', 'is_approved' => true, 'is_active' => true]);
        Product::factory()->count(3)->create(['points_per_unit' => 1]);

        $this->actingAs($plumber, 'sanctum')
            ->getJson('/api/v1/mobile/plumber/products?per_page=500')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 500);
    }

    public function test_catalog_exposes_thumb_url_and_page_two_omits_groups(): void
    {
        $plumber = User::factory()->create(['role' => 'plumber', 'is_approved' => true, 'is_active' => true]);
        Product::factory()->count(3)->create(['points_per_unit' => 1]);

        $page1 = $this->actingAs($plumber, 'sanctum')
            ->getJson('/api/v1/mobile/plumber/products?per_page=1&page=1')
            ->assertOk();

        $item = collect($page1->json('items') ?? [])->first();
        $this->assertIsArray($item);
        $this->assertArrayHasKey('thumb_url', $item);
        $this->assertIsArray($page1->json('groups'));

        $page2 = $this->actingAs($plumber, 'sanctum')
            ->getJson('/api/v1/mobile/plumber/products?per_page=1&page=2')
            ->assertOk();

        $this->assertSame([], $page2->json('groups'));
        $this->assertSame([], $page2->json('size_groups'));
        $this->assertSame([], $page2->json('data.groups'));
        $this->assertSame([], $page2->json('data.size_groups'));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function bindStock(array $rows): void
    {
        $qtyByProduct = collect($rows)->mapWithKeys(
            fn (array $row) => [(int) $row['product_id'] => (int) $row['available_qty']]
        );
        $inventory = Mockery::mock(NetworkInventoryService::class)->makePartial();
        $inventory->shouldReceive('stockQtyByProductForRetailTrader')->andReturn($qtyByProduct);
        $inventory->shouldReceive('stockForRetailTrader')->andReturn(collect($rows));
        $this->app->instance(NetworkInventoryService::class, $inventory);
    }
}
