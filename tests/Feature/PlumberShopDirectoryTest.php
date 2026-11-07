<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlumberShopDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_plumber_shops_sort_nearest_and_search_by_name(): void
    {
        $near = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
            'brand_name' => 'محل قريب',
            'latitude' => 32.8872,
            'longitude' => 13.1913,
        ]);
        $far = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
            'brand_name' => 'محل بعيد',
            'latitude' => 32.9500,
            'longitude' => 13.3000,
        ]);
        User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
            'brand_name' => 'جملة ممنوع',
            'latitude' => 32.8870,
            'longitude' => 13.1910,
        ]);

        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_approved' => true,
            'is_active' => true,
            'latitude' => 32.8870,
            'longitude' => 13.1910,
        ]);
        Sanctum::actingAs($plumber);

        $res = $this->getJson('/api/v1/mobile/plumber/shops?sort=nearest')
            ->assertOk()
            ->assertJsonPath('meta.sort', 'nearest')
            ->assertJsonPath('meta.origin.source', 'profile')
            ->assertJsonPath('meta.listed_role', 'retail_trader');

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertSame([$near->id, $far->id], $ids);
        $this->assertNotNull($res->json('data.0.distance_km'));
        $this->assertTrue($res->json('data.0.distance_km') <= $res->json('data.1.distance_km'));

        $this->getJson('/api/v1/mobile/plumber/shops?q=بعيد')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $far->id);
    }

    public function test_plumber_can_favorite_and_filter_favorites(): void
    {
        $shop = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
            'brand_name' => 'مفضلتي',
            'latitude' => 32.88,
            'longitude' => 13.19,
        ]);
        User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
            'brand_name' => 'عادي',
        ]);

        $plumber = User::factory()->create(['role' => 'plumber', 'is_approved' => true, 'is_active' => true]);
        Sanctum::actingAs($plumber);

        $this->postJson('/api/v1/mobile/plumber/favorite-stores/'.$shop->id)
            ->assertOk()
            ->assertJsonPath('data.is_favorite', true)
            ->assertJsonPath('data.store.is_favorite', true);

        $this->getJson('/api/v1/mobile/plumber/shops?favorites_only=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $shop->id)
            ->assertJsonPath('data.0.is_favorite', true);

        $this->getJson('/api/v1/mobile/plumber/favorite-stores')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.items.0.id', $shop->id);

        $this->deleteJson('/api/v1/mobile/plumber/favorite-stores/'.$shop->id)
            ->assertOk()
            ->assertJsonPath('data.is_favorite', false);

        $this->getJson('/api/v1/mobile/plumber/shops?favorites_only=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_query_lat_lng_overrides_profile_for_nearest(): void
    {
        $a = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
            'brand_name' => 'A',
            'latitude' => 32.90,
            'longitude' => 13.20,
        ]);
        $b = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
            'brand_name' => 'B',
            'latitude' => 32.80,
            'longitude' => 13.10,
        ]);

        $plumber = User::factory()->create([
            'role' => 'plumber',
            'latitude' => 32.90,
            'longitude' => 13.20,
        ]);
        Sanctum::actingAs($plumber);

        // Near B
        $res = $this->getJson('/api/v1/mobile/plumber/shops?sort=nearest&lat=32.80&lng=13.10')
            ->assertOk()
            ->assertJsonPath('meta.origin.source', 'query');

        $this->assertSame([$b->id, $a->id], collect($res->json('data'))->pluck('id')->all());
    }
}
