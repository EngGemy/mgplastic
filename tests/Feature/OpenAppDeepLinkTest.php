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
            'app_links.package' => 'com.mgplastic.app',
            'app_links.android_sha256_fingerprints' => ['AA:BB'],
            'app_links.apple_team_id' => 'TEAM1',
            'app_links.apple_app_id' => '',
        ]);

        $android = $this->get('/.well-known/assetlinks.json');
        $android->assertOk();
        $android->assertJsonFragment(['package_name' => 'com.mgplastic.app']);

        $ios = $this->get('/.well-known/apple-app-site-association');
        $ios->assertOk();
        $ios->assertSee('TEAM1.com.mgplastic.app', false);
        $ios->assertSee('/open/*', false);
    }
}
