<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlumberScoreEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'type', 'reference_type', 'reference_id', 'points_delta', 'meta', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
            'points_delta' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
