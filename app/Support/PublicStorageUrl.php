<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Build public storage URLs only when the file is web-reachable.
 * Missing / unlinked files → null (never a 404 URL for mobile clients).
 */
final class PublicStorageUrl
{
    /** @var array<string, bool> Request-local reachability cache (avoids repeated exists/is_file). */
    protected static array $reachable = [];

    /** @deprecated Use try() — kept so older call sites never 500. */
    public static function make(?string $path): ?string
    {
        return self::try($path);
    }

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

            return self::publicUrlIfReachable($relative);
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');

        // Strip accidental "storage/" prefix from stored values.
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        // Optional fallback under public/images (product-type icons, etc.)
        if (! self::diskExists($path)) {
            $publicFile = public_path('images/'.$path);
            if (is_file($publicFile)) {
                return asset('images/'.$path);
            }

            return null;
        }

        return self::publicUrlIfReachable($path);
    }

    /**
     * File must exist on the public disk AND be reachable via /storage/…
     * (covers missing `php artisan storage:link` which otherwise yields HTTP 404).
     */
    protected static function publicUrlIfReachable(string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ($relative === '' || ! self::isReachable($relative)) {
            return null;
        }

        return Storage::disk('public')->url($relative);
    }

    protected static function isReachable(string $relative): bool
    {
        if (array_key_exists($relative, self::$reachable)) {
            return self::$reachable[$relative];
        }

        if (! self::diskExists($relative)) {
            return self::$reachable[$relative] = false;
        }

        // Must be reachable via /storage/… (missing storage:link ⇒ HTTP 404 for clients).
        $webPath = public_path('storage/'.$relative);

        return self::$reachable[$relative] = is_file($webPath);
    }

    protected static function diskExists(string $relative): bool
    {
        return Storage::disk('public')->exists($relative);
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

    /** Clear request-local cache (tests). */
    public static function flushCache(): void
    {
        self::$reachable = [];
    }
}
