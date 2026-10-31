<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotification;
use App\Models\User;
use App\Services\PhoneOtpService;
use App\Services\WhatsAppNotifier;
use App\Support\WahaResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WahaResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.waha.url' => 'https://waha.test',
            'services.waha.key' => 'test-api-key',
            'services.waha.session' => 'session_test',
            'services.waha.enabled' => true,
            'services.waha.sync_alerts' => true,
            'services.waha.breaker_ttl' => 300,
            'services.waha.connect_timeout' => 3,
            'services.waha.timeout' => 6,
            'services.waha.otp_timeout' => 6,
            'services.waha.admin_phones' => [],
            'services.waha.default_country_code' => '218',
            'services.otp.channels' => 'whatsapp,sms',
            'services.marsol.token' => 'marsol-token',
            'services.marsol.sender_id' => 'sender-uuid',
            'services.marsol.base_url' => 'https://api.marsol.ly',
        ]);

        Cache::flush();
    }

    public function test_401_opens_breaker_and_blocks_further_http(): void
    {
        Http::fake([
            'https://waha.test/api/sendText' => Http::sequence()
                ->push(['error' => 'unauthorized'], 401)
                ->push(['ok' => true], 200),
        ]);

        $notifier = app(WhatsAppNotifier::class);
        $this->assertFalse($notifier->send('0912159316', 'otp'));
        $this->assertTrue($notifier->isBreakerOpen());

        Http::fake([
            'https://waha.test/*' => Http::response(['ok' => true], 200),
        ]);

        $this->assertFalse($notifier->send('0912159316', 'otp2'));
        Http::assertNothingSent();
    }

    public function test_scan_qr_422_opens_breaker(): void
    {
        Http::fake([
            'https://waha.test/api/sendText' => Http::response([
                'error' => 'Session status is not as expected',
                'status' => 'SCAN_QR_CODE',
                'expected' => ['WORKING'],
            ], 422),
        ]);

        $notifier = app(WhatsAppNotifier::class);
        $result = $notifier->deliverDetailed([[
            'phone' => '0912159316',
            'text' => 'hello',
            'is_otp' => true,
            'recipient_type' => 'otp',
        ]], skipDelay: true);

        $this->assertFalse($result->ok);
        $this->assertSame(WahaResult::REASON_SESSION_DOWN, $result->reason);
        $this->assertTrue($notifier->isBreakerOpen());
    }

    public function test_breaker_open_means_zero_http_calls(): void
    {
        app(WhatsAppNotifier::class)->openBreaker('test');
        Http::fake();

        $this->assertFalse(app(WhatsAppNotifier::class)->send('0912159316', 'x'));
        Http::assertNothingSent();
    }

    public function test_media_failure_falls_back_to_text_once(): void
    {
        Http::fake([
            'https://waha.test/api/sendImage' => Http::response(['error' => 'bad media'], 400),
            'https://waha.test/api/sendText' => Http::response(['ok' => true], 200),
        ]);

        $ok = app(WhatsAppNotifier::class)->deliver([
            [
                'phone' => '0912159316',
                'text' => 'caption',
                'image' => ['base64' => base64_encode('fake'), 'mimetype' => 'image/jpeg', 'filename' => 'x.jpg'],
                'is_otp' => false,
                'recipient_type' => 'admin',
            ],
        ], skipDelay: true);

        $this->assertTrue($ok);
        Http::assertSentCount(2);
    }

    public function test_session_down_on_image_does_not_try_text(): void
    {
        Http::fake([
            'https://waha.test/api/sendImage' => Http::response([
                'error' => 'Session status is not as expected',
                'status' => 'FAILED',
            ], 422),
            'https://waha.test/api/sendText' => Http::response(['ok' => true], 200),
        ]);

        $result = app(WhatsAppNotifier::class)->deliverDetailed([
            [
                'phone' => '0912159316',
                'text' => 'caption',
                'image' => ['base64' => base64_encode('fake'), 'filename' => 'x.jpg'],
                'recipient_type' => 'admin',
            ],
        ], skipDelay: true);

        $this->assertFalse($result->ok);
        $this->assertSame(WahaResult::REASON_SESSION_DOWN, $result->reason);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendImage'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sendText'));
    }

    public function test_job_releases_when_breaker_open(): void
    {
        app(WhatsAppNotifier::class)->openBreaker('test');

        $job = new class([['phone' => '0912159316', 'text' => 'hi', 'recipient_type' => 'admin']]) extends SendWhatsAppNotification
        {
            public ?int $releasedFor = null;

            public function release($delay = 0): void
            {
                $this->releasedFor = (int) $delay;
            }

            public function attempts(): int
            {
                return 1;
            }
        };

        $job->handle(app(WhatsAppNotifier::class));

        $this->assertNotNull($job->releasedFor);
        $this->assertGreaterThanOrEqual(30, $job->releasedFor);
    }

    public function test_egypt_sms_fails_fast_without_marsol_hang(): void
    {
        Http::fake();

        $user = User::factory()->create(['phone' => '01277444422']);

        try {
            app(PhoneOtpService::class)->issue($user, 'login', 'sms');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('SMS', collect($e->errors())->flatten()->first() ?? '');
        }

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'marsol.ly'));
    }

    public function test_reset_breaker_allows_send_again(): void
    {
        $notifier = app(WhatsAppNotifier::class);
        $notifier->openBreaker('temp');
        $notifier->resetBreaker();

        Http::fake([
            'https://waha.test/api/sendText' => Http::response(['ok' => true], 200),
        ]);

        $this->assertTrue($notifier->send('0912159316', 'otp'));
    }

    public function test_send_many_queues_when_sync_alerts_false(): void
    {
        config(['services.waha.sync_alerts' => false, 'queue.default' => 'database']);
        Queue::fake();
        Http::fake();

        app(WhatsAppNotifier::class)->sendMany([[
            'phone' => '0912159316',
            'text' => 'alert',
            'recipient_type' => 'admin',
        ]]);

        Queue::assertPushed(SendWhatsAppNotification::class);
        Http::assertNothingSent();
    }
}
