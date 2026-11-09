<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrUsage extends Model
{
    public $timestamps = false;

    protected $table = 'ocr_usage';

    protected $fillable = [
        'user_id',
        'role',
        'batch_id',
        'image_id',
        'model',
        'input_tokens',
        'output_tokens',
        'duration_ms',
        'success',
        'kind',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OcrBatch::class, 'batch_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(OcrImage::class, 'image_id');
    }
}
