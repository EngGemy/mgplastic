<?php

namespace App\Models;

use App\Support\UnicodeText;
use Illuminate\Database\Eloquent\Model;

class ProductCategoryTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'description'];

    protected static function booted(): void
    {
        static::saving(function (ProductCategoryTranslation $row): void {
            foreach (['name', 'description'] as $field) {
                if (! filled($row->{$field})) {
                    continue;
                }
                $row->{$field} = UnicodeText::nfkc((string) $row->{$field});
            }
        });
    }
}
