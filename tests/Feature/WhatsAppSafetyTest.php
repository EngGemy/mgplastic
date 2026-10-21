<?php

namespace Tests\Feature;

use App\Mail\WhatsAppLimitReachedMail;
use App\Mail\WhatsAppSessionAlertMail;
use App\Models\User;
use App\Models\WhatsAppLog;
use App\Services\PhoneOtpService;
use App\Services\WhatsAppBrandCard;
use App\Services\WhatsAppBusinessAlerts;
use App\Services\WhatsAppNotifier;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WhatsAppSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function enableWaha(array $extra = []): void
    {
        config(array_merge([
            'services.waha.url' => 'https://waha.test',
            'services.waha.key' => 'secret-key',
            'services.waha.session' => 'session_test_abc',
            'services.waha.enabled' => true,
            'services.waha.sync_alerts' => true,
            'services.waha.admin_phones' => ['201030003186'],
            'services.waha.default_country_code' => '218',
            'services.waha.delay_min' => 0,
            'services.waha.delay_max' => 0,
            'services.waha.daily_limit' => 50,
            'services.waha.notify_customers' => true,
            'services.waha.coalesce_seconds' => 60,
            'services.waha.alert_emails' => ['ops@example.com'],
            'services.otp.channels' => 'whatsapp',
        ], $extra));
    }

    public function test_daily_limit_blocks_alerts_but_not_otp(): void
    {
        $this->enableWaha(['services.waha.daily_limit' => 2]);
        Mail::fake();
        Http::fake(['https://waha.test/*' => Http::response(['ok' => true], 200)]);

        WhatsAppLog::record([
            'event' => 'x', 'phone' => '218911111111', 'recipient_type' => 'customer',
            'status' => 'sent', 'channel' => 'text', 'created_at' => now(),
        ]);
        WhatsAppLog::record([
            'event' => 'y', 'phone' => '218911111112', 'recipient_type' => 'admin',
            'status' => 'sent', 'channel' => 'text', 'created_at' => now(),
        ]);

        app(WhatsAppNotifier::class)->sendMany([[
            'phone' => '0912002002',
            'text' => 'تنبيه',
            'event' => 'invoice_created',
            'recipient_type' => 'customer',
            'reference_type' => 'invoice',
            'reference_id' => 1,
        ]]);

        $this->assertTrue(
            WhatsAppLog::query()->where('status', 'skipped_limit')->exists()
        );
        Mail::assertSent(WhatsAppLimitReachedMail::class);

        $user = User::factory()->create(['phone' => '0916126065']);
        app(PhoneOtpService::class)->issue($user, 'login', 'whatsapp');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/sendText')
            && ($r['session'] ?? '') === 'session_test_abc');
    }

    public function test_notify_customers_false_admins_only(): void
    {
        $this->enableWaha(['services.waha.notify_customers' => false]);
        Http::fake(['https://waha.test/*' => Http::response(['ok' => true], 200)]);

        app(WhatsAppNotifier::class)->notifyUsersAndAdmins(
            ['0912002002'],
            'مرحبا',
            null,
            ['event' => 'test', 'reference_type' => 'order', 'reference_id' => 9]
        );

        Http::assertSent(fn ($r) => ($r['chatId'] ?? '') === '201030003186@c.us');
        Http::assertNotSent(fn ($r) => ($r['chatId'] ?? '') === '218912002002@c.us');
    }

    public function test_image_generated_once_captions_differ(): void
    {
        $this->enableWaha(['services.waha.coalesce_seconds' => 0]);
        Http::fake(['https://waha.test/*' => Http::response(['ok' => true], 200)]);

        $asset = app(WhatsAppBrandCard::class)->assetFor('invoice_created', [
            'hero' => Money::fromCents(125000),
            'hero_label' => 'الإجمالي',
            'rows' => [['رقم', 'OUT-77']],
        ]);
        $this->assertNotNull($asset);

        $customerText = "أهلاً *عميل*\nفاتورة";
        $adminText = "فاتورة جديدة\nجملة ← قطاعي";

        app(WhatsAppNotifier::class)->sendMany([
            [
                'phone' => '0912002002',
                'text' => $customerText,
                'image' => $asset,
                'event' => 'invoice_created',
                'recipient_type' => 'customer',
                'reference_type' => 'invoice',
                'reference_id' => 77,
            ],
            [
                'phone' => '201030003186',
                'text' => $adminText,
                'image' => $asset,
                'event' => 'invoice_created',
                'recipient_type' => 'admin',
                'reference_type' => 'invoice',
                'reference_id' => 77,
            ],
        ]);

        $imageCalls = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_contains($r->url(), 'sendImage'));

        $this->assertGreaterThanOrEqual(2, $imageCalls->count());
        $this->assertCount(1, $imageCalls->map(fn ($r) => $r['file']['filename'] ?? '')->unique()->filter());
        $captions = $imageCalls->map(fn ($r) => $r['caption'] ?? '')->all();
        $this->assertNotSame($captions[0] ?? null, $captions[1] ?? null);
    }

    public function test_fallback_text_logged(): void
    {
        $this->enableWaha();
        Http::fake([
            'https://waha.test/api/sendImage' => Http::response(['error' => 'bad'], 400),
            'https://waha.test/api/sendText' => Http::response(['ok' => true], 200),
        ]);

        $ok = app(WhatsAppNotifier::class)->deliver([[
            'phone' => '0912002002',
            'text' => 'اختبار',
            'image' => [
                'base64' => base64_encode('x'),
                'mimetype' => 'image/jpeg',
                'filename' => 't.jpg',
            ],
            'event' => 'test',
            'recipient_type' => 'customer',
            'reference_type' => 'order',
            'reference_id' => 1,
        ]]);

        $this->assertTrue($ok);
        $this->assertTrue(WhatsAppLog::query()->where('status', 'fallback_text')->exists());
    }

    public function test_coalesce_skips_second_related_alert(): void
    {
        $this->enableWaha(['services.waha.coalesce_seconds' => 60]);
        Cache::flush();
        Http::fake(['https://waha.test/*' => Http::response(['ok' => true], 200)]);

        $msg = [
            'phone' => '0912002002',
            'text' => 'أ',
            'event' => 'order_status',
            'recipient_type' => 'customer',
            'reference_type' => 'order',
            'reference_id' => 55,
        ];
        app(WhatsAppNotifier::class)->deliver([$msg]);
        app(WhatsAppNotifier::class)->deliver([[...$msg, 'text' => 'ب']]);

        $sent = collect(Http::recorded())->filter(fn ($r) => str_contains($r[0]->url(), 'sendText') || str_contains($r[0]->url(), 'sendImage'));
        $this->assertSame(1, $sent->count());
    }

    public function test_health_alerts_once_then_restores(): void
    {
        $this->enableWaha();
        Mail::fake();
        Cache::flush();

        Http::fake([
            'https://waha.test/api/sessions/session_test_abc' => Http::sequence()
                ->push(['status' => 'FAILED'], 200)
                ->push(['status' => 'FAILED'], 200)
                ->push(['status' => 'WORKING'], 200),
        ]);

        $this->artisan('whatsapp:health')->assertSuccessful();
        Mail::assertSent(WhatsAppSessionAlertMail::class, fn ($m) => $m->kind === 'down');

        $this->artisan('whatsapp:health')->assertSuccessful();
        Mail::assertSent(WhatsAppSessionAlertMail::class, 1);

        $this->artisan('whatsapp:health')->assertSuccessful();
        Mail::assertSent(WhatsAppSessionAlertMail::class, fn ($m) => $m->kind === 'restored');
    }

    public function test_session_never_hardcoded_default_in_payload(): void
    {
        $this->enableWaha(['services.waha.session' => 'session_01m5chp9twqwdrvjxj35jne7a3']);
        Http::fake(['https://waha.test/*' => Http::response(['ok' => true], 200)]);

        app(WhatsAppNotifier::class)->send('0912159316', 'مرحبا');

        Http::assertSent(fn ($r) => ($r['session'] ?? null) === 'session_01m5chp9twqwdrvjxj35jne7a3'
            && ($r['session'] ?? null) !== 'default');
    }

    public function test_money_helper_used_in_invoice_caption(): void
    {
        $this->assertSame('1,250.00 د.ل', Money::fromCents(125000));
    }
}
