<?php

namespace Tests\Feature;

use App\Services\WhatsAppBrandCard;
use Tests\TestCase;

class WhatsAppBrandCardThemeTest extends TestCase
{
    public function test_each_core_event_has_distinct_accent_and_badge(): void
    {
        $card = app(WhatsAppBrandCard::class);
        $keys = [
            'invoice_created',
            'supply_confirmed',
            'points_awarded',
            'order_created',
            'return_created',
            'return_approved',
            'return_rejected',
            'withdrawal_pending',
            'welcome',
        ];

        $signatures = [];
        foreach ($keys as $key) {
            $theme = $card->theme($key);
            $sig = implode('|', [
                implode(',', $theme['accent'] ?? []),
                $theme['badge_text'] ?? '',
                $theme['icon'] ?? '',
            ]);
            $this->assertNotContains($sig, $signatures, "Theme {$key} collided visually with another");
            $signatures[] = $sig;
        }
    }

    public function test_order_status_themes_map_and_differ(): void
    {
        $card = app(WhatsAppBrandCard::class);

        $placed = $card->themeForOrderStatus('placed');
        $shipped = $card->themeForOrderStatus('shipping');
        $cancelled = $card->themeForOrderStatus('cancelled');

        $this->assertSame('order_status_review', $placed);
        $this->assertSame('order_status_shipped', $shipped);
        $this->assertSame('order_status_cancelled', $cancelled);

        $this->assertNotSame(
            $card->theme($placed)['accent'],
            $card->theme($cancelled)['accent']
        );
    }

    public function test_asset_for_returns_jpeg_with_theme_key(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required');
        }

        $asset = app(WhatsAppBrandCard::class)->assetFor('invoice_created', [
            'hero' => '100.00',
            'hero_label' => 'الإجمالي',
            'rows' => [['رقم', 'INV-1']],
        ]);

        $this->assertIsArray($asset);
        $this->assertSame('image/jpeg', $asset['mimetype']);
        $this->assertSame('invoice_created', $asset['theme']);
        $this->assertNotEmpty($asset['base64']);
        $this->assertStringEndsWith('.jpg', $asset['filename']);
    }

    public function test_same_payload_reuses_file_for_multiple_recipients(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required');
        }

        $card = app(WhatsAppBrandCard::class);
        $payload = ['hero' => '99', 'rows' => [['A', 'B']]];
        $a = $card->assetFor('points_awarded', $payload);
        $b = $card->assetFor('points_awarded', $payload);

        $this->assertSame($a['relative'] ?? null, $b['relative'] ?? null);
    }
}
