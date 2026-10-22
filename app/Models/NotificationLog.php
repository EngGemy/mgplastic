<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNREGISTERED = 'unregistered';

    protected $fillable = [
        'notification_id',
        'device_token_id',
        'status',
        'fcm_message_id',
        'error',
        'attempts',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(NotificationInbox::class, 'notification_id');
    }

    public function deviceToken(): BelongsTo
    {
        return $this->belongsTo(PushToken::class, 'device_token_id');
    }
}
