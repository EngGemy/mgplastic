<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'kind', 'status', 'channel', 'ai_used', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'ai_used' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
