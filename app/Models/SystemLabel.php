<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SystemLabel extends Model
{
    protected $fillable = ['key', 'default_value', 'custom_value', 'group', 'description'];

    public static function get(string $key, string $fallback = ''): string
    {
        if (! Schema::hasTable('system_labels')) {
            return $fallback;
        }

        try {
            return Cache::remember("system_label_{$key}", 3600, function () use ($key, $fallback) {
                $label = static::where('key', $key)->first();
                if (! $label) {
                    return $fallback;
                }

                return $label->custom_value ?: $label->default_value;
            });
        } catch (\Throwable) {
            return $fallback;
        }
    }

    protected static function booted(): void
    {
        static::saved(fn ($label) => Cache::forget("system_label_{$label->key}"));
        static::deleted(fn ($label) => Cache::forget("system_label_{$label->key}"));
    }
}
