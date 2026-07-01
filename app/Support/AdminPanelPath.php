<?php

namespace App\Support;

class AdminPanelPath
{
    public static function segment(): string
    {
        $path = trim((string) config('portal.admin_path', 'admin'), '/');

        return $path !== '' ? $path : 'admin';
    }

    public static function url(string $suffix = ''): string
    {
        $base = '/'.static::segment();
        $suffix = ltrim($suffix, '/');

        return $suffix !== '' ? $base.'/'.$suffix : $base;
    }

    public static function hidesLegacyAdminUrl(): bool
    {
        return static::segment() !== 'admin';
    }
}
