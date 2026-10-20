<?php

namespace App\Console\Commands;

use App\Mail\WhatsAppSessionAlertMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WhatsAppHealthCommand extends Command
{
    protected $signature = 'whatsapp:health';

    protected $description = 'Check WAHA session status and alert once per outage';

    public function handle(): int
    {
        $base = rtrim(trim((string) config('services.waha.url')), '/');
        $session = trim((string) config('services.waha.session', ''));
        $key = (string) config('services.waha.key');

        if ($base === '' || $session === '') {
            $this->warn('WAHA_URL or WAHA_SESSION empty — skip');

            return self::SUCCESS;
        }

        $cacheKey = 'whatsapp:session_down:'.$session;
        $status = null;
        $detail = '';

        try {
            $response = Http::timeout(12)
                ->withHeaders([
                    'X-Api-Key' => $key,
                    'Accept' => 'application/json',
                ])
                ->get("{$base}/api/sessions/{$session}");

            if (! $response->successful()) {
                $status = 'HTTP_'.$response->status();
                $detail = 'HTTP '.$response->status();
            } else {
                $json = $response->json();
                $status = (string) ($json['status'] ?? $json['engine']['status'] ?? 'UNKNOWN');
                $detail = 'status='.$status;
            }
        } catch (\Throwable $e) {
            $status = 'ERROR';
            $detail = 'connection failed';
            Log::warning('[whatsapp:health] request failed', ['error' => $e->getMessage()]);
        }

        $working = strtoupper((string) $status) === 'WORKING';

        if ($working) {
            if (Cache::pull($cacheKey)) {
                $this->mailAlert('restored', $session, $detail);
                $this->info("Session {$session} restored (WORKING)");
            } else {
                $this->line("Session {$session}: WORKING");
            }

            return self::SUCCESS;
        }

        $this->error("Session {$session}: {$status}");

        if (Cache::add($cacheKey, 1, now()->addHours(6))) {
            $this->mailAlert('down', $session, $detail);
            $this->warn('Alert email sent (once per outage window)');
        } else {
            $this->line('Outage already alerted within 6h — skip email');
        }

        return self::SUCCESS;
    }

    protected function mailAlert(string $kind, string $session, string $detail): void
    {
        $emails = (array) config('services.waha.alert_emails', []);
        if ($emails === []) {
            Log::warning('[whatsapp:health] no WHATSAPP_ALERT_EMAILS', ['kind' => $kind]);

            return;
        }
        try {
            Mail::to($emails)->send(new WhatsAppSessionAlertMail($kind, $session, $detail));
        } catch (\Throwable $e) {
            Log::warning('[whatsapp:health] mail failed', ['error' => $e->getMessage()]);
        }
    }
}
