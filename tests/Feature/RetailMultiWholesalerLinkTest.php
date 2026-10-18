<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RetailNetworkLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RetailMultiWholesalerLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_retail_trader_can_link_multiple_wholesalers(): void
    {
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $w1 = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
            'brand_name' => 'جملة أ',
            'network_code' => 'MG-W-000201',
        ]);
        $w2 = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
            'brand_name' => 'جملة ب',
            'network_code' => 'MG-W-000202',
            'phone' => '0917000202',
        ]);

        $links = app(RetailNetworkLinkService::class);
        $a = $links->linkWholesalerByCode($retail, 'MG-W-000201');
        $b = $links->linkWholesalerByCode($retail, 'MG-W-000202');

        $this->assertTrue($a['created_link']);
        $this->assertTrue($b['created_link']);
        $this->assertTrue($links->isLinked($w1, $retail->fresh()));
        $this->assertTrue($links->isLinked($w2, $retail->fresh()));
        $this->assertCount(2, $links->linkedWholesalerIds($retail->fresh()));

        Sanctum::actingAs($retail);
        $this->getJson('/api/v1/mobile/trader/wholesalers')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        $this->deleteJson('/api/v1/mobile/trader/wholesalers/'.$w1->id)
            ->assertOk();

        $this->assertFalse($links->isLinked($w1->fresh(), $retail->fresh()));
        $this->assertTrue($links->isLinked($w2->fresh(), $retail->fresh()));
    }
}
