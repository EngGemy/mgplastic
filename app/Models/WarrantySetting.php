<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class WarrantySetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = Cache::remember("warranty_setting:{$key}", 300, function () use ($key) {
            return static::query()->where('key', $key)->first();
        });
        $val = $row?->value;

        return $val ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("warranty_setting:{$key}");
    }
}
