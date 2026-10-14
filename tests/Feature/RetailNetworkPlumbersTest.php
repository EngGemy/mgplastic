<?php

namespace Tests\Feature;

use App\Filament\Distributor\Resources\DistributorRetailTraderResource;
use App\Models\User;
use App\Services\RetailNetworkLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetailNetworkPlumbersTest extends TestCase
{
    use RefreshDatabase;

    public function test_linking_a_retail_trader_puts_their_plumbers_in_the_wholesaler_network(): void
    {
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $other = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
            'parent_distributor_id' => $other->id,
            'network_code' => 'MG-R-000127',
        ]);
        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'parent_distributor_id' => $retail->id,
        ]);

        $service = app(RetailNetworkLinkService::class);
        $service->linkByCode($wholesaler, 'MG-R-000127', $wholesaler);

        $this->assertTrue($service->isLinked($wholesaler, $retail->fresh()));
        $this->assertContains($retail->id, $service->linkedRetailerIds($wholesaler));
        $this->assertTrue(
            User::query()
                ->where('role', 'plumber')
                ->whereKey($plumber->id)
                ->whereIn('parent_distributor_id', $service->linkedRetailerIds($wholesaler))
                ->exists()
        );
        $this->assertSame($other->id, $retail->fresh()->parent_distributor_id);

        $this->actingAs($wholesaler);
        $visibleIds = DistributorRetailTraderResource::getEloquentQuery()->pluck('id')->all();
        $this->assertContains($retail->id, $visibleIds);
    }
}
