<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiToolCall extends Model
{
    protected $fillable = [
        'tool',
        'arguments',
        'ok',
        'http_status',
        'error',
        'result_meta',
        'ip',
        'user_agent',
        'duration_ms',
    ];

    protected $casts = [
        'arguments' => 'array',
        'result_meta' => 'array',
        'ok' => 'boolean',
    ];
}
