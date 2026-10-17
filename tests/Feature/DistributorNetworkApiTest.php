<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RetailNetworkLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DistributorNetworkApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_wholesaler_can_register_and_link_retail_trader(): void
    {
        $wholesale = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($wholesale);

        $this->postJson('/api/v1/mobile/distributor/retail-traders', [
            'name' => 'محل النور',
            'phone' => '0917000111',
            'brand_name' => 'النور',
            'password' => 'secret12',
        ])
            ->assertCreated()
            ->assertJsonPath('data.retail_trader.role', 'retail_trader');

        $retailId = (int) $this->postJson('/api/v1/mobile/distributor/retail-traders', [
            // will fail duplicate phone — create another
            'name' => 'محل السلام',
            'phone' => '0917000222',
        ])->json('data.retail_trader.id');

        $this->assertTrue(
            app(RetailNetworkLinkService::class)->isLinked($wholesale, User::findOrFail($retailId))
        );
    }

    public function test_wholesaler_can_link_existing_retail_by_code_and_add_plumber_under_them(): void
    {
        $wholesale = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
            'network_code' => 'MG-R-900001',
            'phone' => '0918000111',
        ]);
        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'network_code' => 'MG-P-900099',
            'phone' => '0919000111',
            'parent_distributor_id' => null,
        ]);

        Sanctum::actingAs($wholesale);

        $this->postJson('/api/v1/mobile/distributor/retail-traders/link', [
            'network_code' => 'MG-R-900001',
        ])->assertCreated()->assertJsonPath('data.created_link', true);

        $this->postJson('/api/v1/mobile/distributor/plumbers/link', [
            'retail_trader_id' => $retail->id,
            'network_code' => 'MG-P-900099',
        ])
            ->assertCreated()
            ->assertJsonPath('data.plumber.id', $plumber->id);

        $this->assertSame($retail->id, (int) $plumber->fresh()->parent_distributor_id);

        $this->getJson('/api/v1/mobile/distributor/plumbers')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_wholesaler_cannot_add_plumber_under_unlinked_retail(): void
    {
        $wholesale = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'network_code' => 'MG-P-900088',
            'parent_distributor_id' => null,
        ]);

        Sanctum::actingAs($wholesale);
        $this->postJson('/api/v1/mobile/distributor/plumbers/link', [
            'retail_trader_id' => $retail->id,
            'code' => 'MG-P-900088',
        ])->assertForbidden();
    }
}
