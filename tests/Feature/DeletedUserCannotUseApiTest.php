<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class DeletedUserCannotUseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleted_user_token_is_rejected_and_purged(): void
    {
        $user = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
        ]);

        $plain = $user->createToken('auth_token')->plainTextToken;
        $tokenId = (int) explode('|', $plain, 2)[0];

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);

        // Simulate SQL / hard delete without Eloquent events.
        User::query()->whereKey($user->id)->delete();

        $this->withToken($plain)
            ->getJson('/api/v1/mobile/me')
            ->assertUnauthorized();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_inactive_user_token_is_rejected(): void
    {
        $user = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
        ]);

        $plain = $user->createToken('auth_token')->plainTextToken;

        // Bypass observer so the token still exists (simulates stale client token after SQL deactivate).
        User::query()->whereKey($user->id)->update(['is_active' => false]);

        $this->withToken($plain)
            ->getJson('/api/v1/mobile/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'account_revoked');
    }

    public function test_eloquent_delete_revokes_tokens(): void
    {
        $user = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
        ]);

        $plain = $user->createToken('auth_token')->plainTextToken;
        $tokenId = (int) explode('|', $plain, 2)[0];

        $user->delete();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertNull(PersonalAccessToken::findToken($plain));
    }
}
