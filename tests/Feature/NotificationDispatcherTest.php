<?php

namespace Tests\Feature;

use App\Jobs\SendPushJob;
use App\Models\NotificationInbox;
use App\Models\NotificationPreference;
use App\Models\PushToken;
use App\Models\User;
use App\Notifications\Catalog\NotificationType;
use App\Services\FcmPushService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\QuietHoursService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'plumber',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
            'locale' => 'ar',
            'timezone' => 'Africa/Tripoli',
        ], $attrs));
    }

    public function test_dispatcher_writes_inbox_and_queues_push(): void
    {
        Queue::fake();

        $user = $this->makeUser();
        PushToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'd1',
            'token' => str_repeat('t', 40),
            'platform' => 'android',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);

        $inbox = app(NotificationDispatcher::class)->sendOne(
            $user,
            NotificationType::OrderApproved,
            ['order_number' => 'ORD-1', 'entity_id' => 55, 'order_id' => 55],
        );

        $this->assertNotNull($inbox);
        $this->assertDatabaseHas('notifications_inbox', [
            'user_id' => $user->id,
            'type' => 'order.approved',
            'idempotency_key' => 'order.approved|55|'.$user->id,
        ]);
        $this->assertStringContainsString('ORD-1', $inbox->body);

        Queue::assertPushed(SendPushJob::class, fn (SendPushJob $job) => $job->inboxId === $inbox->id);
    }

    public function test_preferences_can_disable_push(): void
    {
        Queue::fake();
        $user = $this->makeUser();

        NotificationPreference::query()->create([
            'user_id' => $user->id,
            'type' => NotificationType::PointsAdded->value,
            'push' => false,
            'in_app' => true,
            'email' => false,
        ]);

        $inbox = app(NotificationDispatcher::class)->sendOne(
            $user,
            NotificationType::PointsAdded,
            ['points' => 10, 'entity_id' => 'p-1'],
        );

        $this->assertNotNull($inbox);
        Queue::assertNotPushed(SendPushJob::class);
    }

    public function test_quiet_hours_delays_normal_priority(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-22 23:30:00', 'UTC'));

        $user = $this->makeUser([
            'quiet_from' => '22:00',
            'quiet_to' => '07:00',
            'timezone' => 'UTC',
        ]);

        $delay = app(QuietHoursService::class)->delayUntil($user);
        $this->assertNotNull($delay);
        $this->assertSame(7, $delay->timezone('UTC')->hour);

        $inbox = app(NotificationDispatcher::class)->sendOne(
            $user,
            NotificationType::PointsAdded,
            ['points' => 5, 'entity_id' => 'qh-1'],
        );

        Queue::assertPushed(SendPushJob::class, function (SendPushJob $job) {
            return $job->delay !== null;
        });

        Carbon::setTestNow();
    }

    public function test_high_priority_bypasses_quiet_hours(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-22 23:30:00', 'UTC'));

        $user = $this->makeUser([
            'quiet_from' => '22:00',
            'quiet_to' => '07:00',
            'timezone' => 'UTC',
        ]);

        app(NotificationDispatcher::class)->sendOne(
            $user,
            NotificationType::OrderApproved,
            ['order_number' => 'X', 'entity_id' => 9],
        );

        Queue::assertPushed(SendPushJob::class, function (SendPushJob $job) {
            return $job->delay === null;
        });

        Carbon::setTestNow();
    }

    public function test_idempotency_prevents_duplicate_inbox(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $dispatcher = app(NotificationDispatcher::class);

        $a = $dispatcher->sendOne($user, NotificationType::InvoiceApproved, [
            'invoice_number' => 'INV-1',
            'entity_id' => 100,
        ]);
        $b = $dispatcher->sendOne($user, NotificationType::InvoiceApproved, [
            'invoice_number' => 'INV-1',
            'entity_id' => 100,
        ]);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, NotificationInbox::query()->where('user_id', $user->id)->count());
    }

    public function test_rate_limit_skips_normal_push(): void
    {
        Queue::fake();
        config(['notifications.max_normal_push_per_user_per_day' => 2]);

        $user = $this->makeUser();
        Cache::put('notif:push:day:'.$user->id.':'.now()->toDateString(), 2, now()->endOfDay());

        app(NotificationDispatcher::class)->sendOne(
            $user,
            NotificationType::PointsAdded,
            ['points' => 1, 'entity_id' => 'rl-1'],
        );

        Queue::assertNotPushed(SendPushJob::class);
    }

    public function test_send_push_job_deactivates_unregistered_tokens(): void
    {
        $user = $this->makeUser();
        $token = PushToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'bad-device',
            'token' => str_repeat('z', 40),
            'platform' => 'ios',
            'is_active' => true,
        ]);

        $inbox = NotificationInbox::query()->create([
            'user_id' => $user->id,
            'type' => NotificationType::CampaignBroadcast->value,
            'title' => 'Hello',
            'body' => 'World',
            'data' => ['type' => 'campaign.broadcast', 'screen' => 'home'],
            'priority' => 'normal',
        ]);

        $this->mock(FcmPushService::class, function ($mock) use ($token) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('sendMulticast')->once()->andReturn([
                'successes' => [],
                'failures' => [$token->token => 'UNREGISTERED'],
            ]);
        });

        (new SendPushJob($inbox->id))->handle(
            app(FcmPushService::class),
            app(NotificationDispatcher::class),
        );

        $this->assertFalse($token->fresh()->is_active);
        $this->assertDatabaseHas('notification_logs', [
            'notification_id' => $inbox->id,
            'status' => 'unregistered',
        ]);
    }

    public function test_preferences_api(): void
    {
        $user = $this->makeUser();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson('/api/v1/mobile/notifications/preferences')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['data' => ['preferences', 'quiet_hours', 'catalog']]);

        $this->putJson('/api/v1/mobile/notifications/preferences', [
            'preferences' => [
                ['type' => 'points.added', 'push' => false, 'in_app' => true, 'email' => false],
            ],
            'quiet_hours' => [
                'quiet_from' => '22:00',
                'quiet_to' => '07:00',
                'timezone' => 'Africa/Tripoli',
                'locale' => 'ar',
            ],
        ])->assertOk()->assertJsonPath('status', true);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'type' => 'points.added',
            'push' => 0,
        ]);
        $this->assertSame('22:00', substr((string) $user->fresh()->quiet_from, 0, 5));
    }
}
