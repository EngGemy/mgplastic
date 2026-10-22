<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WholesalerJoinRequest;
use App\Services\RetailNetworkLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WholesalerJoinRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_retail_requests_join_and_wholesaler_approves(): void
    {
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
            'brand_name' => 'جملة النور',
            'network_code' => 'MG-W-000301',
        ]);

        Sanctum::actingAs($retail);
        $this->postJson('/api/v1/mobile/trader/wholesalers/link', [
            'network_code' => 'MG-W-000301',
            'message' => 'أريد التوريد منكم',
        ])
            ->assertCreated()
            ->assertJsonPath('data.pending_approval', true)
            ->assertJsonPath('data.created_request', true);

        $this->assertFalse(app(RetailNetworkLinkService::class)->isLinked($wholesaler, $retail->fresh()));

        $reqId = WholesalerJoinRequest::query()->value('id');
        $this->assertNotNull($reqId);

        Sanctum::actingAs($wholesaler);
        $this->getJson('/api/v1/mobile/distributor/join-requests')
            ->assertOk()
            ->assertJsonPath('data.pending_count', 1);

        $this->postJson("/api/v1/mobile/distributor/join-requests/{$reqId}/approve")
            ->assertOk()
            ->assertJsonPath('data.request.status', 'approved');

        $this->assertTrue(app(RetailNetworkLinkService::class)->isLinked($wholesaler->fresh(), $retail->fresh()));
    }

    public function test_wholesaler_can_reject_and_retail_can_cancel(): void
    {
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
            'network_code' => 'MG-W-000302',
        ]);

        Sanctum::actingAs($retail);
        $this->postJson('/api/v1/mobile/trader/wholesaler-join-requests', [
            'network_code' => 'MG-W-000302',
        ])->assertCreated();

        $reqId = (int) WholesalerJoinRequest::query()->value('id');

        Sanctum::actingAs($wholesaler);
        $this->postJson("/api/v1/mobile/distributor/join-requests/{$reqId}/reject", [
            'reason' => 'خارج نطاق التغطية',
        ])
            ->assertOk()
            ->assertJsonPath('data.request.status', 'rejected');

        $this->assertFalse(app(RetailNetworkLinkService::class)->isLinked($wholesaler, $retail->fresh()));

        Sanctum::actingAs($retail);
        $this->postJson('/api/v1/mobile/trader/wholesaler-join-requests', [
            'network_code' => 'MG-W-000302',
        ])->assertCreated();

        $pendingId = (int) WholesalerJoinRequest::query()->pending()->value('id');
        $this->deleteJson("/api/v1/mobile/trader/wholesaler-join-requests/{$pendingId}")
            ->assertOk()
            ->assertJsonPath('data.request.status', 'cancelled');
    }
}
