<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrTermAlias extends Model
{
    protected $fillable = [
        'term_normalized',
        'family_key',
        'source',
        'status',
        'hits',
        'distinct_users',
        'user_ids',
        'example_raw',
        'last_seen_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'distinct_users' => 'integer',
            'user_ids' => 'array',
            'last_seen_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function mayAutoAccept(): bool
    {
        return $this->isApproved();
    }
}
