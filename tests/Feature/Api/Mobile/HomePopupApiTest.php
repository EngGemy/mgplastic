<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\HomePopup;
use App\Models\HomePopupEvent;
use App\Models\User;
use App\Services\HomeMobileLayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HomePopupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function makePopup(array $overrides = []): HomePopup
    {
        return HomePopup::query()->create(array_merge([
            'version' => 1,
            'title_ar' => 'إعلان تجريبي',
            'title_en' => 'Test popup',
            'body_ar' => 'وصف',
            'body_en' => 'Body',
            'image_path' => 'home-popups/test.jpg',
            'image_ratio' => 0.8,
            'kind' => 'announcement',
            'buttons' => [
                [
                    'id' => 'cta',
                    'text_ar' => 'اضغط هنا',
                    'text_en' => 'Tap here',
                    'link_type' => 'home',
                    'style' => 'primary',
                ],
            ],
            'target_roles' => ['guest'],
            'show' => 'once',
            'dismissible' => true,
            'dismiss_text_ar' => 'لاحقاً',
            'dismiss_text_en' => 'Later',
            'sort_order' => 10,
            'is_active' => true,
        ], $overrides));
    }

    public function test_layout_embeds_popup_for_guest(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        $this->makePopup([
            'title_ar' => 'سجّل عندنا',
            'buttons' => [[
                'id' => 'register',
                'text_ar' => 'سجّل عندنا',
                'text_en' => 'Sign up',
                'link_type' => 'register',
                'style' => 'primary',
            ]],
            'target_roles' => ['guest'],
        ]);

        $this->getJson('/api/v1/mobile/home/layout', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.role', 'guest')
            ->assertJsonPath('data.popup.kind', 'announcement')
            ->assertJsonPath('data.popup.title', 'سجّل عندنا')
            ->assertJsonPath('data.popup.buttons.0.link', 'mgplastic://register')
            ->assertJsonPath('data.popup.show', 'once')
            ->assertJsonPath('data.popup.version', 1);
    }

    public function test_layout_popup_null_when_inactive(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        $this->makePopup(['is_active' => false]);

        $this->getJson('/api/v1/mobile/home/layout')
            ->assertOk()
            ->assertJsonPath('data.popup', null);
    }

    public function test_english_locale_picks_english_copy(): void
    {
        $this->makePopup([
            'title_ar' => 'عربي',
            'title_en' => 'English title',
            'buttons' => [[
                'id' => 'go',
                'text_ar' => 'اذهب',
                'text_en' => 'Go',
                'link_type' => 'home',
                'style' => 'primary',
            ]],
        ]);

        $this->getJson('/api/v1/mobile/home/popup', ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.title', 'English title')
            ->assertJsonPath('data.buttons.0.text', 'Go');
    }

    public function test_each_role_gets_own_popup(): void
    {
        $this->makePopup([
            'title_ar' => 'ضيف',
            'buttons' => [[
                'id' => 'reg',
                'text_ar' => 'سجّل',
                'link_type' => 'register',
                'style' => 'primary',
            ]],
            'target_roles' => ['guest'],
        ]);

        $plumberUser = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'is_approved' => true,
        ]);

        $this->makePopup([
            'title_ar' => 'سباك',
            'buttons' => [[
                'id' => 'visit',
                'text_ar' => 'زور السباك',
                'link_type' => 'plumber',
                'link_id' => $plumberUser->id,
                'style' => 'primary',
            ]],
            'target_roles' => ['plumber'],
            'sort_order' => 5,
        ]);

        $this->getJson('/api/v1/mobile/home/popup', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.title', 'ضيف')
            ->assertJsonPath('data.buttons.0.link', 'mgplastic://register');

        Sanctum::actingAs($plumberUser);

        $this->getJson('/api/v1/mobile/home/popup', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.title', 'سباك')
            ->assertJsonPath('data.buttons.0.link', 'mgplastic://plumber/'.$plumberUser->id);
    }

    public function test_poll_buttons_can_have_null_links(): void
    {
        $this->makePopup([
            'kind' => 'poll',
            'dismissible' => false,
            'show' => 'always',
            'buttons' => [
                ['id' => 'yes', 'text_ar' => 'نعم', 'link_type' => 'none', 'style' => 'primary'],
                ['id' => 'no', 'text_ar' => 'لا', 'link_type' => 'none', 'style' => 'outline'],
            ],
        ]);

        $this->getJson('/api/v1/mobile/home/popup')
            ->assertOk()
            ->assertJsonPath('data.kind', 'poll')
            ->assertJsonPath('data.dismissible', false)
            ->assertJsonPath('data.buttons.0.link', null)
            ->assertJsonPath('data.buttons.1.id', 'no');
    }

    public function test_hides_guest_blocked_links(): void
    {
        $this->makePopup([
            'buttons' => [
                ['id' => 'ok', 'text_ar' => 'كتالوج', 'link_type' => 'catalog', 'style' => 'primary'],
                ['id' => 'bad', 'text_ar' => 'محفظة', 'link_type' => 'wallet', 'style' => 'outline'],
            ],
        ]);

        $buttons = $this->getJson('/api/v1/mobile/home/popup')->json('data.buttons');
        $this->assertCount(1, $buttons);
        $this->assertSame('ok', $buttons[0]['id']);
        $this->assertSame('mgplastic://catalog', $buttons[0]['link']);
    }

    public function test_vendor_query_role_maps_to_retail_trader(): void
    {
        $this->makePopup([
            'title_ar' => 'للقطاعي',
            'target_roles' => ['retail_trader'],
            'buttons' => [[
                'id' => 'home',
                'text_ar' => 'حسناً',
                'link_type' => 'home',
                'style' => 'primary',
            ]],
        ]);

        $this->getJson('/api/v1/mobile/home/popup?role=vendor', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.title', 'للقطاعي');
    }

    public function test_event_endpoint_records_view_click_dismiss_without_auth(): void
    {
        $popup = $this->makePopup();

        $this->postJson('/api/v1/mobile/home/popup/'.$popup->id.'/event', [
            'event' => 'view',
            'version' => 1,
            'device_id' => 'device-abc',
        ])->assertNoContent();

        $this->postJson('/api/v1/mobile/home/popup/'.$popup->id.'/event', [
            'event' => 'click',
            'button_id' => 'cta',
            'version' => 1,
            'device_id' => 'device-abc',
        ])->assertNoContent();

        $this->postJson('/api/v1/mobile/home/popup/'.$popup->id.'/event', [
            'event' => 'dismiss',
            'device_id' => 'device-abc',
        ])->assertNoContent();

        $this->assertSame(3, HomePopupEvent::query()->where('home_popup_id', $popup->id)->count());
        $this->assertSame(1, $popup->statsSummary()['views']);
        $this->assertSame(1, $popup->statsSummary()['clicks']);
        $this->assertArrayHasKey('cta', $popup->statsSummary()['by_button']);
    }

    public function test_settings_exposes_popup_and_event_urls(): void
    {
        $this->getJson('/api/v1/mobile/settings')
            ->assertOk()
            ->assertJsonPath('data.home.popup', url('/api/v1/mobile/home/popup'))
            ->assertJsonPath('data.home.popup_event', url('/api/v1/mobile/home/popup/{id}/event'));
    }

    public function test_dead_plumber_button_is_stripped(): void
    {
        $this->makePopup([
            'buttons' => [[
                'id' => 'gone',
                'text_ar' => 'سباك محذوف',
                'link_type' => 'plumber',
                'link_id' => 999999,
                'style' => 'primary',
            ]],
        ]);

        $this->getJson('/api/v1/mobile/home/popup')
            ->assertOk()
            ->assertJsonPath('data.buttons', []);
    }
}
