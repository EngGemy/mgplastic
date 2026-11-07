<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\HomePopup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HomePopupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function makePopup(array $overrides = []): HomePopup
    {
        return HomePopup::query()->create(array_merge([
            'title' => 'إعلان تجريبي',
            'subtitle' => 'وصف',
            'image_path' => 'home-popups/test.jpg',
            'kind' => 'promo',
            'cta_text' => 'اضغط هنا',
            'link_type' => 'home',
            'target_roles' => ['guest'],
            'frequency' => 'once',
            'dismissible' => true,
            'sort_order' => 10,
            'is_active' => true,
        ], $overrides));
    }

    public function test_guest_without_token_gets_guest_popup(): void
    {
        $this->makePopup([
            'cta_text' => 'سجّل عندنا',
            'link_type' => 'auth_register',
            'kind' => 'auth',
            'target_roles' => ['guest'],
        ]);

        $this->getJson('/api/v1/mobile/home/popup')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.cta.text', 'سجّل عندنا')
            ->assertJsonPath('data.cta.link', 'mgplastic://auth/register')
            ->assertJsonPath('data.kind', 'auth')
            ->assertJsonPath('data.frequency', 'once');
    }

    public function test_returns_null_when_no_active_popup(): void
    {
        $this->getJson('/api/v1/mobile/home/popup')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data', null)
            ->assertJsonPath('message', 'لا يوجد إعلان');
    }

    public function test_inactive_or_expired_popup_returns_null(): void
    {
        $this->makePopup([
            'is_active' => false,
            'target_roles' => ['guest'],
        ]);

        $this->makePopup([
            'is_active' => true,
            'target_roles' => ['guest'],
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
            'sort_order' => 99,
        ]);

        $this->getJson('/api/v1/mobile/home/popup')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_role_filtering_hides_other_roles(): void
    {
        $this->makePopup([
            'title' => 'للسباك فقط',
            'cta_text' => 'محفظتي',
            'link_type' => 'wallet',
            'target_roles' => ['plumber'],
            'sort_order' => 50,
        ]);

        $this->getJson('/api/v1/mobile/home/popup')
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->getJson('/api/v1/mobile/home/popup?role=plumber')
            ->assertOk()
            ->assertJsonPath('data.cta.text', 'محفظتي')
            ->assertJsonPath('data.cta.link', 'mgplastic://wallet');
    }

    public function test_authenticated_plumber_ignores_role_query(): void
    {
        $this->makePopup([
            'cta_text' => 'ضيف فقط',
            'link_type' => 'auth_login',
            'target_roles' => ['guest'],
            'sort_order' => 100,
        ]);

        $this->makePopup([
            'cta_text' => 'عرض السباك',
            'link_type' => 'plumber',
            'link_id' => 55,
            'kind' => 'feature',
            'target_roles' => ['plumber'],
            'sort_order' => 10,
        ]);

        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_approved' => true,
            'is_active' => true,
        ]);
        Sanctum::actingAs($plumber);

        $this->getJson('/api/v1/mobile/home/popup?role=guest')
            ->assertOk()
            ->assertJsonPath('data.cta.text', 'عرض السباك')
            ->assertJsonPath('data.cta.link', 'mgplastic://plumber/55');
    }

    public function test_highest_sort_order_wins_for_same_role(): void
    {
        $this->makePopup([
            'cta_text' => 'الأقل',
            'sort_order' => 1,
            'target_roles' => ['guest'],
        ]);
        $this->makePopup([
            'cta_text' => 'الأعلى',
            'sort_order' => 20,
            'target_roles' => ['guest'],
        ]);

        $this->getJson('/api/v1/mobile/home/popup')
            ->assertOk()
            ->assertJsonPath('data.cta.text', 'الأعلى');
    }

    public function test_settings_exposes_home_popup_url(): void
    {
        $this->getJson('/api/v1/mobile/settings')
            ->assertOk()
            ->assertJsonPath('data.home.popup', url('/api/v1/mobile/home/popup'));
    }

    public function test_shop_and_deep_link_resolvers(): void
    {
        $shop = $this->makePopup([
            'cta_text' => 'زيارة المحل',
            'link_type' => 'shop',
            'link_id' => 90,
            'target_roles' => ['guest', 'plumber'],
        ]);

        $this->assertSame('mgplastic://shop/90', $shop->resolveCtaLink());

        $deep = $this->makePopup([
            'cta_text' => 'قائمة المحلات',
            'link_type' => 'deep_link',
            'link_path' => 'list/shops',
            'target_roles' => ['guest'],
            'is_active' => false,
        ]);

        $this->assertSame('mgplastic://list/shops', $deep->resolveCtaLink());
    }
}
