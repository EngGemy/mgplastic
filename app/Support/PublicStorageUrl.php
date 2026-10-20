<?php

namespace App\Support;

/**
 * Build absolute public URLs for files on the "public" disk.
 * Mobile apps cannot load Filament-relative or localhost storage paths.
 */
class PublicStorageUrl
{
    public static function make(mixed $path): ?string
    {
        if (is_array($path)) {
            $path = $path[0] ?? null;
        }

        $path = is_string($path) ? trim($path) : '';
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Strip accidental "storage/" or "/storage/" prefixes from DB values
        $path = preg_replace('#^/?storage/#', '', $path) ?? $path;

        return url('storage/'.ltrim($path, '/'));
    }
}
