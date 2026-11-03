<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OcrImage extends Model
{
    protected $fillable = [
        'batch_id',
        'position',
        'path',
        'sha256',
        'width',
        'height',
        'status',
        'attempts',
        'last_error_code',
        'next_retry_at',
        'model_used',
        'lines_count',
        'replaced_by_id',
        'read_version',
        'quality_warning',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'next_retry_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'quality_warning' => 'boolean',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OcrBatch::class, 'batch_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OcrImageLine::class, 'image_id');
    }

    public function currentLines(): HasMany
    {
        return $this->lines()->where('version', $this->read_version);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, ['done', 'empty', 'failed', 'canceled'], true);
    }
}
