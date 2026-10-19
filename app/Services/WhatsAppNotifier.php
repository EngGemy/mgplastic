<?php

namespace App\Services;

use App\Jobs\SendWhatsAppNotification;
use App\Support\WhatsAppMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppNotifier
{
    /**
     * Send one message immediately (OTP / sync). Never fans out to admins.
     * Pass $imageUrl OR a brand-card asset array from WhatsAppBrandCard::assetFor().
     *
     * @param  string|array{url?:string,base64?:string,mimetype?:string,filename?:string,path?:string}|null  $image
     */
    public function send(string $phone, string $text, string|array|null $image = null): bool
    {
        return $this->deliver([[
            'phone' => $phone,
            'text' => WhatsAppMessage::rtlBlock($text),
            'image' => $image,
        ]]);
    }

    /**
     * @param  list<array{phone:string,text:string,image?:mixed,image_url?:?string}>  $messages
     */
    public function sendMany(array $messages): void
    {
        if (! $this->enabled()) {
            Log::warning('[WhatsAppNotifier] skipped — WHATSAPP_NOTIFY_ENABLED is false');

            return;
        }

        $prepared = $this->prepareMessages($messages);
        if ($prepared === []) {
            Log::warning('[WhatsAppNotifier] skipped — no valid phones after normalize');

            return;
        }

        if (filter_var(config('services.waha.sync_alerts', false), FILTER_VALIDATE_BOOLEAN)
            || config('queue.default') === 'sync') {
            $this->deliver($prepared);

            return;
        }

        SendWhatsAppNotification::dispatch($prepared)->afterCommit();
    }

    public function notifyAdmins(string $text, string|array|null $image = null): void
    {
        $messages = [];
        foreach ($this->adminPhones() as $phone) {
            $messages[] = ['phone' => $phone, 'text' => $text, 'image' => $image];
        }

        $this->sendMany($messages);
    }

    public function notifyUserAndAdmins(?string $userPhone, string $text, string|array|null $image = null): void
    {
        $this->notifyUsersAndAdmins(
            filled($userPhone) ? [$userPhone] : [],
            $text,
            $image
        );
    }

    /**
     * @param  list<string|null>  $userPhones
     */
    public function notifyUsersAndAdmins(array $userPhones, string $text, string|array|null $image = null): void
    {
        $messages = [];

        foreach ($userPhones as $phone) {
            if (filled($phone)) {
                $messages[] = [
                    'phone' => (string) $phone,
                    'text' => $text,
                    'image' => $image,
                ];
            }
        }

        foreach ($this->adminPhones() as $phone) {
            $messages[] = [
                'phone' => $phone,
                'text' => $text,
                'image' => $image,
            ];
        }

        $this->sendMany($messages);
    }

    /**
     * @param  list<array{phone:string,text:string,image?:mixed,image_url?:?string}>  $messages
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
            Log::error('[WhatsAppNotifier] WAHA_URL is empty — cannot send');

            return false;
        }

        $allOk = true;
        $isFirst = true;

        foreach ($prepared as $message) {
            if (! $isFirst) {
                usleep(1_000_000);
            }
            $isFirst = false;

            $hasImage = $this->hasImagePayload($message['image'] ?? null);
            $ok = false;

            if ($hasImage) {
                $ok = $this->sendImage($url, $message['phone'], $message['text'], $message['image']);
                if (! $ok) {
                    Log::warning('[WhatsAppNotifier] sendImage failed — falling back to sendText', [
                        'phone' => $message['phone'],
                    ]);
                    $ok = $this->sendText($url, $message['phone'], $message['text']);
                }
            } else {
                $ok = $this->sendText($url, $message['phone'], $message['text']);
            }

            if (! $ok) {
                $allOk = false;
            }
        }

        return $allOk;
    }

    public function enabled(): bool
    {
        return (bool) config('services.waha.enabled');
    }

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
     * @param  list<array{phone?:string,text?:string,image?:mixed,image_url?:?string}>  $messages
     * @return list<array{phone:string,text:string,image:mixed}>
     */
    protected function prepareMessages(array $messages): array
    {
        $out = [];
        $seen = [];

        foreach ($messages as $message) {
            $phone = $this->normalizePhone((string) ($message['phone'] ?? ''));
            $text = WhatsAppMessage::rtlBlock(trim((string) ($message['text'] ?? '')));
            $image = $message['image'] ?? $message['image_url'] ?? null;

            if (! $phone || $text === '') {
                continue;
            }

            $imageKey = is_array($image)
                ? md5(($image['filename'] ?? '').($image['url'] ?? '').substr((string) ($image['base64'] ?? ''), 0, 32))
                : (string) $image;

            $key = $phone.'|'.md5($text.'|'.$imageKey);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $out[] = [
                'phone' => $phone,
                'text' => $text,
                'image' => $image,
            ];
        }

        return $out;
    }

    protected function sendText(string $baseUrl, string $phone, string $text): bool
    {
        return $this->postJson($baseUrl.'/api/sendText', [
            'session' => (string) config('services.waha.session', 'default'),
            'chatId' => $phone.'@c.us',
            'text' => $text,
        ], $phone, 'sendText');
    }

    /**
     * Prefer base64 (WAHA often cannot fetch localhost APP_URL). Fall back to file.url.
     *
     * @param  string|array{url?:string,base64?:string,mimetype?:string,filename?:string,path?:string}  $image
     */
    protected function sendImage(string $baseUrl, string $phone, string $caption, string|array $image): bool
    {
        $session = (string) config('services.waha.session', 'default');
        $chatId = $phone.'@c.us';
        $endpoint = $baseUrl.'/api/sendImage';

        $attempts = [];

        if (is_array($image) && filled($image['base64'] ?? null)) {
            $attempts[] = [
                'mode' => 'base64',
                'payload' => [
                    'session' => $session,
                    'chatId' => $chatId,
                    'caption' => $caption,
                    'file' => [
                        'mimetype' => (string) ($image['mimetype'] ?? 'image/png'),
                        'filename' => (string) ($image['filename'] ?? 'mg-card.png'),
                        'data' => (string) $image['base64'],
                    ],
                ],
            ];
        }

        $url = is_array($image) ? ($image['url'] ?? null) : $image;
        if (filled($url)) {
            $attempts[] = [
                'mode' => 'url',
                'payload' => [
                    'session' => $session,
                    'chatId' => $chatId,
                    'caption' => $caption,
                    'file' => [
                        'url' => (string) $url,
                    ],
                ],
            ];
        }

        if ($attempts === []) {
            Log::error('[WhatsAppNotifier] sendImage aborted — no base64 and no url', [
                'phone' => $phone,
            ]);

            return false;
        }

        foreach ($attempts as $attempt) {
            $ok = $this->postJson($endpoint, $attempt['payload'], $phone, 'sendImage:'.$attempt['mode']);
            if ($ok) {
                Log::info('[WhatsAppNotifier] sendImage ok', [
                    'phone' => $phone,
                    'mode' => $attempt['mode'],
                ]);

                return true;
            }
        }

        return false;
    }

    protected function hasImagePayload(mixed $image): bool
    {
        if (is_string($image) && filled($image)) {
            return true;
        }

        if (! is_array($image)) {
            return false;
        }

        return filled($image['base64'] ?? null) || filled($image['url'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postJson(string $endpoint, array $payload, string $phone, string $action): bool
    {
        try {
            $response = Http::timeout(20)
                ->retry(2, 200, throw: false)
                ->withHeaders([
                    'X-Api-Key' => (string) config('services.waha.key'),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, $payload);

            if ($response->successful()) {
                return true;
            }

            // Truncate body; never log API key or full base64
            $body = $response->body();
            if (strlen($body) > 500) {
                $body = substr($body, 0, 500).'…';
            }

            Log::error('[WhatsAppNotifier] WAHA '.$action.' failed', [
                'status' => $response->status(),
                'phone' => $phone,
                'session' => config('services.waha.session'),
                'endpoint' => $endpoint,
                'reason' => $body,
                'hint' => str_contains($action, 'url')
                    ? 'WAHA may not reach APP_URL (localhost). Base64 path should be used.'
                    : 'Check WAHA session is WORKING and /api/sendImage accepts file.data base64.',
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('[WhatsAppNotifier] WAHA '.$action.' exception', [
                'phone' => $phone,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
