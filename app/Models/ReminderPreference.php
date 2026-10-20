<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderPreference extends Model
{
    protected $fillable = [
        'user_id', 'weekly_enabled', 'daily_points_enabled', 'quiet_hours',
        'last_weekly_sent_at', 'last_daily_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'weekly_enabled' => 'boolean',
            'daily_points_enabled' => 'boolean',
            'quiet_hours' => 'array',
            'last_weekly_sent_at' => 'datetime',
            'last_daily_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function forUser(User $user): self
    {
        return static::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['weekly_enabled' => true, 'daily_points_enabled' => true]
        );
    }
}
