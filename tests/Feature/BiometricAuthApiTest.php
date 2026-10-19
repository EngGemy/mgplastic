<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BiometricAuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_challenge_verify_flow(): void
    {
        $user = User::factory()->create([
            'role' => 'retail_trader',
            'phone' => '0911111222',
            'is_approved' => true,
            'is_active' => true,
            'is_phone_verified' => true,
        ]);

        Sanctum::actingAs($user);
        $register = $this->postJson('/api/v1/mobile/biometric/register', [
            'device_id' => 'test-device-1',
            'device_name' => 'Test Phone',
            'platform' => 'android',
        ])->assertCreated();

        $unlock = $register->json('data.unlock_token');
        $this->assertNotEmpty($unlock);

        Sanctum::actingAs($user); // still authed for list
        $this->getJson('/api/v1/mobile/biometric/devices')
            ->assertOk()
            ->assertJsonPath('data.items.0.device_id', 'test-device-1');

        // Public challenge + verify
        $challenge = $this->postJson('/api/v1/mobile/biometric/challenge', [
            'login' => '0911111222',
            'device_id' => 'test-device-1',
        ])->assertOk();

        $verified = $this->postJson('/api/v1/mobile/biometric/verify', [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'device_id' => 'test-device-1',
            'unlock_token' => $unlock,
        ])->assertOk();

        $this->assertNotEmpty($verified->json('data.token'));
        $this->assertSame('biometric', $verified->json('data.auth_method'));
    }

    public function test_settings_exposes_biometric_flag(): void
    {
        $this->getJson('/api/v1/mobile/settings')
            ->assertOk()
            ->assertJsonPath('data.biometric.enabled', true);
    }
}
