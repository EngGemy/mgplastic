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

    public function test_plumber_can_order_from_multiple_retail_shops(): void
    {
        $shopA = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $shopB = User::factory()->create([
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

        $orders = app(OrderService::class);
        $orders->place($plumber, OrderStatus::CHANNEL_RETAIL_TO_PLUMBER, [
            ['product_id' => $product->id, 'quantity' => 1],
        ], ['retail_trader_id' => $shopA->id]);

        $orders->place($plumber->fresh(), OrderStatus::CHANNEL_RETAIL_TO_PLUMBER, [
            ['product_id' => $product->id, 'quantity' => 1],
        ], ['retail_trader_id' => $shopB->id]);

        $links = app(PlumberRetailLinkService::class);
        $this->assertTrue($links->isLinked($shopA, $plumber->fresh()));
        $this->assertTrue($links->isLinked($shopB, $plumber->fresh()));
        $this->assertCount(2, $links->linkedRetailShopIds($plumber->fresh()));
    }

    public function test_link_by_code_adds_without_removing_other_shops(): void
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
            'name' => 'TEST سباك',
            'parent_distributor_id' => $other->id,
            'network_code' => 'MG-P-000099',
        ]);

        app(PlumberRetailLinkService::class)->attach($other, $taken, $other);

        $links = app(PlumberRetailLinkService::class);
        $result = $links->linkByCode($shop, 'MG-P-000088');

        $this->assertTrue($result['created_link']);
        $this->assertSame($shop->id, $free->fresh()->parent_distributor_id);

        $again = $links->linkByCode($shop, '0916111000');
        $this->assertFalse($again['created_link']);

        $added = $links->linkByCode($shop, $taken->network_code);
        $this->assertTrue($added['created_link']);
        $this->assertTrue($links->isLinked($shop, $taken->fresh()));
        $this->assertTrue($links->isLinked($other, $taken->fresh()));
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
        app(PlumberRetailLinkService::class)->attach($shop, $mine, $shop);

        User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'parent_distributor_id' => null,
            'network_code' => 'MG-P-000055',
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($shop);

        $this->getJson('/api/v1/mobile/trader/plumbers')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $mine->id);

        $this->postJson('/api/v1/mobile/trader/plumbers/link', ['code' => 'MG-P-000055'])
            ->assertCreated()
            ->assertJsonPath('data.created_link', true);
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
        $product = Product::factory()->create(['points_per_unit' => 1]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/شبكتك/');

        app(PlumberDistributionPosService::class)->issueToPlumber($shop, $plumber, [
            ['product_id' => $product->id, 'quantity' => 1],
        ], $shop);
    }

    protected function bindStock(Product $product): void
    {
        $inventory = Mockery::mock(NetworkInventoryService::class);
        $inventory->shouldReceive('stockForRetailTrader')->andReturn(collect([
            [
                'product_id' => $product->id,
                'name' => 'p',
                'available_qty' => 100,
                'points_per_unit' => 1,
                'slots' => [['invoice_id' => 1, 'parent_distribution_id' => 1, 'invoice_item_id' => 1, 'qty' => 100]],
            ],
        ]));
        $inventory->shouldReceive('allocateFromStock')->andReturnUsing(function ($stock, $requested) {
            return [[
                'invoice_id' => 1,
                'parent_distribution_id' => 1,
                'items' => collect($requested)->map(fn ($qty, $pid) => [
                    'invoice_item_id' => 1,
                    'product_id' => $pid,
                    'quantity' => $qty,
                ])->values()->all(),
            ]];
        });
        $this->app->instance(NetworkInventoryService::class, $inventory);
    }
}
