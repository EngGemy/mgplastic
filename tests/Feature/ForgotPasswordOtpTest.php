<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ForgotPasswordOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.waha.url' => 'https://waha.test',
            'services.waha.key' => 'secret',
            'services.waha.session' => 'default',
            'services.waha.enabled' => true,
            'services.waha.sync_alerts' => true,
            'services.waha.admin_phones' => [],
            'services.otp.channels' => 'whatsapp,sms',
        ]);
    }

    public function test_forgot_password_without_channel_returns_picker(): void
    {
        Http::fake();

        User::factory()->create([
            'phone' => '0916126065',
            'otp_last_sent_at' => now()->subMinutes(5),
        ]);

        $this->postJson('/api/v1/auth/forgot-password/send-otp', [
            'phone' => '0916126065',
        ])
            ->assertOk()
            ->assertJsonPath('code', 'channel_required')
            ->assertJsonPath('otp_sent', false)
            ->assertJsonPath('requires_channel', true)
            ->assertJsonStructure(['otp_ticket', 'otp_channels']);

        Http::assertNothingSent();
    }

    public function test_forgot_password_sends_otp_via_whatsapp_when_channel_chosen(): void
    {
        Http::fake([
            'https://waha.test/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create([
            'phone' => '0916126065',
            'otp_last_sent_at' => now()->subMinutes(5),
        ]);

        $start = $this->postJson('/api/v1/auth/forgot-password/send-otp', [
            'phone' => '218916126065',
        ])->assertOk();

        $ticket = $start->json('otp_ticket');

        $this->postJson('/api/v1/auth/forgot-password/send-otp', [
            'phone' => '218916126065',
            'otp_ticket' => $ticket,
            'channel' => 'whatsapp',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('code', 'otp_sent')
            ->assertJsonPath('channel', 'whatsapp')
            ->assertJsonPath('message', 'تم إرسال رمز التحقق عبر واتساب');

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->otp_code);
        $this->assertNull($fresh->marsol_otp_request_id);
        $this->assertTrue(str_starts_with((string) $fresh->otp_code, '$2y$') || Hash::info($fresh->otp_code)['algo'] !== null);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/sendText')) {
                return false;
            }

            $data = $request->data();
            $text = (string) ($data['text'] ?? '');
            $chatId = (string) ($data['chatId'] ?? '');

            if (array_key_exists('file', $data) && filled($data['file'])) {
                return false;
            }

            $hasRlm = str_starts_with($text, WhatsAppMessage::RLM)
                || str_contains($text, WhatsAppMessage::RLM);

            return str_contains($chatId, '218916126065')
                && str_contains($text, 'رمز التحقق')
                && $hasRlm;
        });
    }

    public function test_forgot_password_whatsapp_fail_does_not_switch_to_sms(): void
    {
        config([
            'services.marsol.token' => 'marsol-token',
            'services.marsol.sender_id' => 'sender-uuid',
            'services.marsol.base_url' => 'https://api.marsol.ly',
        ]);

        Http::fake([
            'https://waha.test/*' => Http::response(['error' => 'session down'], 500),
            'https://api.marsol.ly/*' => Http::response(['should' => 'not'], 500),
        ]);

        User::factory()->create([
            'phone' => '0916126065',
            'otp_last_sent_at' => now()->subMinutes(5),
        ]);

        $ticket = $this->postJson('/api/v1/auth/forgot-password/send-otp', [
            'phone' => '0916126065',
        ])->assertOk()->json('otp_ticket');

        $this->postJson('/api/v1/auth/forgot-password/send-otp', [
            'phone' => '0916126065',
            'otp_ticket' => $ticket,
            'channel' => 'whatsapp',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'otp_send_failed');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'marsol.ly'));
    }

    public function test_forgot_password_sms_channel_uses_marsol_only(): void
    {
        config([
            'services.marsol.token' => 'marsol-token',
            'services.marsol.sender_id' => 'sender-uuid',
            'services.marsol.base_url' => 'https://api.marsol.ly',
        ]);

        Http::fake([
            'https://waha.test/*' => Http::response(['should' => 'not'], 500),
            'https://api.marsol.ly/public/senderIds' => Http::response([
                ['id' => 'sender-uuid', 'available' => true, 'isDefault' => true],
            ]),
            'https://api.marsol.ly/public/otp/initiate' => Http::response([
                'requestId' => 'req-sms-choice',
                'resendToken' => 'resend-sms',
                'expiration' => 300,
            ]),
        ]);

        $user = User::factory()->create([
            'phone' => '0916126065',
            'otp_last_sent_at' => now()->subMinutes(5),
        ]);

        $ticket = $this->postJson('/api/v1/auth/forgot-password/send-otp', [
            'phone' => '0916126065',
        ])->assertOk()->json('otp_ticket');

        $this->postJson('/api/v1/auth/forgot-password/send-otp', [
            'phone' => '0916126065',
            'otp_ticket' => $ticket,
            'channel' => 'sms',
        ])
            ->assertOk()
            ->assertJsonPath('code', 'otp_sent')
            ->assertJsonPath('channel', 'sms');

        $this->assertNull($user->fresh()->otp_code);
        $this->assertSame('req-sms-choice', $user->fresh()->marsol_otp_request_id);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/sendText'));
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/public/otp/initiate')) {
                return false;
            }
            $phone = (string) ($request->data()['phoneNumber'] ?? '');

            // Marsol expects Libyan local format.
            return $phone === '0916126065';
        });
    }
}
