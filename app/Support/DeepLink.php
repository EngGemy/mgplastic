<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Builds HTTPS Universal Links that open the MG app on the right screen.
 * Never embeds OTP or secrets — IDs only; auth/authorization happens in-app.
 *
 * HTTPS (WhatsApp-safe):  https://mg-plastic.com/open/{type}/{id}
 * Custom scheme (native): mgplastic://{type}/{id}
 * Android intent:         intent://{type}/{id}#Intent;scheme=mgplastic;package=…;end
 */
class DeepLink
{
    public static function for(string $type, int|string|null $id = null, ?Model $model = null): string
    {
        $type = self::normalizeType($type);
        $resolvedId = self::resolveId($type, $id, $model);
        $base = rtrim((string) config('app_links.public_base', config('app.url')), '/');

        return "{$base}/open/{$type}/{$resolvedId}";
    }

    /** In-app path the RN/Expo router should handle (after auth). */
    public static function appPath(string $type, int|string|null $id = null, ?Model $model = null): string
    {
        $type = self::normalizeType($type);
        $resolvedId = self::resolveId($type, $id, $model);

        return $type === 'home' ? 'home' : "{$type}/{$resolvedId}";
    }

    public static function customScheme(string $type, int|string|null $id = null, ?Model $model = null): string
    {
        $scheme = (string) config('app_links.scheme', 'mgplastic');
        $path = self::appPath($type, $id, $model);

        return "{$scheme}://{$path}";
    }

    public static function androidIntent(string $type, int|string|null $id = null, ?Model $model = null): string
    {
        $scheme = (string) config('app_links.scheme', 'mgplastic');
        $package = (string) config('app_links.package', 'com.MgTools');
        $path = self::appPath($type, $id, $model);
        $fallback = rawurlencode(self::for($type, $id, $model));

        return "intent://{$path}#Intent;scheme={$scheme};package={$package};S.browser_fallback_url={$fallback};end";
    }

    public static function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        $allowed = (array) config('app_links.types', []);

        return in_array($type, $allowed, true) ? $type : 'home';
    }

    public static function isAllowedType(string $type): bool
    {
        return in_array(strtolower(trim($type)), (array) config('app_links.types', []), true);
    }

    protected static function resolveId(string $type, int|string|null $id, ?Model $model): int|string
    {
        $resolved = $id ?? ($model?->getKey() ?? 0);
        if ($type === 'home') {
            return $resolved !== '' && $resolved !== null ? $resolved : 0;
        }

        return $resolved === '' || $resolved === null ? 0 : $resolved;
    }
}
