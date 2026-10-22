<?php

namespace App\Models;

use App\Support\UserRoles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationBroadcast extends Model
{
    protected $fillable = [
        'sent_by',
        'title',
        'body',
        'status',
        'audience',
        'role',
        'user_ids',
        'segment',
        'recipients_count',
        'preview_count',
        'url',
        'action_label',
        'push_type',
        'push_id',
        'meta',
        'sent_push',
        'sent_at',
        'schedule_at',
    ];

    protected function casts(): array
    {
        return [
            'user_ids' => 'array',
            'segment' => 'array',
            'meta' => 'array',
            'sent_push' => 'boolean',
            'sent_at' => 'datetime',
            'schedule_at' => 'datetime',
            'recipients_count' => 'integer',
            'preview_count' => 'integer',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function audienceLabel(): string
    {
        return match ($this->audience) {
            'user' => 'مستخدم واحد',
            'users' => 'مجموعة مستخدمين',
            'role' => 'دور: '.UserRoles::label($this->role),
            'plumbers' => 'كل السباكين',
            'retailers' => 'كل تجار القطاعي',
            'wholesalers' => 'كل موزعي الجملة',
            'all' => 'جميع المستخدمين',
            'custom' => 'مخصص',
            default => $this->audience ?: '—',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'success' => 'نجاح',
            'warning' => 'تحذير',
            'danger' => 'تنبيه هام',
            default => 'معلومة',
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'success' => 'success',
            'warning' => 'warning',
            'danger' => 'danger',
            default => 'info',
        };
    }
}
