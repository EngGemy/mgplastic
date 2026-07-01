<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppFlag extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];
    protected $casts = ['value' => 'array'];

    public static function getBool(string $key, bool $default = false): bool
    {
        $flag = static::query()->where('key', $key)->value('value');
        if ($flag === null) return $default;

        // value can be {"enabled":true} or a raw bool-ish
        $arr = is_array($flag) ? $flag : (is_string($flag) ? json_decode($flag, true) : null);
        if (is_array($arr) && array_key_exists('enabled', $arr)) {
            return (bool) $arr['enabled'];
        }
        return (bool) $flag;
    }

    public static function setBool(string $key, bool $enabled, ?int $updatedBy = null): self
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => ['enabled' => $enabled], 'updated_by' => $updatedBy]
        );
    }
}
