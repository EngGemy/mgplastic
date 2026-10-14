<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\NetworkInventoryService;
use App\Services\OrderService;
use App\Services\PlumberDistributionPosService;
use App\Services\PlumberRetailLinkService;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PlumberRetailLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_order_links_the_plumber_and_a_later_order_does_not_move_him(): void
    {
        $shop = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $other = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'parent_distributor_id' => null,
            'network_code' => 'MG-P-000041',
        ]);
        $product = Product::factory()->create(['points_per_unit' => 1]);

        $this->bindStock($product);

        app(OrderService::class)->place($plumber, OrderStatus::CHANNEL_RETAIL_TO_PLUMBER, [
            ['product_id' => $product->id, 'quantity' => 1],
        ], ['retail_trader_id' => $shop->id]);

        $this->assertSame($shop->id, $plumber->fresh()->parent_distributor_id);

        app(OrderService::class)->place($plumber->fresh(), OrderStatus::CHANNEL_RETAIL_TO_PLUMBER, [
            ['product_id' => $product->id, 'quantity' => 1],
        ], ['retail_trader_id' => $other->id]);

        $this->assertSame($shop->id, $plumber->fresh()->parent_distributor_id);
    }

    public function test_link_by_code_claims_a_free_plumber_and_refuses_one_already_taken(): void
    {
        $shop = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $other = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $free = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'phone' => '0916111000',
            'network_code' => 'MG-P-000088',
        ]);
        $taken = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'parent_distributor_id' => $other->id,
            'network_code' => 'MG-P-000099',
        ]);

        $links = app(PlumberRetailLinkService::class);
        $result = $links->linkByCode($shop, 'MG-P-000088');

        $this->assertTrue($result['created_link']);
        $this->assertSame($shop->id, $free->fresh()->parent_distributor_id);

        $again = $links->linkByCode($shop, '0916111000');
        $this->assertFalse($again['created_link']);

        $this->expectException(\DomainException::class);
        $links->linkByCode($shop, $taken->network_code);
    }

    public function test_trader_api_lists_only_his_plumbers_and_can_link_one(): void
    {
        $shop = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $mine = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'parent_distributor_id' => $shop->id,
        ]);
        User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'parent_distributor_id' => null,
            'network_code' => 'MG-P-000055',
        ]);

        $this->actingAs($shop, 'sanctum')
            ->getJson('/api/v1/mobile/trader/plumbers')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $mine->id);

        $this->actingAs($shop, 'sanctum')
            ->postJson('/api/v1/mobile/trader/plumbers/link', ['code' => 'MG-P-000055'])
            ->assertCreated()
            ->assertJsonPath('data.created_link', true);

        $this->actingAs($shop, 'sanctum')
            ->getJson('/api/v1/mobile/trader/plumbers')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);
    }

    public function test_pos_sale_requires_the_plumber_to_already_be_in_the_network(): void
    {
        $shop = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'parent_distributor_id' => null,
        ]);

        $this->expectException(\DomainException::class);
        app(PlumberDistributionPosService::class)->issueToPlumber($shop, $plumber, [
            ['product_id' => 1, 'quantity' => 1],
        ], $shop);
    }

    /**
     * @return array<string, mixed>
     */
    private function bindStock(Product $product): void
    {
        $inventory = Mockery::mock(NetworkInventoryService::class)->makePartial();
        $inventory->shouldReceive('stockForRetailTrader')->andReturn(collect([[
            'product_id' => $product->id,
            'name' => 'منتج',
            'available_qty' => 10,
            'available_points' => 0,
            'points_per_unit' => 1,
            'category_id' => $product->product_category_id,
            'image' => null,
            'slots' => [],
        ]]));
        $inventory->shouldReceive('allocateFromStock')->andReturn([]);
        $this->app->instance(NetworkInventoryService::class, $inventory);
    }
}
