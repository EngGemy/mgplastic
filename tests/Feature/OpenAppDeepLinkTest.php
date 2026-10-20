<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpenAppDeepLinkTest extends TestCase
{
    public function test_open_route_renders_bridge_page(): void
    {
        config([
            'app_links.scheme' => 'mgplastic',
            'app_links.package' => 'com.mgplastic.app',
            'app_links.public_base' => 'https://mg-plastic.com',
        ]);

        $response = $this->get('/open/invoice/42');

        $response->assertOk();
        $response->assertSee('افتح في التطبيق', false);
        $response->assertSee('https://mg-plastic.com/open/invoice/42', false);
        $response->assertSee('mgplastic://invoice/42', false);
    }

    public function test_open_home_without_id(): void
    {
        $this->get('/open/home')->assertOk();
    }

    public function test_open_rejects_unknown_type(): void
    {
        $this->get('/open/otp/123456')->assertNotFound();
    }

    public function test_well_known_asset_links_and_aasa(): void
    {
        config([
            'app_links.package' => 'com.MgTools',
            'app_links.android_sha256_fingerprints' => ['2C:C3:68:A2:7B:57:DE:E8'],
            'app_links.apple_team_id' => 'LP86Z6FUHH',
            'app_links.apple_app_id' => 'LP86Z6FUHH.com.mgPlumbers.app',
            'app_links.ios_bundle_id' => 'com.mgPlumbers.app',
        ]);

        $android = $this->get('/.well-known/assetlinks.json');
        $android->assertOk();
        $android->assertJsonFragment(['package_name' => 'com.MgTools']);

        $ios = $this->get('/.well-known/apple-app-site-association');
        $ios->assertOk();
        $ios->assertSee('LP86Z6FUHH.com.mgPlumbers.app', false);
        $ios->assertSee('/open/*', false);
        $ios->assertDontSee('com.MgTools', false);
    }

    public function test_aasa_fallback_uses_ios_bundle_not_android_package(): void
    {
        config([
            'app_links.package' => 'com.MgTools',
            'app_links.apple_team_id' => 'LP86Z6FUHH',
            'app_links.apple_app_id' => '',
            'app_links.ios_bundle_id' => 'com.mgPlumbers.app',
        ]);

        $this->get('/.well-known/apple-app-site-association')
            ->assertOk()
            ->assertSee('LP86Z6FUHH.com.mgPlumbers.app', false)
            ->assertDontSee('LP86Z6FUHH.com.MgTools', false);
    }
}
