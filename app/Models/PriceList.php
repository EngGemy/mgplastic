<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    protected $fillable = [
        'number',
        'title',
        'subtitle',
        'customer_name',
        'customer_company',
        'customer_phone',
        'issued_on',
        'valid_until',
        'notes',
        'status',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'valid_until' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (PriceList $list) {
            if (blank($list->number)) {
                $list->number = static::nextNumber();
            }
        });
    }

    public static function nextNumber(): string
    {
        $year = now()->format('Y');
        $count = static::query()->whereYear('created_at', $year)->count() + 1;

        return 'PL-'.$year.'-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class)->orderBy('sort')->orderBy('id');
    }

    public function customerLabel(): ?string
    {
        $parts = array_values(array_filter([
            $this->customer_company,
            $this->customer_name,
        ]));

        return $parts === [] ? null : implode(' — ', $parts);
    }
}
