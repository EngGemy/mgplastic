<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Durable key/value state for monitoring (survives cache:clear / optimize:clear).
 */
class SystemHealthState extends Model
{
    public const KEY_QUEUE_HEARTBEAT = 'queue_worker_heartbeat';

    public const KEY_QUEUE_RESTARTED_AT = 'queue_restarted_at';

    protected $fillable = [
        'key',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public static function tableReady(): bool
    {
        try {
            return Schema::hasTable((new static)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    public static function getPayload(string $key, mixed $default = null): mixed
    {
        if (! self::tableReady()) {
            return $default;
        }

        $row = static::query()->where('key', $key)->first();

        return $row?->payload ?? $default;
    }

    public static function putPayload(string $key, mixed $payload): void
    {
        if (! self::tableReady()) {
            return;
        }

        static::query()->updateOrCreate(
            ['key' => $key],
            ['payload' => $payload],
        );
    }

    public static function getString(string $key, ?string $default = null): ?string
    {
        $payload = self::getPayload($key);
        if (is_string($payload)) {
            return $payload;
        }
        if (is_array($payload) && isset($payload['value']) && is_string($payload['value'])) {
            return $payload['value'];
        }

        return $default;
    }

    public static function putString(string $key, string $value): void
    {
        self::putPayload($key, ['value' => $value]);
    }

    public static function alertStateKey(string $checkKey): string
    {
        return 'alert_state:'.$checkKey;
    }
}
