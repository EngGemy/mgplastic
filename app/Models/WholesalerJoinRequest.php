<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesalerJoinRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'wholesaler_id',
        'retail_trader_id',
        'status',
        'message',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function wholesaler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'wholesaler_id');
    }

    public function retailTrader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retail_trader_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'message' => $this->message,
            'rejection_reason' => $this->rejection_reason,
            'wholesaler_id' => (int) $this->wholesaler_id,
            'retail_trader_id' => (int) $this->retail_trader_id,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'wholesaler' => $this->relationProfile('wholesaler'),
            'retail_trader' => $this->relationProfile('retailTrader'),
        ];
    }

    /** @return array<string, mixed>|null */
    protected function relationProfile(string $relation): ?array
    {
        if (! $this->relationLoaded($relation) || ! $this->{$relation}) {
            return null;
        }

        /** @var User $u */
        $u = $this->{$relation};

        return [
            'id' => $u->id,
            'name' => $u->name,
            'brand_name' => $u->brand_name,
            'phone' => $u->phone,
            'network_code' => $u->network_code,
            'profile_photo' => $u->profile_photo_url,
            'city' => $u->relationLoaded('city') && $u->city
                ? [
                    'id' => $u->city->id,
                    'name' => $u->city->{app()->getLocale() === 'ar' ? 'name_ar' : 'name_en'},
                ]
                : null,
        ];
    }
}
