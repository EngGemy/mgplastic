<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    protected $fillable = [
        'number',
        'title',
        'subtitle',
        'factory_customer_id',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(FactoryCustomer::class, 'factory_customer_id');
    }

    /**
     * Copy the factory customer onto the printed quote so later edits do not rewrite old lists.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withCustomerSnapshot(array $data): array
    {
        if (empty($data['factory_customer_id'])) {
            return $data;
        }

        $customer = FactoryCustomer::query()->find($data['factory_customer_id']);
        if (! $customer) {
            return $data;
        }

        $data['customer_company'] = $customer->company_name;
        $data['customer_name'] = $customer->contact_name;
        $data['customer_phone'] = $customer->phone;

        return $data;
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
