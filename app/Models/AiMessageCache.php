<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMessageCache extends Model
{
    protected $table = 'ai_message_cache';

    public $timestamps = false;

    protected $fillable = ['key', 'kind', 'text', 'created_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
