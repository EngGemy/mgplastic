<?php

namespace App\Models;

use App\Support\UnicodeText;
use Illuminate\Database\Eloquent\Model;

class ProductTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'description', 'usage'];

    protected static function booted(): void
    {
        static::saving(function (ProductTranslation $row): void {
            foreach (['name', 'description', 'usage'] as $field) {
                if (! filled($row->{$field})) {
                    continue;
                }
                $row->{$field} = UnicodeText::nfkc((string) $row->{$field});
            }
        });
    }
}
