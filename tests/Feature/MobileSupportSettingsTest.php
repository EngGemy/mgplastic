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
            'support_phone' => '0916126065',
            'support_whatsapp' => '0916126065',
        ]);

        $this->getJson('/api/v1/mobile/settings')
            ->assertOk()
            ->assertJsonPath('data.support.phone', '0916126065')
            ->assertJsonPath('data.support.phone_url', 'tel:+218916126065')
            ->assertJsonPath('data.support.whatsapp', '0916126065')
            ->assertJsonPath('data.support.whatsapp_url', 'https://wa.me/218916126065');

        $settings->update([
            'support_phone' => '',
            'support_whatsapp' => null,
        ]);

        $this->getJson('/api/v1/mobile/settings')
            ->assertOk()
            ->assertJsonPath('data.support.phone', null)
            ->assertJsonPath('data.support.phone_url', null)
            ->assertJsonPath('data.support.whatsapp', null)
            ->assertJsonPath('data.support.whatsapp_url', null);
    }
}
