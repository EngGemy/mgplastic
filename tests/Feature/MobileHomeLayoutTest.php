<?php

namespace Tests\Feature;

use App\Models\HomeLayoutSection;
use App\Models\HomeMobileSetting;
use App\Models\User;
use App\Services\HomeMobileLayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileHomeLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_layout_returns_version_and_sections_for_guest(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        $this->getJson('/api/v1/mobile/home/layout')
            ->assertOk()
            ->assertJsonPath('data.role', 'guest')
            ->assertJsonStructure([
                'data' => [
                    'version',
                    'role',
                    'sections',
                    'banner' => ['title', 'cta_text', 'is_active'],
                    'titles' => ['main_sections', 'new_products', 'stores'],
                    'product_types',
                    'highlights',
                ],
            ]);

        $sections = $this->getJson('/api/v1/mobile/home/layout')->json('data.sections');
        $this->assertIsArray($sections);
        $this->assertNotEmpty($sections);
        $this->assertArrayHasKey('key', $sections[0]);
        $this->assertArrayHasKey('type', $sections[0]);
    }

    public function test_authenticated_plumber_gets_plumber_sections(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        $plumber = User::factory()->create(['role' => 'plumber', 'is_approved' => true, 'is_active' => true]);
        Sanctum::actingAs($plumber);

        $res = $this->getJson('/api/v1/mobile/home/layout')
            ->assertOk()
            ->assertJsonPath('data.role', 'plumber');

        $types = collect($res->json('data.sections'))->pluck('type')->all();
        $this->assertContains('quick_actions', $types);
        $this->assertContains('reputation', $types);
        $this->assertNotContains('shop_quick_links', $types);
    }

    public function test_retail_trader_sees_featured_wholesalers_not_quick_actions(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        $trader = User::factory()->create(['role' => 'retail_trader', 'is_approved' => true, 'is_active' => true]);
        Sanctum::actingAs($trader);

        $types = collect($this->getJson('/api/v1/mobile/home/layout')->json('data.sections'))->pluck('type');
        $this->assertTrue($types->contains('featured_wholesalers') || $types->contains('shop_quick_links'));
        $this->assertFalse($types->contains('quick_actions'));
    }

    public function test_disabling_section_removes_it_from_layout(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        HomeLayoutSection::query()->where('key', 'events')->update(['is_active' => false]);
        app(HomeMobileLayoutService::class)->forgetCache();

        $types = collect($this->getJson('/api/v1/mobile/home/layout?role=plumber')->json('data.sections'))
            ->pluck('type');

        $this->assertFalse($types->contains('events'));
    }

    public function test_bump_version_on_settings_change_path(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();
        $before = (int) HomeMobileSetting::instance()->layout_version;
        $after = app(HomeMobileLayoutService::class)->bumpVersion();
        $this->assertSame($before + 1, $after);
    }

    public function test_settings_exposes_home_layout_url(): void
    {
        $this->getJson('/api/v1/mobile/settings')
            ->assertOk()
            ->assertJsonPath('data.home.layout', url('/api/v1/mobile/home/layout'));
    }

    public function test_app_sourced_sections_have_null_items(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        $wallet = collect($this->getJson('/api/v1/mobile/home/layout?role=plumber')->json('data.sections'))
            ->firstWhere('type', 'wallet');

        $this->assertNotNull($wallet);
        $this->assertNull($wallet['items']);
    }

    public function test_plumber_quick_actions_match_sample_keys(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        $section = collect($this->getJson('/api/v1/mobile/home/layout?role=plumber')->json('data.sections'))
            ->firstWhere('type', 'quick_actions');

        $this->assertNotNull($section);
        $keys = collect($section['items'])->pluck('key')->all();
        $this->assertSame(['orders', 'works', 'notifications', 'support'], $keys);
    }

    public function test_guest_starts_with_search_not_wallet(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        $types = collect($this->getJson('/api/v1/mobile/home/layout')->json('data.sections'))
            ->pluck('type')
            ->all();

        $this->assertSame('search', $types[0] ?? null);
        $this->assertNotContains('wallet', $types);
    }

    public function test_plumber_wallet_comes_before_search(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        $types = collect($this->getJson('/api/v1/mobile/home/layout?role=plumber')->json('data.sections'))
            ->pluck('type')
            ->all();

        $wallet = array_search('wallet', $types, true);
        $search = array_search('search', $types, true);

        $this->assertNotFalse($wallet);
        $this->assertNotFalse($search);
        $this->assertLessThan($search, $wallet);
    }

    public function test_trader_shop_links_include_sample_featured_cards(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        $trader = User::factory()->create(['role' => 'retail_trader', 'is_approved' => true, 'is_active' => true]);
        Sanctum::actingAs($trader);

        $section = collect($this->getJson('/api/v1/mobile/home/layout')->json('data.sections'))
            ->firstWhere('type', 'shop_quick_links');

        $this->assertNotNull($section);
        $keys = collect($section['items'])->pluck('key');
        $this->assertTrue($keys->contains('myPlumbers'));
        $this->assertTrue($keys->contains('orderWholesale'));
        $featured = collect($section['items'])->where('is_featured', true);
        $this->assertGreaterThanOrEqual(4, $featured->count());
    }

    public function test_new_products_include_media_type(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        // Without promotions DB rows the section may be omitted — ensure mapper shape when present.
        \App\Models\ProductPromotion::query()->create([
            'title' => 'عرض تجريبي',
            'subtitle' => 'اختبار',
            'media_type' => 'video',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        app(HomeMobileLayoutService::class)->forgetCache();

        $section = collect($this->getJson('/api/v1/mobile/home/layout?role=guest')->json('data.sections'))
            ->firstWhere('type', 'new_products');

        $this->assertNotNull($section);
        $this->assertSame('video', $section['items'][0]['media_type'] ?? null);
    }
}
