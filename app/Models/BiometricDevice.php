<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricDevice extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'device_name',
        'platform',
        'public_key',
        'unlock_token_hash',
        'is_active',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matchesUnlockToken(string $plainToken): bool
    {
        if (! filled($this->unlock_token_hash) || $plainToken === '') {
            return false;
        }

        return hash_equals($this->unlock_token_hash, hash('sha256', $plainToken));
    }
}
