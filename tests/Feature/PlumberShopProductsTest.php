<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductTag;
use App\Models\User;
use App\Services\NetworkInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PlumberShopProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_catalog_lists_all_mg_products_with_stock_and_blocks_over_stock_orders(): void
    {
        $plumber = User::factory()->create(['role' => 'plumber']);
        $shop = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $closed = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => false,
            'is_active' => true,
        ]);

        $inStock = Product::factory()->create(['points_per_unit' => 230.3]);
        $inStock->translateOrNew('ar')->name = 'ماسورة ذهبي';
        $inStock->save();

        $outOfStock = Product::factory()->create(['points_per_unit' => 10]);
        $outOfStock->translateOrNew('ar')->name = 'كوع مخفي';
        $outOfStock->save();

        $tagged = Product::factory()->create([
            'product_category_id' => $inStock->product_category_id,
            'points_per_unit' => 5,
        ]);
        $tagged->translateOrNew('ar')->name = 'وصلة ذهبية';
        $tagged->save();
        $tag = ProductTag::query()->create([
            'product_category_id' => $tagged->product_category_id,
            'name' => 'ذهبي',
        ]);
        $tagged->tags()->attach($tag);

        $this->bindStock([
            $this->stockRow($inStock, 40),
            $this->stockRow($tagged, 8),
        ]);

        $this->actingAs($plumber, 'sanctum')
            ->getJson('/api/v1/mobile/plumber/products')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonMissingPath('data.items.0.available_quantity');

        $filtered = $this->actingAs($plumber, 'sanctum')
            ->getJson('/api/v1/mobile/plumber/products?retail_trader_id='.$shop->id)
            ->assertOk()
            ->assertJsonPath('data.stock_mode', true)
            ->assertJsonPath('data.pagination.total', 3);

        $byId = collect($filtered->json('data.items'))->keyBy('id');

        $this->assertSame(40, $byId[$inStock->id]['available_quantity']);
        $this->assertTrue($byId[$inStock->id]['can_order']);
        $this->assertFalse($byId[$inStock->id]['out_of_stock']);
        $this->assertSame(40, $byId[$inStock->id]['max_order_quantity']);

        $this->assertSame(0, $byId[$outOfStock->id]['available_quantity']);
        $this->assertFalse($byId[$outOfStock->id]['can_order']);
        $this->assertTrue($byId[$outOfStock->id]['out_of_stock']);

        $this->assertSame(8, $byId[$tagged->id]['available_quantity']);

        $this->actingAs($plumber, 'sanctum')
            ->getJson('/api/v1/mobile/plumber/products?retail_trader_id='.$shop->id.'&tag=ذهبي')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $tagged->id)
            ->assertJsonPath('data.items.0.available_quantity', 8);

        $this->actingAs($plumber, 'sanctum')
            ->getJson('/api/v1/mobile/plumber/products?retail_trader_id=999999')
            ->assertNotFound();

        $this->actingAs($plumber, 'sanctum')
            ->getJson('/api/v1/mobile/plumber/products?retail_trader_id='.$closed->id)
            ->assertForbidden();

        $this->actingAs($plumber, 'sanctum')
            ->postJson('/api/v1/mobile/plumber/orders', [
                'retail_trader_id' => $shop->id,
                'lines' => [
                    ['product_id' => $inStock->id, 'quantity' => 50],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', false);

        $this->actingAs($plumber, 'sanctum')
            ->postJson('/api/v1/mobile/plumber/orders', [
                'retail_trader_id' => $shop->id,
                'lines' => [
                    ['product_id' => $outOfStock->id, 'quantity' => 1],
                ],
            ])
            ->assertStatus(422);

        $this->actingAs($plumber, 'sanctum')
            ->postJson('/api/v1/mobile/plumber/orders', [
                'retail_trader_id' => $shop->id,
                'lines' => [
                    ['product_id' => $inStock->id, 'quantity' => 2],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('status', true);
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

    /**
     * @return array<string, mixed>
     */
    private function stockRow(Product $product, int $quantity): array
    {
        return [
            'product_id' => $product->id,
            'name' => 'منتج',
            'available_qty' => $quantity,
            'available_points' => 0,
            'points_per_unit' => (float) $product->points_per_unit,
            'category_id' => $product->product_category_id,
            'image' => null,
            'slots' => [[
                'invoice_id' => 1,
                'invoice_item_id' => 1,
                'parent_distribution_id' => 1,
                'available_qty' => $quantity,
                'points_per_unit' => (float) $product->points_per_unit,
            ]],
        ];
    }
}
