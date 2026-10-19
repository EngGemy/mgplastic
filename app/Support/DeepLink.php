<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Builds HTTPS Universal Links that open the MG app on the right screen.
 * Never embeds OTP or secrets — IDs only; auth/authorization happens in-app.
 */
class DeepLink
{
    public static function for(string $type, int|string|null $id = null, ?Model $model = null): string
    {
        $type = self::normalizeType($type);
        $resolvedId = $id ?? ($model?->getKey() ?? 0);
        if ($type === 'home') {
            $resolvedId = $resolvedId ?: 0;
        }

        $base = rtrim((string) config('app_links.public_base', config('app.url')), '/');

        return "{$base}/open/{$type}/{$resolvedId}";
    }

    public static function customScheme(string $type, int|string $id): string
    {
        $scheme = (string) config('app_links.scheme', 'mgplastic');
        $type = self::normalizeType($type);

        return "{$scheme}://{$type}/{$id}";
    }

    public static function androidIntent(string $type, int|string $id): string
    {
        $scheme = (string) config('app_links.scheme', 'mgplastic');
        $package = (string) config('app_links.package', 'com.mgplastic.app');
        $type = self::normalizeType($type);
        $fallback = urlencode(self::for($type, $id));

        return "intent://{$type}/{$id}#Intent;scheme={$scheme};package={$package};S.browser_fallback_url={$fallback};end";
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
}
