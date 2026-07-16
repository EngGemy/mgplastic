<?php

namespace Tests\Unit;

use App\Support\SocialLinksPayload;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SocialLinksPayloadTest extends TestCase
{
    #[Test]
    public function it_normalizes_links_array(): void
    {
        $request = Request::create('/x', 'POST', [
            'links' => [
                ['platform' => 'facebook', 'url' => 'https://facebook.com/a'],
                ['platform' => 'instagram', 'url' => ''],
                ['platform' => 'whatsapp', 'url' => '0912345678'],
            ],
        ]);

        $links = SocialLinksPayload::normalize($request);

        $this->assertCount(2, $links);
        $this->assertSame('facebook', $links[0]['platform']);
        $this->assertSame('whatsapp', $links[1]['platform']);
        $this->assertSame('https://wa.me/0912345678', $links[1]['url']);
    }

    #[Test]
    public function it_accepts_json_string_links(): void
    {
        $request = Request::create('/x', 'POST', [
            'links' => json_encode([
                ['platform' => 'fb', 'url' => 'facebook.com/shop'],
                ['platform' => 'ig', 'link' => '@plumber'],
            ]),
        ]);

        $links = SocialLinksPayload::normalize($request);

        $this->assertCount(2, $links);
        $this->assertSame('facebook', $links[0]['platform']);
        $this->assertSame('https://facebook.com/shop', $links[0]['url']);
        $this->assertSame('instagram', $links[1]['platform']);
        $this->assertSame('https://instagram.com/plumber', $links[1]['url']);
    }

    #[Test]
    public function it_accepts_platform_map_and_single_root(): void
    {
        $map = Request::create('/x', 'POST', [
            'facebook' => 'https://facebook.com/x',
            'whatsapp' => '0911111111',
            'instagram' => '',
        ]);
        $this->assertCount(2, SocialLinksPayload::normalize($map));

        $single = Request::create('/x', 'POST', [
            'platform' => 'youtube',
            'url' => 'youtube.com/@chan',
        ]);
        $links = SocialLinksPayload::normalize($single);
        $this->assertCount(1, $links);
        $this->assertSame('youtube', $links[0]['platform']);
    }

    #[Test]
    public function it_reads_nested_data_links(): void
    {
        $request = Request::create('/x', 'POST', [
            'data' => [
                'links' => [
                    ['type' => 'tiktok', 'value' => '@shop'],
                ],
            ],
        ]);

        $links = SocialLinksPayload::normalize($request);
        $this->assertCount(1, $links);
        $this->assertSame('tiktok', $links[0]['platform']);
        $this->assertSame('https://tiktok.com/@shop', $links[0]['url']);
    }
}
