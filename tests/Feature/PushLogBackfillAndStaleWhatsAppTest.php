<?php

namespace Tests\Feature;

use App\Jobs\SendPushJob;
use App\Jobs\SendWhatsAppNotification;
use App\Models\NotificationInbox;
use App\Models\NotificationLog;
use App\Models\PushToken;
use App\Models\User;
use App\Notifications\Catalog\NotificationType;
use App\Services\FcmPushService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\SystemHealthService;
use App\Services\WhatsAppNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PushLogBackfillAndStaleWhatsAppTest extends TestCase
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
            'services.waha.alert_emails' => ['ops@example.com'],
            'cache.default' => 'array',
            'monitoring.whatsapp_message_max_age' => [
                'otp' => 300,
                'otp_card' => 300,
                'invoice_created' => 43200,
                'welcome' => 43200,
                'reminder_daily' => 'end_of_day',
                'reminder_weekly' => 86400,
                'default' => 43200,
            ],
        ]);

        Cache::flush();
    }

    public function test_send_push_job_updates_queued_row_instead_of_inserting(): void
    {
        $user = User::factory()->create(['role' => 'plumber', 'is_active' => true]);
        $token = PushToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'dev-1',
            'token' => str_repeat('a', 40),
            'platform' => 'android',
            'is_active' => true,
        ]);

        $inbox = NotificationInbox::query()->create([
            'user_id' => $user->id,
            'type' => NotificationType::CampaignBroadcast->value,
            'title' => 'Hi',
            'body' => 'Body',
            'data' => ['type' => 'campaign.broadcast', 'screen' => 'home'],
            'priority' => 'normal',
        ]);

        NotificationLog::query()->create([
            'notification_id' => $inbox->id,
            'device_token_id' => null,
            'status' => NotificationLog::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        $this->mock(FcmPushService::class, function ($mock) use ($token) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('sendMulticast')->once()->andReturn([
                'successes' => [$token->token => 'msg-1'],
                'failures' => [],
            ]);
        });

        (new SendPushJob($inbox->id))->handle(
            app(FcmPushService::class),
            app(NotificationDispatcher::class),
        );

        $this->assertSame(1, NotificationLog::query()->where('notification_id', $inbox->id)->count());
        $this->assertDatabaseHas('notification_logs', [
            'notification_id' => $inbox->id,
            'device_token_id' => $token->id,
            'status' => NotificationLog::STATUS_SENT,
            'fcm_message_id' => 'msg-1',
        ]);
        $this->assertDatabaseMissing('notification_logs', [
            'notification_id' => $inbox->id,
            'status' => NotificationLog::STATUS_QUEUED,
        ]);
    }

    public function test_push_fcm_check_ignores_orphan_queued_with_sibling_final(): void
    {
        $user = User::factory()->create(['role' => 'plumber', 'is_active' => true]);
        $inbox = NotificationInbox::query()->create([
            'user_id' => $user->id,
            'type' => NotificationType::CampaignBroadcast->value,
            'title' => 'Hi',
            'body' => 'Body',
            'data' => [],
            'priority' => 'normal',
        ]);

        $staleAt = now()->subHours(2);
        $queued = NotificationLog::query()->create([
            'notification_id' => $inbox->id,
            'device_token_id' => null,
            'status' => NotificationLog::STATUS_QUEUED,
            'attempts' => 0,
        ]);
        $queued->forceFill(['created_at' => $staleAt, 'updated_at' => $staleAt])->save();

        $sent = NotificationLog::query()->create([
            'notification_id' => $inbox->id,
            'device_token_id' => null,
            'status' => NotificationLog::STATUS_SENT,
            'attempts' => 1,
            'sent_at' => $staleAt,
        ]);
        $sent->forceFill(['created_at' => $staleAt, 'updated_at' => $staleAt])->save();

        $check = app(SystemHealthService::class)->checkPushFcm();
        $this->assertSame('ok', $check->status);
        $this->assertSame(0, $check->value['queued_stale'] ?? null);
    }

    public function test_backfill_dry_run_and_apply(): void
    {
        $user = User::factory()->create(['role' => 'plumber', 'is_active' => true]);
        $inbox = NotificationInbox::query()->create([
            'user_id' => $user->id,
            'type' => NotificationType::CampaignBroadcast->value,
            'title' => 'Hi',
            'body' => 'Body',
            'data' => [],
            'priority' => 'normal',
        ]);

        $old = now()->subDays(2);
        $queuedWithSibling = NotificationLog::query()->create([
            'notification_id' => $inbox->id,
            'status' => NotificationLog::STATUS_QUEUED,
        ]);
        $queuedWithSibling->forceFill(['created_at' => $old, 'updated_at' => $old])->save();

        $sentSibling = NotificationLog::query()->create([
            'notification_id' => $inbox->id,
            'status' => NotificationLog::STATUS_SENT,
        ]);
        $sentSibling->forceFill(['created_at' => $old, 'updated_at' => $old])->save();

        $orphanInbox = NotificationInbox::query()->create([
            'user_id' => $user->id,
            'type' => NotificationType::CampaignBroadcast->value,
            'title' => 'Orphan',
            'body' => 'x',
            'data' => [],
            'priority' => 'normal',
        ]);
        $orphanQueued = NotificationLog::query()->create([
            'notification_id' => $orphanInbox->id,
            'status' => NotificationLog::STATUS_QUEUED,
        ]);
        $orphanQueued->forceFill(['created_at' => $old, 'updated_at' => $old])->save();

        $this->artisan('notifications:backfill-push-logs')
            ->assertSuccessful();

        $this->assertSame(2, NotificationLog::query()->where('status', NotificationLog::STATUS_QUEUED)->count());

        $this->artisan('notifications:backfill-push-logs', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame(0, NotificationLog::query()->where('status', NotificationLog::STATUS_QUEUED)->count());
        $this->assertDatabaseHas('notification_logs', [
            'notification_id' => $orphanInbox->id,
            'status' => NotificationLog::STATUS_EXPIRED,
            'error' => 'orphan_queued_backfill',
        ]);
        $this->assertSame(1, NotificationLog::query()->where('notification_id', $inbox->id)->count());
    }

    public function test_stale_whatsapp_messages_expire_without_sending_or_alerting(): void
    {
        Mail::fake();
        Http::fake();

        $job = new SendWhatsAppNotification([[
            'phone' => '218912159316',
            'text' => 'old invoice',
            'event' => 'invoice_created',
            'recipient_type' => 'admin',
            'queued_at' => now()->subHours(13)->toIso8601String(),
        ]]);

        $job->handle(app(WhatsAppNotifier::class));

        Http::assertNothingSent();
        $this->assertDatabaseHas('whatsapp_logs', [
            'status' => 'expired',
            'error' => 'stale_after_outage',
            'event' => 'invoice_created',
        ]);
        Mail::assertNothingSent();
    }

    public function test_otp_fail_fast_when_breaker_open(): void
    {
        Http::fake();
        app(WhatsAppNotifier::class)->openBreaker('auth failed (401)');

        $job = new SendWhatsAppNotification([[
            'phone' => '218912159316',
            'text' => '123456',
            'event' => 'otp',
            'recipient_type' => 'otp',
            'is_otp' => true,
            'queued_at' => now()->toIso8601String(),
        ]]);

        $job->handle(app(WhatsAppNotifier::class));

        Http::assertNothingSent();
        $this->assertDatabaseHas('whatsapp_logs', [
            'status' => 'failed',
            'error' => 'breaker_open_otp_fail_fast',
            'event' => 'otp',
        ]);
    }

    public function test_reminder_daily_expires_after_end_of_day(): void
    {
        $job = new SendWhatsAppNotification([]);
        $stale = [
            'phone' => '218912159316',
            'text' => 'daily',
            'event' => 'reminder_daily',
            'queued_at' => now()->subDay()->startOfDay()->toIso8601String(),
        ];
        $fresh = [
            'phone' => '218912159316',
            'text' => 'daily',
            'event' => 'reminder_daily',
            'queued_at' => now()->startOfDay()->toIso8601String(),
        ];

        $this->assertTrue($job->isStale($stale));
        $this->assertFalse($job->isStale($fresh));
    }

    public function test_banner_problems_never_call_waha_http(): void
    {
        Http::fake([
            'https://waha.test/*' => Http::response(['status' => 'WORKING'], 200),
        ]);

        Cache::put(config('monitoring.health_snapshot_key'), [
            'checks' => [[
                'key' => 'waha_session',
                'label' => 'جلسة WAHA',
                'status' => 'critical',
                'value' => null,
                'details' => 'cached problem',
                'checked_at' => now()->toIso8601String(),
            ]],
            'problems' => [[
                'key' => 'waha_session',
                'label' => 'جلسة WAHA',
                'status' => 'critical',
                'value' => null,
                'details' => 'cached problem',
                'checked_at' => now()->toIso8601String(),
            ]],
            'checked_at' => now()->toIso8601String(),
        ], 3600);

        $problems = app(SystemHealthService::class)->bannerProblems();
        $this->assertCount(1, $problems);
        $this->assertSame('cached problem', $problems[0]['details']);
        Http::assertNothingSent();
    }

    public function test_refresh_and_store_snapshot_is_live(): void
    {
        Http::fake([
            'https://waha.test/api/sessions/session_test' => Http::response(['status' => 'WORKING'], 200),
        ]);
        Cache::put(config('monitoring.queue_heartbeat_key'), now()->toIso8601String(), 3600);

        $checks = app(SystemHealthService::class)->refreshAndStoreSnapshot();
        $this->assertNotEmpty($checks);
        Http::assertSentCount(1);

        $snapshot = app(SystemHealthService::class)->cachedSnapshot();
        $this->assertIsArray($snapshot);
        $this->assertNotEmpty($snapshot['checks'] ?? []);
    }
}
