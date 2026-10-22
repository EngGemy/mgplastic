<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WholesalerJoinRequest;
use App\Services\RetailNetworkLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RetailMultiWholesalerLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_retail_trader_can_link_multiple_wholesalers_after_approval(): void
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

        Sanctum::actingAs($retail);
        $this->postJson('/api/v1/mobile/trader/wholesalers/link', ['network_code' => 'MG-W-000201'])
            ->assertCreated();
        $this->postJson('/api/v1/mobile/trader/wholesalers/link', ['network_code' => 'MG-W-000202'])
            ->assertCreated();

        $r1 = WholesalerJoinRequest::query()->where('wholesaler_id', $w1->id)->firstOrFail();
        $r2 = WholesalerJoinRequest::query()->where('wholesaler_id', $w2->id)->firstOrFail();

        Sanctum::actingAs($w1);
        $this->postJson('/api/v1/mobile/distributor/join-requests/'.$r1->id.'/approve')->assertOk();

        Sanctum::actingAs($w2);
        $this->postJson('/api/v1/mobile/distributor/join-requests/'.$r2->id.'/approve')->assertOk();

        $this->assertTrue($links->isLinked($w1, $retail->fresh()));
        $this->assertTrue($links->isLinked($w2, $retail->fresh()));
        $this->assertCount(2, $links->linkedWholesalerIds($retail->fresh()));

        Sanctum::actingAs($retail->fresh());
        $this->getJson('/api/v1/mobile/trader/wholesalers')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        $this->deleteJson('/api/v1/mobile/trader/wholesalers/'.$w1->id)->assertOk();

        $this->assertFalse($links->isLinked($w1->fresh(), $retail->fresh()));
        $this->assertTrue($links->isLinked($w2->fresh(), $retail->fresh()));
    }
}
