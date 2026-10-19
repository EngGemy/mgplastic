<?php

namespace Tests\Unit;

use App\Support\DeepLink;
use Tests\TestCase;

class DeepLinkTest extends TestCase
{
    public function test_builds_https_open_urls_per_type(): void
    {
        config(['app_links.public_base' => 'https://mg-plastic.com']);

        $this->assertSame('https://mg-plastic.com/open/invoice/42', DeepLink::for('invoice', 42));
        $this->assertSame('https://mg-plastic.com/open/order/9', DeepLink::for('order', 9));
        $this->assertSame('https://mg-plastic.com/open/return/3', DeepLink::for('return', 3));
        $this->assertSame('https://mg-plastic.com/open/points/7', DeepLink::for('points', 7));
        $this->assertSame('https://mg-plastic.com/open/supply/11', DeepLink::for('supply', 11));
        $this->assertSame('https://mg-plastic.com/open/withdrawal/5', DeepLink::for('withdrawal', 5));
        $this->assertSame('https://mg-plastic.com/open/home/0', DeepLink::for('home'));
    }

    public function test_unknown_type_falls_back_to_home(): void
    {
        config(['app_links.public_base' => 'https://mg-plastic.com']);

        $this->assertSame('https://mg-plastic.com/open/home/1', DeepLink::for('secret-otp', 1));
    }

    public function test_custom_scheme_and_android_intent(): void
    {
        config([
            'app_links.scheme' => 'mgplastic',
            'app_links.package' => 'com.mgplastic.app',
            'app_links.public_base' => 'https://mg-plastic.com',
        ]);

        $this->assertSame('mgplastic://order/9', DeepLink::customScheme('order', 9));
        $intent = DeepLink::androidIntent('order', 9);
        $this->assertStringContainsString('intent://order/9#Intent', $intent);
        $this->assertStringContainsString('scheme=mgplastic', $intent);
        $this->assertStringContainsString('package=com.mgplastic.app', $intent);
    }
}
