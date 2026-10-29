<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class WhatsAppRestartSessionCommand extends Command
{
    protected $signature = 'whatsapp:restart-session
                            {--logout : Logout first (forces QR scan again)}
                            {--status : Only print session status}';

    protected $description = 'Restart WAHA WhatsApp session (fix FAILED → WORKING for OTP)';

    public function handle(): int
    {
        $base = rtrim(trim((string) config('services.waha.url')), '/');
        $session = trim((string) config('services.waha.session', ''));
        $key = (string) config('services.waha.key');

        if ($base === '' || $session === '') {
            $this->error('WAHA_URL or WAHA_SESSION empty in .env');

            return self::FAILURE;
        }

        $headers = [
            'X-Api-Key' => $key,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $status = $this->fetchStatus($base, $session, $headers);
        $this->line("Session: {$session}");
        $this->line('Status:  '.($status ?? 'UNKNOWN'));

        if ($this->option('status')) {
            return strtoupper((string) $status) === 'WORKING' ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('logout')) {
            $this->warn('Logging out session (QR scan will be required)...');
            $logout = Http::timeout(30)->withHeaders($headers)
                ->post("{$base}/api/sessions/{$session}/logout");
            $this->line('Logout HTTP '.$logout->status().': '.$this->shortBody($logout->body()));
            sleep(2);
        }

        $this->info('Restarting session...');
        $restart = Http::timeout(60)->withHeaders($headers)
            ->post("{$base}/api/sessions/{$session}/restart");

        $this->line('Restart HTTP '.$restart->status().': '.$this->shortBody($restart->body()));

        sleep(3);
        $after = $this->fetchStatus($base, $session, $headers);
        $this->line('Status after restart: '.($after ?? 'UNKNOWN'));

        $upper = strtoupper((string) $after);
        if ($upper === 'WORKING') {
            $this->info('Session is WORKING — WhatsApp OTP should work now.');

            return self::SUCCESS;
        }

        if ($upper === 'SCAN_QR_CODE') {
            $this->warn('Session needs QR scan.');
            $this->line("Open WAHA dashboard or: GET {$base}/api/{$session}/auth/qr");
            $this->line('Scan with the WhatsApp phone linked to this bot, then re-check:');
            $this->line('  php artisan whatsapp:restart-session --status');

            return self::FAILURE;
        }

        $this->error('Session still not WORKING. Try with --logout then scan QR.');

        return self::FAILURE;
    }

    /** @param  array<string, string>  $headers */
    protected function fetchStatus(string $base, string $session, array $headers): ?string
    {
        try {
            $response = Http::timeout(15)->withHeaders($headers)
                ->get("{$base}/api/sessions/{$session}");

            if (! $response->successful()) {
                return 'HTTP_'.$response->status();
            }

            $json = $response->json();

            return (string) ($json['status'] ?? $json['engine']['status'] ?? 'UNKNOWN');
        } catch (\Throwable $e) {
            $this->error('Status check failed: '.$e->getMessage());

            return null;
        }
    }

    protected function shortBody(string $body): string
    {
        $body = trim($body);

        return strlen($body) > 300 ? substr($body, 0, 300).'…' : $body;
    }
}
