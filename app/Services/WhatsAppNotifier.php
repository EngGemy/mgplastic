<?php

namespace App\Services;

use App\Jobs\SendWhatsAppNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppNotifier
{
    /**
     * Send one message immediately (OTP / sync).
     * Never notifies admins — caller must not pass admin fan-out here for OTP.
     */
    public function send(string $phone, string $text): void
    {
        $this->deliver([['phone' => $phone, 'text' => $text]]);
    }

    /**
     * Queue a batch of messages (non-OTP business alerts).
     *
     * @param  list<array{phone:string,text:string}>  $messages
     */
    public function sendMany(array $messages): void
    {
        if (! $this->enabled()) {
            return;
        }

        $prepared = $this->prepareMessages($messages);
        if ($prepared === []) {
            return;
        }

        SendWhatsAppNotification::dispatch($prepared);
    }

    public function notifyAdmins(string $text): void
    {
        $messages = [];
        foreach ($this->adminPhones() as $phone) {
            $messages[] = ['phone' => $phone, 'text' => $text];
        }

        $this->sendMany($messages);
    }

    public function notifyUserAndAdmins(?string $userPhone, string $text): void
    {
        $messages = [];

        if (filled($userPhone)) {
            $messages[] = ['phone' => $userPhone, 'text' => $text];
        }

        foreach ($this->adminPhones() as $phone) {
            $messages[] = ['phone' => $phone, 'text' => $text];
        }

        $this->sendMany($messages);
    }

    /**
     * Actual HTTP delivery — used by the queued Job and by sync `send()`.
     *
     * @param  list<array{phone:string,text:string}>  $messages
     */
    public function deliver(array $messages): void
    {
        if (! $this->enabled()) {
            return;
        }

        $prepared = $this->prepareMessages($messages);
        if ($prepared === []) {
            return;
        }

        $url = trim((string) config('services.n8n.url'));
        if ($url === '') {
            Log::error('[WhatsAppNotifier] N8N_NOTIFY_URL is empty');

            return;
        }

        try {
            $response = Http::timeout(10)
                ->retry(2, 300)
                ->withHeaders([
                    'X-Secret' => (string) config('services.n8n.secret'),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($url, ['messages' => $prepared]);

            if (! $response->successful()) {
                Log::error('[WhatsAppNotifier] webhook failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'count' => count($prepared),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[WhatsAppNotifier] webhook exception', [
                'error' => $e->getMessage(),
                'count' => count($prepared),
            ]);
        }
    }

    public function enabled(): bool
    {
        return (bool) config('services.n8n.enabled');
    }

    /**
     * Normalize phone: strip +/spaces/dashes; 0XXXXXXXXXX (11) → 20XXXXXXXXXX.
     */
    public function normalizePhone(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = '20'.substr($digits, 1);
        }

        return $digits !== '' ? $digits : null;
    }

    /**
     * @return list<string>
     */
    public function adminPhones(): array
    {
        $phones = [];
        foreach ((array) config('services.n8n.admin_phones', []) as $phone) {
            $normalized = $this->normalizePhone((string) $phone);
            if ($normalized) {
                $phones[$normalized] = $normalized;
            }
        }

        return array_values($phones);
    }

    /**
     * @param  list<array{phone?:string,text?:string}>  $messages
     * @return list<array{phone:string,text:string}>
     */
    protected function prepareMessages(array $messages): array
    {
        $out = [];
        $seen = [];

        foreach ($messages as $message) {
            $phone = $this->normalizePhone((string) ($message['phone'] ?? ''));
            $text = trim((string) ($message['text'] ?? ''));

            if (! $phone || $text === '') {
                continue;
            }

            $key = $phone.'|'.md5($text);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $out[] = ['phone' => $phone, 'text' => $text];
        }

        return $out;
    }
}
