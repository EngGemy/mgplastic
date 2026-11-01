<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotification;
use App\Models\User;
use App\Services\OtpTicketService;
use App\Services\PhoneOtpService;
use App\Services\WhatsAppNotifier;
use App\Support\WahaResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OtpWhatsAppFalseFailureTest extends TestCase
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
            'services.waha.sync_alerts' => false,
            'services.waha.breaker_ttl' => 300,
            'services.waha.connect_timeout' => 3,
            'services.waha.timeout' => 6,
            'services.waha.otp_timeout' => 15,
            'services.waha.timeouts' => [
                'otp' => ['connect' => 3, 'total' => 15],
                'reminders' => ['connect' => 3, 'total' => 6],
                'bulk' => ['connect' => 3, 'total' => 6],
                'health' => 5,
            ],
            'services.waha.timeout_trip_count' => 3,
            'services.waha.timeout_trip_window' => 60,
            'services.waha.admin_phones' => [],
            'services.waha.default_country_code' => '218',
            'services.otp.channels' => 'whatsapp,sms',
            'services.marsol.token' => 'marsol-token',
            'services.marsol.sender_id' => 'sender-uuid',
            'services.marsol.base_url' => 'https://api.marsol.ly',
            'queue.default' => 'database',
        ]);

        Cache::flush();
    }

    public function test_201_with_json_body_is_success_and_records_last_success(): void
    {
        Http::fake([
            'https://waha.test/api/sendText' => Http::response(['id' => 'msg-1'], 201),
        ]);

        $user = User::factory()->create([
            'phone' => '0912159316',
            'otp_last_sent_at' => now()->subMinutes(5),
        ]);

        $meta = app(PhoneOtpService::class)->issue($user, 'send', 'whatsapp');

        $this->assertSame('whatsapp', $meta['channel']);
        $this->assertArrayNotHasKey('delivery', $meta);
        $this->assertNotNull(Cache::get(WhatsAppNotifier::LAST_SUCCESS_CACHE_KEY));
        $this->assertNotNull($user->fresh()->otp_code);
        $this->assertTrue(Hash::check($meta['otp'], $user->fresh()->otp_code));
    }

    public function test_200_and_201_empty_body_are_success(): void
    {
        Http::fake([
            'https://waha.test/api/sendText' => Http::sequence()
                ->push('', 200)
                ->push('', 201),
        ]);

        $notifier = app(WhatsAppNotifier::class);
        $this->assertTrue($notifier->sendDetailed('0912159316', 'a')->ok);
        $this->assertTrue($notifier->sendDetailed('0912159316', 'b')->ok);
    }

    public function test_read_timeout_returns_success_pending_keeps_otp_breaker_closed(): void
    {
        Http::fake(function () {
            throw new ConnectionException(
                'cURL error 28: Operation timed out after 6001 milliseconds with 0 bytes received'
            );
        });

        $user = User::factory()->create([
            'phone' => '0912159316',
            'otp_last_sent_at' => now()->subMinutes(5),
            'is_phone_verified' => false,
            'password' => bcrypt('secret123'),
        ]);

        $ticket = app(OtpTicketService::class)->issue($user);

        $response = $this->postJson('/api/v1/auth/send-otp', [
            'otp_ticket' => $ticket,
            'channel' => 'whatsapp',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('code', 'otp_sent')
            ->assertJsonPath('delivery', 'pending');

        $this->assertFalse(app(WhatsAppNotifier::class)->isBreakerOpen());
        $this->assertNotNull($user->fresh()->otp_code);
        $this->assertNull(Cache::get(WhatsAppNotifier::LAST_SUCCESS_CACHE_KEY));
    }

    public function test_three_consecutive_read_timeouts_open_breaker(): void
    {
        Http::fake(function () {
            throw new ConnectionException(
                'cURL error 28: Operation timed out after 6001 milliseconds with 0 bytes received'
            );
        });

        $notifier = app(WhatsAppNotifier::class);

        $this->assertTrue($notifier->sendDetailed('0912159316', '1')->deliveryUnknown);
        $this->assertFalse($notifier->isBreakerOpen());

        $this->assertTrue($notifier->sendDetailed('0912159316', '2')->deliveryUnknown);
        $this->assertFalse($notifier->isBreakerOpen());

        $this->assertTrue($notifier->sendDetailed('0912159316', '3')->deliveryUnknown);
        $this->assertTrue($notifier->isBreakerOpen());
    }

    public function test_401_opens_breaker_and_returns_otp_send_failed_with_sms_available(): void
    {
        Http::fake([
            'https://waha.test/api/sendText' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $user = User::factory()->create([
            'phone' => '0912159316',
            'otp_last_sent_at' => now()->subMinutes(5),
        ]);

        $ticket = app(OtpTicketService::class)->issue($user);

        $this->postJson('/api/v1/auth/send-otp', [
            'otp_ticket' => $ticket,
            'channel' => 'whatsapp',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'otp_send_failed')
            ->assertJsonPath('sms_available', true)
            ->assertJsonPath('message', 'تعذّر إرسال رمز التحقق عبر واتساب — جرّب SMS أو حاول لاحقًا');

        $this->assertTrue(app(WhatsAppNotifier::class)->isBreakerOpen());
    }

    public function test_egypt_number_waha_down_sms_available_false(): void
    {
        Http::fake([
            'https://waha.test/api/sendText' => Http::response(['error' => 'down'], 500),
        ]);

        $user = User::factory()->create([
            'phone' => '01277444422',
            'otp_last_sent_at' => now()->subMinutes(5),
        ]);

        $ticket = app(OtpTicketService::class)->issue($user);

        $this->postJson('/api/v1/auth/send-otp', [
            'otp_ticket' => $ticket,
            'channel' => 'whatsapp',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'otp_send_failed')
            ->assertJsonPath('sms_available', false)
            ->assertJsonPath('message', 'تعذّر إرسال رمز التحقق عبر واتساب — حاول لاحقًا');
    }

    public function test_session_down_422_opens_breaker(): void
    {
        Http::fake([
            'https://waha.test/api/sendText' => Http::response([
                'error' => 'Session status is not as expected',
                'status' => 'SCAN_QR_CODE',
            ], 422),
        ]);

        $result = app(WhatsAppNotifier::class)->sendDetailed('0912159316', 'otp');

        $this->assertFalse($result->ok);
        $this->assertFalse($result->deliveryUnknown);
        $this->assertSame(WahaResult::REASON_SESSION_DOWN, $result->reason);
        $this->assertTrue(app(WhatsAppNotifier::class)->isBreakerOpen());
    }

    public function test_breaker_already_open_fails_without_http_and_without_rotating_silently(): void
    {
        app(WhatsAppNotifier::class)->openBreaker('preexisting');
        Http::fake();

        $user = User::factory()->create([
            'phone' => '0912159316',
            'otp_last_sent_at' => now()->subMinutes(5),
            'otp_code' => Hash::make('111111'),
        ]);

        $ticket = app(OtpTicketService::class)->issue($user);

        $this->postJson('/api/v1/auth/send-otp', [
            'otp_ticket' => $ticket,
            'channel' => 'whatsapp',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'otp_send_failed');

        Http::assertNothingSent();
        // Failed send must not wipe / rotate a previous code into a new hash without delivery.
        $this->assertTrue(Hash::check('111111', $user->fresh()->otp_code));
    }

    public function test_otp_request_makes_at_most_one_waha_call_even_with_image_follow_up(): void
    {
        Queue::fake();
        Http::fake([
            'https://waha.test/api/sendText' => Http::response(['ok' => true], 201),
            'https://waha.test/api/sendImage' => Http::response(['ok' => true], 201),
        ]);

        $result = app(WhatsAppNotifier::class)->sendDetailed(
            '0912159316',
            'رمز التحقق 123456',
            ['base64' => base64_encode('fake'), 'mimetype' => 'image/jpeg', 'filename' => 'card.jpg']
        );

        $this->assertTrue($result->ok);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendText'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sendImage'));
        Queue::assertPushed(SendWhatsAppNotification::class);
    }

    public function test_image_follow_up_failure_does_not_change_otp_result(): void
    {
        config(['services.waha.sync_alerts' => true, 'queue.default' => 'sync']);

        Http::fake([
            'https://waha.test/api/sendText' => Http::response(['ok' => true], 200),
            'https://waha.test/api/sendImage' => Http::response(['error' => 'media'], 400),
        ]);

        $user = User::factory()->create([
            'phone' => '0912159316',
            'otp_last_sent_at' => now()->subMinutes(5),
        ]);

        // Direct notifier path with image — OTP text ok even if card path fails.
        $result = app(WhatsAppNotifier::class)->sendDetailed(
            (string) $user->phone,
            'رمز التحقق 999999',
            ['base64' => base64_encode('x'), 'filename' => 'c.jpg']
        );

        $this->assertTrue($result->ok);
    }

    public function test_cooldown_after_pending_keeps_existing_code(): void
    {
        Http::fake(function () {
            throw new ConnectionException(
                'cURL error 28: Operation timed out after 6001 milliseconds with 0 bytes received'
            );
        });

        $user = User::factory()->create([
            'phone' => '0912159316',
            'otp_last_sent_at' => now()->subMinutes(5),
        ]);

        $meta = app(PhoneOtpService::class)->issue($user, 'send', 'whatsapp');
        $this->assertSame('pending', $meta['delivery']);
        $hash = $user->fresh()->otp_code;

        $ticket = app(OtpTicketService::class)->issue($user->fresh());

        $this->postJson('/api/v1/auth/send-otp', [
            'otp_ticket' => $ticket,
            'channel' => 'whatsapp',
        ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'otp_cooldown');

        $this->assertSame($hash, $user->fresh()->otp_code);
    }

    public function test_connect_refused_opens_breaker_immediately(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect to waha.test port 443');
        });

        $result = app(WhatsAppNotifier::class)->sendDetailed('0912159316', 'otp');

        $this->assertFalse($result->ok);
        $this->assertFalse($result->deliveryUnknown);
        $this->assertTrue(app(WhatsAppNotifier::class)->isBreakerOpen());
    }
}
