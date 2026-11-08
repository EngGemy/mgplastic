<?php

namespace App\Console\Commands;

use App\Mail\WhatsAppSessionAlertMail;
use App\Services\WhatsAppNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WhatsAppHealthCommand extends Command
{
    protected $signature = 'whatsapp:health';

    protected $description = 'Check WAHA reachability, auth, session, breaker, queue backlog';

    public function handle(WhatsAppNotifier $notifier): int
    {
        $base = rtrim(trim((string) config('services.waha.url')), '/');
        $session = trim((string) config('services.waha.session', ''));
        $key = (string) config('services.waha.key');

        $reachable = false;
        $authOk = null;
        $status = null;
        $detail = '';
        $reasons = [];

        if ($base === '' || $session === '') {
            $reasons[] = 'WAHA_URL or WAHA_SESSION empty';
            $this->error(implode('; ', $reasons));
            $this->storeSnapshot(false, implode('; ', $reasons));

            return self::FAILURE;
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(6)
                ->withHeaders([
                    'X-Api-Key' => $key,
                    'Accept' => 'application/json',
                ])
                ->get("{$base}/api/sessions/{$session}");

            $reachable = true;

            if ($response->status() === 401 || $response->status() === 403) {
                $authOk = false;
                $status = 'HTTP_'.$response->status();
                $detail = 'Unauthorized';
                $reasons[] = 'auth failed ('.$response->status().')';
                $notifier->openBreaker('unauthorized');
            } elseif (! $response->successful()) {
                $authOk = true;
                $status = 'HTTP_'.$response->status();
                $detail = 'HTTP '.$response->status();
                $reasons[] = $detail;
            } else {
                $authOk = true;
                $json = $response->json();
                $status = (string) ($json['status'] ?? $json['engine']['status'] ?? 'UNKNOWN');
                $detail = 'status='.$status;
                if (strtoupper($status) !== 'WORKING') {
                    $reasons[] = 'session '.$status;
                    $notifier->openBreaker('session_down: '.$status);
                } else {
                    $notifier->resetBreaker('whatsapp:health');
                }
            }
        } catch (\Throwable $e) {
            $status = 'ERROR';
            $detail = 'connection failed';
            $reasons[] = 'unreachable: '.$e->getMessage();
            $notifier->openBreaker('unreachable');
            Log::warning('[whatsapp:health] request failed', ['error' => $e->getMessage()]);
        }

        $breakerOpen = $notifier->isBreakerOpen();
        if ($breakerOpen) {
            $reasons[] = 'breaker open ('.$notifier->breakerReason().')';
        }

        $backlog = $this->whatsappQueueBacklog();
        $lastSuccess = Cache::get(WhatsAppNotifier::LAST_SUCCESS_CACHE_KEY);

        $this->line('Session: '.$session);
        $this->line('Reachable: '.($reachable ? 'yes' : 'no'));
        $this->line('Auth: '.($authOk === null ? 'n/a' : ($authOk ? 'ok' : 'FAIL')));
        $this->line('Status: '.($status ?? 'UNKNOWN'));
        $this->line('Breaker: '.($breakerOpen ? 'OPEN' : 'closed'));
        $this->line('Queue backlog (whatsapp): '.$backlog);
        $this->line('Last success: '.($lastSuccess ?: 'never'));

        $healthy = $reachable && $authOk === true && strtoupper((string) $status) === 'WORKING' && ! $breakerOpen;
        $reasonText = $healthy ? 'ok' : implode('; ', $reasons ?: ['unhealthy']);

        $this->storeSnapshot($healthy, $reasonText, [
            'session' => $session,
            'status' => $status,
            'backlog' => $backlog,
            'last_success' => $lastSuccess,
            'breaker' => $breakerOpen,
            'breaker_reason' => $notifier->breakerReason(),
            'breaker_opened_at' => $notifier->breakerOpenedAt(),
        ]);

        $this->alertOnStateChange($healthy, $session, $reasonText);

        if ($healthy) {
            $this->info('WAHA healthy');

            return self::SUCCESS;
        }

        $this->error('WAHA unhealthy: '.$reasonText);

        return self::FAILURE;
    }

    protected function whatsappQueueBacklog(): int
    {
        try {
            $queue = (string) config('services.waha.queue', 'whatsapp');

            return (int) DB::table('jobs')->where('queue', $queue)->count();
        } catch (\Throwable) {
            return -1;
        }
    }

    /** @param  array<string, mixed>  $extra */
    protected function storeSnapshot(bool $ok, string $reason, array $extra = []): void
    {
        Cache::put(WhatsAppNotifier::HEALTH_CACHE_KEY, array_merge([
            'ok' => $ok,
            'reason' => $reason,
            'checked_at' => now()->toIso8601String(),
        ], $extra), now()->addMinutes(30));
    }

    protected function alertOnStateChange(bool $healthy, string $session, string $detail): void
    {
        $stateKey = 'whatsapp:health_state';
        $previous = Cache::get($stateKey); // null | healthy | unhealthy
        $next = $healthy ? 'healthy' : 'unhealthy';
        Cache::put($stateKey, $next, now()->addDays(2));

        // First observation of DOWN (or transition healthy→unhealthy): alert once.
        if (! $healthy && $previous !== 'unhealthy') {
            Log::error('WAHA DOWN: '.$detail);
            $this->mailAlert('down', $session, $detail);
        }

        if ($healthy && $previous === 'unhealthy') {
            Log::info('WAHA restored: '.$detail);
            $this->mailAlert('restored', $session, $detail);
        }
    }

    protected function mailAlert(string $kind, string $session, string $detail): void
    {
        $emails = (array) config('services.waha.alert_emails', []);
        if ($emails === []) {
            return;
        }
        try {
            Mail::to($emails)->send(new WhatsAppSessionAlertMail($kind, $session, $detail));
        } catch (\Throwable $e) {
            Log::warning('[whatsapp:health] mail failed', ['error' => $e->getMessage()]);
        }
    }
}
