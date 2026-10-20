<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotification;
use App\Models\Invoice;
use App\Models\User;
use App\Services\PhoneOtpService;
use App\Services\WhatsAppNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WhatsAppNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function enableWaha(array $extra = []): void
    {
        config(array_merge([
            'services.waha.url' => 'https://waha.test',
            'services.waha.key' => 'test-api-key',
            'services.waha.session' => 'session_test',
            'services.waha.enabled' => true,
            'services.waha.admin_phones' => ['01012345678', '201098765432'],
            'services.waha.default_country_code' => '218',
            'services.waha.sync_alerts' => false,
            'services.waha.queue' => 'whatsapp',
            'services.waha.delay_min' => 0,
            'services.waha.delay_max' => 0,
            'services.waha.daily_limit' => 0,
            'services.waha.coalesce_seconds' => 0,
            'services.waha.notify_customers' => true,
            'services.otp.channels' => 'whatsapp,sms',
            'services.marsol.token' => 'marsol-token',
            'services.marsol.sender_id' => 'sender-uuid',
            'services.marsol.base_url' => 'https://api.marsol.ly',
        ], $extra));
    }

    public function test_normalizes_libya_and_egypt_phones(): void
    {
        config(['services.waha.default_country_code' => '218']);
        $notifier = app(WhatsAppNotifier::class);

        $this->assertSame('201012345678', $notifier->normalizePhone('+20 10-1234-5678'));
        $this->assertSame('201012345678', $notifier->normalizePhone('01012345678'));
        $this->assertSame('201030003186', $notifier->normalizePhone('201030003186'));

        $this->assertSame('218912159316', $notifier->normalizePhone('0912159316'));
        $this->assertSame('218912159316', $notifier->normalizePhone('218912159316'));
        $this->assertSame('218912159316', $notifier->normalizePhone('00218912159316'));
        $this->assertSame('218912159316', $notifier->normalizePhone('912159316'));
    }

    public function test_admin_phones_normalize_from_config(): void
    {
        config([
            'services.waha.admin_phones' => ['201030003186', '0912159316'],
        ]);

        $this->assertSame(
            ['201030003186', '218912159316'],
            app(WhatsAppNotifier::class)->adminPhones()
        );
    }

    public function test_waha_send_succeeds(): void
    {
        $this->enableWaha(['services.otp.channels' => 'whatsapp']);
        Http::fake([
            'https://waha.test/api/sendText' => Http::response(['ok' => true], 200),
        ]);

        $ok = app(WhatsAppNotifier::class)->send('0912159316', 'مرحبا');

        $this->assertTrue($ok);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://waha.test/api/sendText'
                && $request->header('X-Api-Key')[0] === 'test-api-key'
                && $request['session'] === 'session_test'
                && $request['chatId'] === '218912159316@c.us'
                && $request['text'] === "\u{200F}مرحبا";
        });
    }

    public function test_otp_whatsapp_fails_sms_succeeds(): void
    {
        $this->enableWaha();
        Http::fake([
            'https://waha.test/api/sendText' => Http::response(['error' => 'down'], 500),
            'https://api.marsol.ly/public/senderIds' => Http::response([
                ['id' => 'sender-uuid', 'available' => true, 'isDefault' => true],
            ]),
            'https://api.marsol.ly/public/otp/initiate' => Http::response([
                'requestId' => 'req-sms-1',
                'resendToken' => 'resend-1',
                'expiration' => 300,
            ]),
        ]);

        $user = User::factory()->create([
            'phone' => '0916126065',
            'is_phone_verified' => false,
        ]);

        $meta = app(PhoneOtpService::class)->issue($user, 'login');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check($meta['otp'], $fresh->otp_code));
        $this->assertSame('req-sms-1', $fresh->marsol_otp_request_id);
    }

    public function test_otp_both_channels_fail_does_not_keep_otp(): void
    {
        $this->enableWaha();
        Http::fake([
            'https://waha.test/api/sendText' => Http::response(['error' => 'down'], 500),
            'https://api.marsol.ly/public/senderIds' => Http::response([]),
            'https://api.marsol.ly/public/otp/initiate' => Http::response(['error' => 'fail'], 500),
        ]);

        $user = User::factory()->create([
            'phone' => '0916126065',
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

        try {
            app(PhoneOtpService::class)->issue($user, 'login');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('تعذّر إرسال', collect($e->errors())->flatten()->first() ?? '');
        }

        $fresh = $user->fresh();
        $this->assertNull($fresh->otp_code);
        $this->assertNull($fresh->marsol_otp_request_id);
    }

    public function test_otp_channels_whatsapp_only_skips_marsol(): void
    {
        $this->enableWaha(['services.otp.channels' => 'whatsapp']);
        Http::fake([
            'https://waha.test/api/sendText' => Http::response(['ok' => true], 200),
            'https://api.marsol.ly/*' => Http::response(['should' => 'not'], 500),
        ]);

        $user = User::factory()->create(['phone' => '0916126065']);

        app(PhoneOtpService::class)->issue($user, 'login');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/sendText'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'marsol.ly'));
        $this->assertNull($user->fresh()->marsol_otp_request_id);
    }

    public function test_disabled_setting_skips_send(): void
    {
        $this->enableWaha(['services.waha.enabled' => false]);
        Http::fake();

        $this->assertFalse(app(WhatsAppNotifier::class)->send('01012345678', 'مرحبا'));
        Http::assertNothingSent();
    }

    public function test_invoice_created_queues_alert_job(): void
    {
        $this->enableWaha([
            'services.waha.sync_alerts' => false,
            'queue.default' => 'database',
        ]);
        Queue::fake();

        $customer = User::factory()->create([
            'phone' => '01012345678',
            'name' => 'عميل تجريبي',
            'role' => 'wholesale_distributor',
        ]);

        Invoice::query()->create([
            'number' => 'INV-100',
            'invoice_flow' => 'incoming',
            'wholesale_distributor_id' => $customer->id,
            'total_cents' => 150050,
            'currency' => 'LYD',
            'status' => 'approved',
        ]);

        Queue::assertPushedOn('whatsapp', SendWhatsAppNotification::class);
        Queue::assertPushed(SendWhatsAppNotification::class, function (SendWhatsAppNotification $job) {
            $this->assertSame(3, $job->tries);
            $phones = collect($job->messages)->pluck('phone')->unique()->values()->all();
            $this->assertContains('201012345678', $phones);
            $this->assertContains('201098765432', $phones);
            $this->assertTrue(
                collect($job->messages)->contains(fn ($m) => str_contains($m['text'], 'فاتورة جديدة')
                    && str_contains($m['text'], 'INV-100'))
            );
            $keys = collect($job->messages)->map(fn ($m) => $m['phone'].'|'.$m['text'])->unique();

            return $keys->count() === count($job->messages);
        });
    }

    public function test_matches_accepts_local_hashed_otp(): void
    {
        $this->enableWaha(['services.otp.channels' => 'whatsapp']);
        Http::fake([
            'https://waha.test/api/sendText' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create(['phone' => '0916126065']);
        $meta = app(PhoneOtpService::class)->issue($user, 'login');

        $this->assertTrue(app(PhoneOtpService::class)->matches($user->fresh(), $meta['otp']));
        $this->assertFalse(app(PhoneOtpService::class)->matches($user->fresh(), '000000'));
    }
}
