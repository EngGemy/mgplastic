<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = ['product_id', 'image', 'sort'];

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            if (! $row->isDirty('image')) {
                return;
            }
            $old = $row->getOriginal('image');
            if (filled($old) && $old !== $row->image) {
                Storage::disk('public')->delete($old);
            }
        });

        static::deleting(function (self $row) {
            if (filled($row->image)) {
                Storage::disk('public')->delete($row->image);
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
