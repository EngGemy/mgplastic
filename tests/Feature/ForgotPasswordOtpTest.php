<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ForgotPasswordOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_otp_when_the_phone_format_differs(): void
    {
        config([
            'services.marsol.token' => 'test-token',
            'services.marsol.sender_id' => 'sender-uuid',
            'services.marsol.base_url' => 'https://api.marsol.ly',
        ]);

        Http::fake([
            'https://api.marsol.ly/public/senderIds' => Http::response([]),
            'https://api.marsol.ly/public/otp/initiate' => Http::response([
                'requestId' => 'req-1',
                'resendToken' => 'resend-1',
                'expiration' => 300,
            ]),
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

        $this->assertSame('req-1', $user->fresh()->marsol_otp_request_id);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.marsol.ly/public/otp/initiate'
            && $request['phoneNumber'] === '218916126065');
    }
}
