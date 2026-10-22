<?php

namespace App\Services;

use App\Jobs\SendFcmPushJob;
use App\Models\PushToken;
use App\Models\User;
use App\Support\DeepLink;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

class FcmPushService
{
    public function enabled(): bool
    {
        if (! (bool) config('services.fcm.enabled', true)) {
            return false;
        }

        $credentials = (string) (
            config('firebase.projects.app.credentials')
            ?: env('FIREBASE_CREDENTIALS')
            ?: env('GOOGLE_APPLICATION_CREDENTIALS')
            ?: ''
        );

        if ($credentials === '') {
            return false;
        }

        // Relative paths resolve from base_path().
        $path = str_starts_with($credentials, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:[\\\\/]#', $credentials)
            ? $credentials
            : base_path($credentials);

        return is_file($path);
    }

    /**
     * Queue a push to every active device token for the user.
     *
     * @param  array<string, mixed>  $meta  Same meta used for in-app notifications.
     */
    public function notifyUser(User $user, string $title, ?string $body = null, array $meta = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        $tokens = PushToken::query()
            ->where('user_id', $user->id)
            ->pluck('token')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($tokens === []) {
            return;
        }

        $data = $this->buildDataPayload($meta);

        foreach ($tokens as $token) {
            SendFcmPushJob::dispatch(
                token: (string) $token,
                title: $title,
                body: (string) ($body ?? ''),
                data: $data,
            )->afterCommit();
        }
    }

    /**
     * Send immediately (used by the job).
     *
     * @param  array<string, string>  $data
     */
    public function sendToToken(string $token, string $title, string $body, array $data = []): void
    {
        if (! $this->enabled() || trim($token) === '') {
            return;
        }

        try {
            $messaging = app(Messaging::class);

            $message = CloudMessage::new()
                ->toToken($token)
                ->withNotification(FcmNotification::create($title, $body !== '' ? $body : null))
                ->withData($data)
                ->withAndroidConfig(
                    AndroidConfig::fromArray([
                        'priority' => 'high',
                        'notification' => ['sound' => 'default'],
                    ])
                )
                ->withApnsConfig(
                    ApnsConfig::fromArray([
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                            ],
                        ],
                    ])
                );

            $messaging->send($message);
        } catch (MessagingException $e) {
            $this->forgetInvalidToken($token, $e);
            Log::warning('[FCM] send failed', [
                'error' => $e->getMessage(),
                'token_suffix' => substr($token, -8),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[FCM] send error', [
                'error' => $e->getMessage(),
                'token_suffix' => substr($token, -8),
            ]);
        }
    }

    public function forgetToken(string $token): void
    {
        PushToken::query()->where('token', $token)->delete();
    }

    public function forgetDevice(string $deviceId): void
    {
        PushToken::query()->where('device_id', $deviceId)->delete();
    }

    public function forgetUser(User|int $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;
        PushToken::query()->where('user_id', $userId)->delete();
    }

    /**
     * Upsert by device_id; reassign token if it belonged to another user.
     *
     * @return PushToken
     */
    public function register(User $user, string $token, string $platform, string $deviceId): PushToken
    {
        $token = trim($token);
        $deviceId = trim($deviceId);
        $platform = strtolower(trim($platform)) === 'ios' ? 'ios' : 'android';

        // Same FCM token on another account (device switched login) → move it.
        PushToken::query()
            ->where('token', $token)
            ->where('user_id', '!=', $user->id)
            ->delete();

        return PushToken::query()->updateOrCreate(
            ['device_id' => $deviceId],
            [
                'user_id' => $user->id,
                'token' => $token,
                'platform' => $platform,
                'last_seen_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, string>
     */
    public function buildDataPayload(array $meta): array
    {
        $type = $this->resolveType($meta);
        $id = $this->resolveId($meta, $type);

        $data = [
            'type' => $type,
        ];

        if ($id !== null && $id !== '') {
            $data['id'] = (string) $id;
        }

        if (isset($meta['order_id'])) {
            $data['order_id'] = (string) (int) $meta['order_id'];
        }

        if (isset($meta['order_number'])) {
            $data['order_number'] = (string) $meta['order_number'];
        }

        if (isset($meta['order_status'])) {
            $data['order_status'] = (string) $meta['order_status'];
        }

        $link = $meta['link'] ?? null;
        if (! filled($link) && $type !== 'home' && $id !== null && $id !== '') {
            try {
                $link = DeepLink::for($type, $id);
            } catch (\Throwable) {
                $link = null;
            }
        }

        if (filled($link)) {
            $data['link'] = (string) $link;
        }

        // FCM data values must be strings.
        return array_map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v), $data);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function resolveType(array $meta): string
    {
        $raw = strtolower(trim((string) (
            $meta['type']
            ?? $meta['entity_type']
            ?? $meta['push_type']
            ?? ''
        )));

        $aliases = [
            'orders' => 'order',
            'invoices' => 'invoice',
            'supplies' => 'supply',
            'returns' => 'return',
            'withdrawals' => 'withdrawal',
            'promotions' => 'promotion',
            'collections' => 'collection',
            'events' => 'event',
            'products' => 'product',
            'shops' => 'shop',
            'plumbers' => 'plumber',
        ];

        $type = $aliases[$raw] ?? $raw;

        $allowed = [
            'order', 'invoice', 'supply', 'points', 'return', 'withdrawal',
            'promotion', 'collection', 'event', 'product', 'shop', 'plumber', 'home',
        ];

        return in_array($type, $allowed, true) ? $type : 'home';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function resolveId(array $meta, string $type): int|string|null
    {
        $keyHints = match ($type) {
            'order' => ['order_id', 'id'],
            'invoice', 'supply' => ['invoice_id', 'id'],
            'return' => ['return_id', 'id'],
            'withdrawal' => ['withdrawal_id', 'id'],
            'promotion' => ['promotion_id', 'id'],
            'collection' => ['collection_id', 'id'],
            'event' => ['event_id', 'id'],
            'product' => ['product_id', 'id'],
            'shop' => ['shop_id', 'store_id', 'id'],
            'plumber' => ['plumber_id', 'id'],
            default => ['id'],
        };

        foreach ($keyHints as $key) {
            if (isset($meta[$key]) && $meta[$key] !== '' && $meta[$key] !== null) {
                return $meta[$key];
            }
        }

        return null;
    }

    protected function forgetInvalidToken(string $token, MessagingException $e): void
    {
        $message = strtoupper($e->getMessage());
        $codes = ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND', 'INVALID_REGISTRATION', 'REGISTRATION_TOKEN_NOT_REGISTERED'];

        foreach ($codes as $code) {
            if (str_contains($message, $code)) {
                $this->forgetToken($token);

                return;
            }
        }

        // kreait often exposes errors() with codes
        if (method_exists($e, 'errors')) {
            try {
                $errors = (array) $e->errors();
                $blob = strtoupper(json_encode($errors) ?: '');
                foreach ($codes as $code) {
                    if (str_contains($blob, $code)) {
                        $this->forgetToken($token);

                        return;
                    }
                }
            } catch (\Throwable) {
                // ignore
            }
        }
    }
}
