<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationInbox extends Model
{
    protected $table = 'notifications_inbox';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'priority',
        'idempotency_key',
        'filament_notification_id',
        'read_at',
        'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'opened_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class, 'notification_id');
    }

    public function markRead(): void
    {
        if ($this->read_at !== null) {
            return;
        }

        $this->forceFill([
            'read_at' => now(),
            'opened_at' => $this->opened_at ?? now(),
        ])->save();
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
