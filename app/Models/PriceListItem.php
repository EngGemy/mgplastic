<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    protected $fillable = [
        'price_list_id',
        'product_id',
        'item_name',
        'pieces_per_box',
        'piece_price_lyd',
        'piece_price_aed',
        'box_price_lyd',
        'box_price_aed',
        'sort',
    ];

    protected $casts = [
        'pieces_per_box' => 'integer',
        'piece_price_lyd' => 'decimal:3',
        'piece_price_aed' => 'decimal:3',
        'box_price_lyd' => 'decimal:3',
        'box_price_aed' => 'decimal:3',
        'sort' => 'integer',
    ];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function packLabel(): string
    {
        $count = (int) $this->pieces_per_box;

        return $count > 0 ? $count.'-قطعة' : '—';
    }

    public function money(string $field): string
    {
        $value = $this->{$field};
        if ($value === null || $value === '') {
            return '0';
        }

        $number = (float) $value;
        if (abs($number - round($number)) < 0.0005) {
            return (string) (int) round($number);
        }

        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }
}
