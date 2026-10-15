<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NetworkStoresDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_plumber_sees_retail_shops_only(): void
    {
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
            'name' => 'محل قطاعي',
        ]);
        User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
            'name' => 'محل جملة',
        ]);
        User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => false,
            'is_active' => true,
            'name' => 'قطاعي غير معتمد',
        ]);

        $plumber = User::factory()->create(['role' => 'plumber']);
        Sanctum::actingAs($plumber);

        $response = $this->getJson('/api/v1/network-stores')
            ->assertOk()
            ->assertJsonPath('meta.listed_role', 'retail_trader')
            ->assertJsonPath('meta.total', 1);

        $this->assertSame($retail->id, $response->json('data.0.id'));
        $this->assertSame('retail_trader', $response->json('data.0.role'));
    }

    public function test_retail_trader_sees_wholesale_shops_only(): void
    {
        $wholesale = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
            'name' => 'عماد جملة',
        ]);
        User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
            'name' => 'محل قطاعي',
        ]);

        $trader = User::factory()->create(['role' => 'retail_trader', 'is_approved' => true, 'is_active' => true]);
        Sanctum::actingAs($trader);

        $response = $this->getJson('/api/v1/vendors')
            ->assertOk()
            ->assertJsonPath('meta.listed_role', 'wholesale_distributor')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('message', 'قائمة محلات الجملة');

        $this->assertSame($wholesale->id, $response->json('data.0.id'));
        $this->assertSame('wholesale_distributor', $response->json('data.0.role'));
    }

    public function test_guest_defaults_to_retail_shops_for_plumber_flow(): void
    {
        User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/network-stores')
            ->assertOk()
            ->assertJsonPath('meta.listed_role', 'retail_trader')
            ->assertJsonPath('meta.total', 1);
    }
}
