<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlumberScore extends Model
{
    protected $fillable = [
        'user_id', 'score', 'level', 'components', 'confirmed_distributions_count',
    ];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'score' => 'integer',
            'confirmed_distributions_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function levelLabel(): string
    {
        return (string) (config('reputation.levels.'.$this->level.'.label') ?? $this->level);
    }
}
