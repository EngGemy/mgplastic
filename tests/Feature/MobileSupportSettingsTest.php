<?php

namespace Tests\Feature;

use App\Models\WebsiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileSupportSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_settings_include_support_phone_and_whatsapp(): void
    {
        $settings = WebsiteSetting::instance();
        $settings->update([
            'contact_phone' => '0916126065',
            'contact_whatsapp' => '0922159316',
            'support_phone' => null,
            'support_whatsapp' => null,
        ]);

        $this->getJson('/api/v1/mobile/settings')
            ->assertOk()
            ->assertJsonPath('data.support.phone', '0916126065')
            ->assertJsonPath('data.support.phone_url', 'tel:+218916126065')
            ->assertJsonPath('data.support.whatsapp', '0922159316')
            ->assertJsonPath('data.support.whatsapp_url', 'https://wa.me/218922159316');

        $settings->update([
            'contact_phone' => '0919842523',
            'contact_whatsapp' => '',
        ]);

        $this->getJson('/api/v1/mobile/settings')
            ->assertOk()
            ->assertJsonPath('data.support.phone', '0919842523')
            ->assertJsonPath('data.support.whatsapp', '0919842523')
            ->assertJsonPath('data.support.whatsapp_url', 'https://wa.me/218919842523');

        $settings->update([
            'contact_phone' => '',
            'contact_whatsapp' => null,
        ]);

        $this->getJson('/api/v1/mobile/settings')
            ->assertOk()
            ->assertJsonPath('data.support.phone', null)
            ->assertJsonPath('data.support.phone_url', null)
            ->assertJsonPath('data.support.whatsapp', null)
            ->assertJsonPath('data.support.whatsapp_url', null);
    }
}
