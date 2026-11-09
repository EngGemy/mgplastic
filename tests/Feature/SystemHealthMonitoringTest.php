<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotification;
use App\Mail\SystemHealthAlertMail;
use App\Models\SystemHealthState;
use App\Services\HealthAlertService;
use App\Services\SystemHealthService;
use App\Services\WhatsAppNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SystemHealthMonitoringTest extends TestCase
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
            'services.waha.sync_alerts' => true,
            'services.waha.breaker_ttl' => 300,
            'services.waha.alert_emails' => ['ops@example.com'],
            'services.waha.admin_phones' => ['218912159316'],
            'monitoring.waha_breaker_probe_seconds' => 300,
            'monitoring.waha_session_cache_seconds' => 60,
            'monitoring.alert_repeat_hours' => 6,
            'cache.default' => 'array',
        ]);

        Cache::flush();
    }

    public function test_waha_session_check_uses_http_fake_and_marks_critical_when_not_working(): void
    {
        Http::fake([
            'https://waha.test/api/sessions/session_test' => Http::response([
                'status' => 'SCAN_QR_CODE',
            ], 200),
        ]);

        $check = app(SystemHealthService::class)->checkWahaSession();

        $this->assertSame('waha_session', $check->key);
        $this->assertSame('critical', $check->status);
        $this->assertStringContainsString('HTTP 200', (string) $check->details);
        $this->assertStringContainsString('SCAN_QR_CODE', (string) $check->details);
    }

    public function test_waha_session_ok_when_working(): void
    {
        Http::fake([
            'https://waha.test/api/sessions/session_test' => Http::response([
                'status' => 'WORKING',
            ], 200),
        ]);

        $check = app(SystemHealthService::class)->checkWahaSession();
        $this->assertSame('ok', $check->status);
    }

    public function test_breaker_half_open_probe_closes_on_http_200(): void
    {
        $notifier = app(WhatsAppNotifier::class);
        $notifier->openBreaker('auth failed (401)');

        // Force probe due immediately.
        $payload = $notifier->breakerPayload();
        Cache::put(WhatsAppNotifier::BREAKER_CACHE_KEY, array_merge($payload ?? [], [
            'next_probe_at' => now()->subMinute()->toIso8601String(),
        ]), 3600);

        Http::fake([
            'https://waha.test/api/sessions/session_test' => Http::response(['status' => 'WORKING'], 200),
        ]);

        $this->assertTrue($notifier->probeBreakerIfDue());
        $this->assertFalse($notifier->isBreakerOpen());
        Http::assertSentCount(1);
    }

    public function test_breaker_probe_stays_open_on_401(): void
    {
        $notifier = app(WhatsAppNotifier::class);
        $notifier->openBreaker('unauthorized');

        Cache::put(WhatsAppNotifier::BREAKER_CACHE_KEY, array_merge($notifier->breakerPayload() ?? [], [
            'next_probe_at' => now()->subMinute()->toIso8601String(),
        ]), 3600);

        Http::fake([
            'https://waha.test/api/sessions/session_test' => Http::response(['error' => 'no'], 401),
        ]);

        $this->assertFalse($notifier->probeBreakerIfDue());
        $this->assertTrue($notifier->isBreakerOpen());
    }

    public function test_alert_fires_on_ok_to_critical_and_not_on_repeat_immediately(): void
    {
        Mail::fake();

        Http::fake([
            'https://waha.test/api/sessions/session_test' => Http::response(['status' => 'FAILED'], 500),
        ]);

        // Seed other checks toward ok where possible.
        SystemHealthState::putString(SystemHealthState::KEY_QUEUE_HEARTBEAT, now()->toIso8601String());
        Cache::put(config('monitoring.queue_heartbeat_key'), now()->toIso8601String(), 3600);

        $service = app(HealthAlertService::class);
        $first = $service->evaluateAndAlert();
        $this->assertNotEmpty($first['alerted']);
        Mail::assertSent(SystemHealthAlertMail::class, 1);

        // Immediate second run should not spam (no repeat yet).
        $second = $service->evaluateAndAlert();
        $this->assertSame([], $second['alerted']);
        $this->assertSame([], $second['repeated']);
        Mail::assertSent(SystemHealthAlertMail::class, 1);
    }

    public function test_alert_sends_recovered_when_returning_to_ok(): void
    {
        Mail::fake();
        SystemHealthState::putString(SystemHealthState::KEY_QUEUE_HEARTBEAT, now()->toIso8601String());
        Cache::put(config('monitoring.queue_heartbeat_key'), now()->toIso8601String(), 3600);

        Http::fake([
            'https://waha.test/api/sessions/session_test' => Http::sequence()
                ->push(['status' => 'FAILED'], 500)
                ->push(['status' => 'WORKING'], 200)
                ->push(['status' => 'WORKING'], 200),
        ]);

        $service = app(HealthAlertService::class);
        $service->evaluateAndAlert();
        Mail::assertSent(SystemHealthAlertMail::class, fn (SystemHealthAlertMail $m) => $m->kind === 'degraded');

        Cache::forget('monitoring:waha_session_check');
        $result = $service->evaluateAndAlert();
        $this->assertContains('waha_session', $result['recovered']);
        Mail::assertSent(SystemHealthAlertMail::class, fn (SystemHealthAlertMail $m) => $m->kind === 'recovered');
    }

    public function test_whatsapp_job_uses_retry_until_instead_of_small_tries_budget(): void
    {
        $job = new SendWhatsAppNotification([[
            'phone' => '218912159316',
            'text' => 'hi',
            'recipient_type' => 'admin',
        ]]);

        $this->assertInstanceOf(\DateTimeInterface::class, $job->retryUntil());
        $this->assertGreaterThan(now()->addHours(11), $job->retryUntil());
        $this->assertSame(100, $job->tries);
    }

    public function test_monitor_health_command_runs(): void
    {
        Http::fake([
            'https://waha.test/api/sessions/session_test' => Http::response(['status' => 'WORKING'], 200),
        ]);
        SystemHealthState::putString(SystemHealthState::KEY_QUEUE_HEARTBEAT, now()->toIso8601String());
        Cache::put(config('monitoring.queue_heartbeat_key'), now()->toIso8601String(), 3600);
        Mail::fake();

        $this->artisan('monitor:health')
            ->assertSuccessful();
    }

    public function test_waha_breaker_check_reports_open_state(): void
    {
        app(WhatsAppNotifier::class)->openBreaker('auth failed (401)');
        $check = app(SystemHealthService::class)->checkWahaBreaker();

        $this->assertSame('critical', $check->status);
        $this->assertSame('open', $check->value);
        $this->assertStringContainsString('auth failed', (string) $check->details);
    }
}
