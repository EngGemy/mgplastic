<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ForgotPasswordOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_otp_via_whatsapp_when_phone_format_differs(): void
    {
        config([
            'services.waha.url' => 'https://waha.test',
            'services.waha.key' => 'secret',
            'services.waha.session' => 'default',
            'services.waha.enabled' => true,
            'services.waha.admin_phones' => [],
            'services.otp.channels' => 'whatsapp',
        ]);

        Http::fake([
            'https://waha.test/api/sendText' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create([
            'phone' => '0916126065',
            'otp_last_sent_at' => now()->subMinutes(5),
        ]);

        $this->postJson('/api/v1/auth/forgot-password/send-otp', [
            'phone' => '218916126065',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم إرسال رمز التحقق');

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->otp_code);
        $this->assertNull($fresh->marsol_otp_request_id);
        $this->assertTrue(str_starts_with((string) $fresh->otp_code, '$2y$') || Hash::info($fresh->otp_code)['algo'] !== null);

        Http::assertSent(fn ($request) => $request->url() === 'https://waha.test/api/sendText'
            && ($request['chatId'] ?? '') === '218916126065@c.us'
            && str_starts_with($request['text'] ?? '', "\u{200F}")
            && str_contains($request['text'] ?? '', 'رمز التحقق')
            && ! isset($request['file']));
    }
}
