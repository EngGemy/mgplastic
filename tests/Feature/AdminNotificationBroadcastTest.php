<?php

namespace Tests\Feature;

use App\Models\NotificationBroadcast;
use App\Models\User;
use App\Services\AdminNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNotificationBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_broadcast_to_plumbers_logs_campaign_and_writes_inbox(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
            'is_phone_verified' => true,
        ]);

        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'is_phone_verified' => true,
        ]);

        User::factory()->create([
            'role' => 'retail_trader',
            'is_active' => true,
            'is_phone_verified' => true,
        ]);

        $this->actingAs($admin);

        $broadcast = AdminNotificationService::broadcast([
            'audience' => 'plumbers',
            'title' => 'عرض خاص للسباكين',
            'body' => 'نقاط مضاعفة هذا الأسبوع',
            'status' => 'success',
            'push_type' => 'points',
            'send_push' => false,
            'sent_by' => $admin->id,
        ]);

        $this->assertInstanceOf(NotificationBroadcast::class, $broadcast);
        $this->assertSame(1, $broadcast->recipients_count);
        $this->assertSame('plumbers', $broadcast->audience);
        $this->assertSame('عرض خاص للسباكين', $broadcast->title);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $plumber->id,
            'notifiable_type' => User::class,
        ]);
    }
}
