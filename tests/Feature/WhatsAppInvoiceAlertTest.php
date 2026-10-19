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
            'services.waha.session' => 'default',
            'services.waha.enabled' => true,
            'services.waha.sync_alerts' => true,
            'services.waha.admin_phones' => ['201030003186'],
            'services.waha.default_country_code' => '218',
        ]);

        Http::fake([
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
            return $request->url() === 'https://waha.test/api/sendText'
                && str_contains($request['text'] ?? '', 'فاتورة جديدة')
                && str_contains($request['text'] ?? '', 'OUT-55')
                && in_array($request['chatId'] ?? '', [
                    '218912002002@c.us', // retailer
                    '218912002001@c.us', // wholesaler
                    '201030003186@c.us', // admin
                ], true);
        });
    }
}
