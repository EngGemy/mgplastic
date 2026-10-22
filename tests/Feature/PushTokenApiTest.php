<?php

namespace Tests\Feature;

use App\Models\PushToken;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\FcmPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushTokenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_push_token_by_device_id_and_reassign_token(): void
    {
        $userA = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
        ]);
        $userB = User::factory()->create([
            'role' => 'retail_trader',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
        ]);

        Sanctum::actingAs($userA);
        $this->postJson('/api/v1/mobile/devices/push-token', [
            'token' => str_repeat('a', 40),
            'platform' => 'android',
            'device_id' => 'device-1',
        ])->assertOk()->assertJsonPath('status', true);

        $this->assertDatabaseHas('push_tokens', [
            'user_id' => $userA->id,
            'device_id' => 'device-1',
            'token' => str_repeat('a', 40),
        ]);

        // Same device, new FCM token → update row
        $this->postJson('/api/v1/mobile/devices/push-token', [
            'token' => str_repeat('b', 40),
            'platform' => 'android',
            'device_id' => 'device-1',
        ])->assertOk();

        $this->assertSame(1, PushToken::query()->where('device_id', 'device-1')->count());
        $this->assertSame(str_repeat('b', 40), PushToken::query()->where('device_id', 'device-1')->value('token'));

        // Same token on another user → move ownership
        Sanctum::actingAs($userB);
        $this->postJson('/api/v1/mobile/devices/push-token', [
            'token' => str_repeat('b', 40),
            'platform' => 'ios',
            'device_id' => 'device-2',
        ])->assertOk();

        $this->assertDatabaseMissing('push_tokens', [
            'user_id' => $userA->id,
            'token' => str_repeat('b', 40),
        ]);
        $this->assertDatabaseHas('push_tokens', [
            'user_id' => $userB->id,
            'device_id' => 'device-2',
            'platform' => 'ios',
        ]);
    }

    public function test_delete_push_token_before_logout(): void
    {
        $user = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/mobile/devices/push-token', [
            'token' => str_repeat('c', 40),
            'platform' => 'ios',
            'device_id' => 'device-x',
        ])->assertOk();

        $this->deleteJson('/api/v1/mobile/devices/push-token', [
            'device_id' => 'device-x',
        ])->assertOk()->assertJsonPath('status', true);

        $this->assertDatabaseMissing('push_tokens', ['device_id' => 'device-x']);
    }

    public function test_admin_notification_calls_fcm_service(): void
    {
        $user = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'is_phone_verified' => true,
        ]);

        $this->mock(FcmPushService::class, function ($mock) use ($user) {
            $mock->shouldReceive('notifyUser')
                ->once()
                ->withArgs(function (User $u, string $title, ?string $body, array $meta) use ($user) {
                    return (int) $u->id === (int) $user->id
                        && $title === 'طلب جديد'
                        && ($meta['entity_type'] ?? null) === 'order';
                });
        });

        AdminNotificationService::send(
            $user,
            'طلب جديد',
            'الطلب في الطريق',
            'info',
            null,
            null,
            ['entity_type' => 'order', 'order_id' => 40],
        );

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_build_data_payload_maps_order_meta(): void
    {
        $data = app(FcmPushService::class)->buildDataPayload([
            'entity_type' => 'order',
            'order_id' => 40,
            'order_number' => 'ORD-1',
        ]);

        $this->assertSame('order', $data['type']);
        $this->assertSame('40', $data['id']);
        $this->assertSame('40', $data['order_id']);
        $this->assertArrayHasKey('link', $data);
    }
}
