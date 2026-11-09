<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class OcrBatch extends Model
{
    protected $fillable = [
        'user_id',
        'purpose',
        'source',
        'supplier_user_id',
        'distributor_id',
        'status',
        'idempotency_token',
        'cart_added_at',
    ];

    protected function casts(): array
    {
        return [
            'cart_added_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(OcrImage::class, 'batch_id')->orderBy('position');
    }

    public function lines(): HasManyThrough
    {
        return $this->hasManyThrough(OcrImageLine::class, OcrImage::class, 'batch_id', 'image_id');
    }

    public function ensureIdempotencyToken(): string
    {
        if ($this->idempotency_token) {
            return $this->idempotency_token;
        }

        $this->idempotency_token = (string) Str::uuid();
        $this->save();

        return $this->idempotency_token;
    }

    public function isSettled(): bool
    {
        return $this->images()
            ->whereNotIn('status', ['done', 'empty', 'failed', 'canceled'])
            ->doesntExist();
    }

    public function refreshStatusFromImages(): void
    {
        $statuses = $this->images()->pluck('status');
        if ($statuses->isEmpty()) {
            $this->status = 'draft';
            $this->save();

            return;
        }

        if ($statuses->contains(fn ($s) => in_array($s, ['queued', 'reading', 'retrying'], true))) {
            $this->status = 'reading';
        } elseif ($this->status === 'added') {
            // keep
        } else {
            $this->status = 'review';
        }
        $this->save();
    }
}
