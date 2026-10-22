<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Build public storage URLs only when the file actually exists.
 * Missing files → null (never a 404 URL for mobile clients).
 */
final class PublicStorageUrl
{
    public static function try(?string $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        $path = trim($path);

        // Absolute URL that points at our /storage/… disk → verify relative file.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $relative = self::relativeFromPublicUrl($path);
            if ($relative === null) {
                // External CDN / unknown host — keep as-is.
                return $path;
            }

            return Storage::disk('public')->exists($relative)
                ? Storage::disk('public')->url($relative)
                : null;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');

        // Strip accidental "storage/" prefix from stored values.
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (! Storage::disk('public')->exists($path)) {
            // Optional fallback under public/images (product-type icons, etc.)
            $publicFile = public_path('images/'.$path);
            if (is_file($publicFile)) {
                return asset('images/'.$path);
            }

            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Extract disk-relative path from …/storage/foo/bar.jpg URLs.
     */
    public static function relativeFromPublicUrl(string $url): ?string
    {
        $urlPath = parse_url($url, PHP_URL_PATH);
        if (! is_string($urlPath) || $urlPath === '') {
            return null;
        }

        if (! preg_match('#/storage/(.+)$#', $urlPath, $m)) {
            return null;
        }

        return ltrim(rawurldecode($m[1]), '/');
    }
}
