<?php

namespace Tests\Feature;

use App\Listeners\NoteQueueRestart;
use App\Listeners\RecordQueueWorkerHeartbeat;
use App\Models\NotificationLog;
use App\Models\SystemHealthState;
use App\Models\User;
use App\Services\HealthAlertService;
use App\Services\SystemHealthService;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class SystemHealthStatePersistenceTest extends TestCase
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
            'services.waha.alert_emails' => ['ops@example.com'],
            'monitoring.queue_restart_grace_seconds' => 180,
            'monitoring.queue_heartbeat_stale_seconds' => 300,
            'cache.default' => 'array',
        ]);
        Cache::flush();
        Mail::fake();
        Http::fake([
            'https://waha.test/api/sessions/session_test' => Http::response(['status' => 'WORKING'], 200),
        ]);
    }

    public function test_heartbeat_survives_cache_flush(): void
    {
        (new RecordQueueWorkerHeartbeat)->handle(new \stdClass);
        $this->assertNotNull(SystemHealthState::getString(SystemHealthState::KEY_QUEUE_HEARTBEAT));

        Cache::flush();

        $check = app(SystemHealthService::class)->checkQueueWorker();
        $this->assertSame('ok', $check->status);
        $this->assertNotEmpty($check->value['heartbeat_at'] ?? null);
    }

    public function test_alert_state_survives_cache_flush_no_duplicate_new_alert(): void
    {
        SystemHealthState::putString(SystemHealthState::KEY_QUEUE_HEARTBEAT, now()->toIso8601String());
        $notifier = app(\App\Services\WhatsAppNotifier::class);
        $notifier->openBreaker('auth failed (401)');

        $service = app(HealthAlertService::class);
        $first = $service->evaluateAndAlert();
        $this->assertContains('waha_breaker', $first['alerted']);

        $stored = SystemHealthState::getPayload(SystemHealthState::alertStateKey('waha_breaker'));
        $this->assertSame('critical', $stored['status'] ?? null);
        $this->assertNotEmpty($stored['last_alerted_at'] ?? null);

        // Simulate deploy wipe of file cache — DB alert state must remain.
        Cache::flush();
        $notifier->openBreaker('auth failed (401)'); // breaker itself still uses cache today

        $second = $service->evaluateAndAlert();
        $this->assertNotContains('waha_breaker', $second['alerted']);
        $this->assertNotContains('waha_breaker', $second['repeated']);
        $this->assertSame(
            'critical',
            SystemHealthState::getPayload(SystemHealthState::alertStateKey('waha_breaker'))['status'] ?? null
        );
    }

    public function test_queue_restart_grace_avoids_critical_without_heartbeat(): void
    {
        Cache::flush();
        SystemHealthState::query()->delete();

        (new NoteQueueRestart)->handle(new CommandStarting(
            'queue:restart',
            new ArrayInput([]),
            new NullOutput,
        ));

        $check = app(SystemHealthService::class)->checkQueueWorker();
        $this->assertSame('ok', $check->status);
        $this->assertTrue((bool) ($check->value['restart_grace'] ?? false));
    }

    public function test_send_push_without_token_uses_skipped_no_token_status(): void
    {
        $user = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'is_approved' => true,
        ]);

        $inbox = \App\Models\NotificationInbox::query()->create([
            'user_id' => $user->id,
            'title' => 't',
            'body' => 'b',
            'type' => 'test',
            'priority' => 'normal',
        ]);

        NotificationLog::query()->create([
            'notification_id' => $inbox->id,
            'device_token_id' => null,
            'status' => NotificationLog::STATUS_QUEUED,
        ]);

        $fcm = $this->mock(\App\Services\FcmPushService::class);
        $fcm->shouldReceive('enabled')->andReturn(true);

        $dispatcher = $this->mock(\App\Services\Notifications\NotificationDispatcher::class);

        $job = new \App\Jobs\SendPushJob($inbox->id);
        $job->handle($fcm, $dispatcher);

        $this->assertDatabaseHas('notification_logs', [
            'notification_id' => $inbox->id,
            'status' => NotificationLog::STATUS_SKIPPED_NO_TOKEN,
        ]);
    }
}
