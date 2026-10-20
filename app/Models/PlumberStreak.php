<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlumberStreak extends Model
{
    protected $fillable = [
        'user_id', 'current_weeks', 'best_weeks', 'last_active_week',
        'freezes_available', 'last_freeze_reset',
    ];

    protected function casts(): array
    {
        return [
            'last_active_week' => 'date',
            'last_freeze_reset' => 'date',
            'current_weeks' => 'integer',
            'best_weeks' => 'integer',
            'freezes_available' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
