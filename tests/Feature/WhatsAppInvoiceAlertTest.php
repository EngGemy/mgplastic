<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppInvoiceAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_outgoing_wholesale_to_retail_invoice_sends_waha_to_buyer_and_admins(): void
    {
        config([
            'services.waha.url' => 'https://waha.test',
            'services.waha.key' => 'key',
            'services.waha.session' => 'session_test',
            'services.waha.enabled' => true,
            'services.waha.sync_alerts' => true,
            'services.waha.admin_phones' => ['201030003186'],
            'services.waha.default_country_code' => '218',
            'services.waha.delay_min' => 0,
            'services.waha.delay_max' => 0,
            'services.waha.notify_customers' => true,
            'services.waha.coalesce_seconds' => 0,
            'services.waha.daily_limit' => 0,
        ]);

        Http::fake([
            'https://waha.test/api/sendImage' => Http::response(['ok' => true], 200),
            'https://waha.test/api/sendText' => Http::response(['ok' => true], 200),
        ]);

        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'phone' => '0912002001',
            'name' => 'جملة تجريبي',
            'is_approved' => true,
            'is_active' => true,
        ]);

        $retailer = User::factory()->create([
            'role' => 'retail_trader',
            'phone' => '0912002002',
            'name' => 'قطاعي تجريبي',
            'is_approved' => true,
            'is_active' => true,
        ]);

        Invoice::query()->create([
            'number' => 'OUT-55',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'outgoing',
            'wholesale_distributor_id' => $wholesaler->id,
            'counterparty_user_id' => $retailer->id,
            'issued_by' => $wholesaler->id,
            'total_cents' => 0,
            'points_awarded' => 120,
            'currency' => 'LYD',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/sendImage')) {
                return false;
            }
            $caption = $request['caption'] ?? '';
            $file = $request['file'] ?? [];

            return str_contains($caption, 'فاتورة جديدة')
                && str_contains($caption, 'OUT-55')
                && str_contains($caption, '*MG للصناعات البلاستيكية*')
                && str_contains($caption, 'شكرًا لتعاملكم مع')
                && str_contains($caption, 'افتح في التطبيق')
                && str_contains($caption, '/open/invoice/')
                && str_starts_with($caption, "\u{200F}")
                && filled($file['data'] ?? null)
                && ($file['mimetype'] ?? '') === 'image/jpeg'
                && str_contains((string) ($file['filename'] ?? ''), 'invoice_created')
                && str_ends_with(strtolower((string) ($file['filename'] ?? '')), '.jpg')
                && in_array($request['chatId'] ?? '', [
                    '218912002002@c.us',
                    '218912002001@c.us',
                    '201030003186@c.us',
                ], true);
        });
    }

    public function test_send_image_falls_back_to_text_when_image_fails(): void
    {
        config([
            'services.waha.url' => 'https://waha.test',
            'services.waha.key' => 'key',
            'services.waha.session' => 'session_test',
            'services.waha.enabled' => true,
            'services.waha.sync_alerts' => true,
            'services.waha.admin_phones' => [],
            'services.waha.delay_min' => 0,
            'services.waha.delay_max' => 0,
            'services.waha.daily_limit' => 0,
            'services.waha.coalesce_seconds' => 0,
        ]);

        Http::fake([
            'https://waha.test/api/sendImage' => Http::response(['error' => 'bad file'], 400),
            'https://waha.test/api/sendText' => Http::response(['ok' => true], 200),
        ]);

        $ok = app(\App\Services\WhatsAppNotifier::class)->send(
            '0912002002',
            "MG للصناعات البلاستيكية\nاختبار",
            [
                'base64' => base64_encode('fakejpeg'),
                'mimetype' => 'image/jpeg',
                'filename' => 'x.jpg',
                // Local URL must be skipped — only base64 attempt, then text fallback
                'url' => 'http://localhost/storage/x.jpg',
            ]
        );

        $this->assertTrue($ok);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/sendImage')
            && ($r['file']['mimetype'] ?? '') === 'image/jpeg'
            && filled($r['file']['data'] ?? null)
            && ! isset($r['file']['url']));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/sendText')
            && str_starts_with($r['text'] ?? '', "\u{200F}"));
    }
}
