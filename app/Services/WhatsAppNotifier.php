<?php

namespace App\Services;

use App\Jobs\SendWhatsAppNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppNotifier
{
    /**
     * Send one message immediately (OTP / sync). Never fans out to admins.
     */
    public function send(string $phone, string $text): bool
    {
        return $this->deliver([['phone' => $phone, 'text' => $text]]);
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
     * Deliver via WAHA sendText. Returns true only if every message succeeds.
     *
     * @param  list<array{phone:string,text:string}>  $messages
     */
    public function deliver(array $messages): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $prepared = $this->prepareMessages($messages);
        if ($prepared === []) {
            return false;
        }

        $url = rtrim(trim((string) config('services.waha.url')), '/');
        if ($url === '') {
            Log::error('[WhatsAppNotifier] WAHA_URL is empty');

            return false;
        }

        $allOk = true;
        $isFirst = true;

        foreach ($prepared as $message) {
            if (! $isFirst) {
                usleep(1_000_000);
            }
            $isFirst = false;

            if (! $this->sendText($url, $message['phone'], $message['text'])) {
                $allOk = false;
            }
        }

        return $allOk;
    }

    public function enabled(): bool
    {
        return (bool) config('services.waha.enabled');
    }

    /**
     * Normalize phone:
     * - strip non-digits and leading 00
     * - 0 + 10 digits → 218… (Libya)
     * - 0 + 11 digits → 20… (Egypt)
     * - already starts with 218 or 20 → leave as-is
     * - bare local → prefix default_country_code
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

        while (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '218') || str_starts_with($digits, '20')) {
            return $digits;
        }

        $len = strlen($digits);

        if (str_starts_with($digits, '0') && $len === 10) {
            return '218'.substr($digits, 1);
        }

        if (str_starts_with($digits, '0') && $len === 11) {
            return '20'.substr($digits, 1);
        }

        $defaultCc = preg_replace('/\D+/', '', (string) config('services.waha.default_country_code', '218')) ?: '218';
        if (! str_starts_with($digits, '0') && $len >= 8 && $len <= 10) {
            return $defaultCc.$digits;
        }

        return $digits;
    }

    /**
     * @return list<string>
     */
    public function adminPhones(): array
    {
        $phones = [];
        foreach ((array) config('services.waha.admin_phones', []) as $phone) {
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

    protected function sendText(string $baseUrl, string $phone, string $text): bool
    {
        $endpoint = $baseUrl.'/api/sendText';
        $session = (string) config('services.waha.session', 'default');
        $apiKey = (string) config('services.waha.key');

        try {
            $response = Http::timeout(8)
                ->retry(2, 200, throw: false)
                ->withHeaders([
                    'X-Api-Key' => $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, [
                    'session' => $session,
                    'chatId' => $phone.'@c.us',
                    'text' => $text,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('[WhatsAppNotifier] WAHA sendText failed', [
                'status' => $response->status(),
                'phone' => $phone,
                'session' => $session,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('[WhatsAppNotifier] WAHA sendText exception', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
