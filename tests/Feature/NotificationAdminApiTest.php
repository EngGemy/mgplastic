<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_broadcast_preview_and_send(): void
    {
        Queue::fake();

        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
        ]);
        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/notifications/broadcast', [
            'title' => 'عرض خاص',
            'body' => 'خصم اليوم',
            'audience' => 'plumbers',
            'preview_only' => true,
        ])->assertOk()
            ->assertJsonPath('data.preview_count', 1);

        $this->postJson('/api/v1/admin/notifications/broadcast', [
            'title' => 'عرض خاص',
            'body' => 'خصم اليوم',
            'audience' => 'plumbers',
            'send_push' => true,
        ])->assertOk()
            ->assertJsonPath('status', true);

        $this->assertDatabaseHas('notification_broadcasts', [
            'title' => 'عرض خاص',
            'recipients_count' => 1,
        ]);
        $this->assertDatabaseHas('notifications_inbox', [
            'user_id' => $plumber->id,
            'type' => 'campaign.broadcast',
        ]);
    }

    public function test_delivery_stats_endpoint(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/notifications/stats')
            ->assertOk()
            ->assertJsonStructure(['data' => ['by_type', 'totals']]);
    }

    public function test_admin_notification_service_uses_dispatcher(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'role' => 'retail_trader',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
        ]);

        AdminNotificationService::send(
            $user,
            'تم اعتماد الفاتورة',
            'فاتورة #12',
            'success',
            null,
            null,
            ['notification_type' => 'invoice.approved', 'entity_id' => 12, 'invoice_number' => '#12'],
        );

        $this->assertDatabaseHas('notifications_inbox', [
            'user_id' => $user->id,
            'type' => 'invoice.approved',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
        ]);
    }
}
