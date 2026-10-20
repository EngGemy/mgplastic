<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppLog extends Model
{
    protected $table = 'whatsapp_logs';

    public $timestamps = false;

    protected $fillable = [
        'event',
        'phone',
        'recipient_type',
        'status',
        'channel',
        'reference_type',
        'reference_id',
        'error',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public static function record(array $attrs): void
    {
        try {
            if (isset($attrs['error']) && is_string($attrs['error']) && strlen($attrs['error']) > 500) {
                $attrs['error'] = substr($attrs['error'], 0, 500).'…';
            }
            $attrs['created_at'] = $attrs['created_at'] ?? now();
            static::query()->create($attrs);
        } catch (\Throwable) {
            // Never break the primary flow because of logging.
        }
    }

    public static function countTodayNonOtp(): int
    {
        return static::query()
            ->whereDate('created_at', now()->toDateString())
            ->where('recipient_type', '!=', 'otp')
            ->whereIn('status', ['sent', 'fallback_text'])
            ->count();
    }
}
