<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class WhatsAppRestartSessionCommand extends Command
{
    protected $signature = 'whatsapp:restart-session
                            {--logout : Logout first (forces QR scan again)}
                            {--status : Only print session status}
                            {--wait=90 : Seconds to wait for WORKING / SCAN_QR_CODE}';

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
            return $this->finishFromStatus($base, $session, $status);
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

        $wait = max(5, (int) $this->option('wait'));
        $this->line("Waiting up to {$wait}s for WORKING or SCAN_QR_CODE...");

        $after = $this->waitForSettled($base, $session, $headers, $wait);
        $this->line('Status after wait: '.($after ?? 'UNKNOWN'));

        return $this->finishFromStatus($base, $session, $after);
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function waitForSettled(string $base, string $session, array $headers, int $seconds): ?string
    {
        $deadline = time() + $seconds;
        $last = null;

        while (time() < $deadline) {
            $last = $this->fetchStatus($base, $session, $headers);
            $upper = strtoupper((string) $last);

            if (in_array($upper, ['WORKING', 'SCAN_QR_CODE', 'FAILED', 'STOPPED'], true)) {
                return $last;
            }

            $this->line('  … '.$upper);
            sleep(3);
        }

        return $last;
    }

    protected function finishFromStatus(string $base, string $session, ?string $status): int
    {
        $upper = strtoupper((string) $status);

        if ($upper === 'WORKING') {
            $this->info('Session is WORKING — WhatsApp OTP should work now.');

            return self::SUCCESS;
        }

        if ($upper === 'SCAN_QR_CODE') {
            $this->warn('Session needs QR scan.');
            $this->line("QR: {$base}/api/{$session}/auth/qr   (header X-Api-Key)");
            $this->line('Or open the WAHA dashboard → Sessions → scan with the bot phone.');
            $this->line('Then: php artisan whatsapp:restart-session --status');

            return self::FAILURE;
        }

        if ($upper === 'STARTING') {
            $this->warn('Still STARTING — wait ~30s then run:');
            $this->line('  php artisan whatsapp:restart-session --status');

            return self::FAILURE;
        }

        $this->error('Session not WORKING ('.$upper.'). Try:');
        $this->line('  php artisan whatsapp:restart-session --logout --wait=120');

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
