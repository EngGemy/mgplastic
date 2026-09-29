<?php

namespace App\Support;

use App\Models\SocialLink;
use Illuminate\Http\Request;

/**
 * Normalizes mobile/client social-link payloads before validation.
 *
 * Accepted shapes:
 * - { links: [{ platform, url }] }
 * - { social_links: [...] }
 * - { links: "[{...}]" }  // JSON string
 * - { links: [{ platform, link|href|value }] }  // url aliases
 * - { facebook: "https://...", whatsapp: "09..." }  // map by platform
 * - { platform, url }  // single link at root
 * - { data: { links: [...] } }
 * - Form data with empty urls for unused platforms (empties are dropped)
 */
final class SocialLinksPayload
{
    /** Short aliases clients often send instead of canonical platform keys. */
    private const PLATFORM_ALIASES = [
        'fb' => 'facebook',
        'face' => 'facebook',
        'ig' => 'instagram',
        'insta' => 'instagram',
        'wa' => 'whatsapp',
        'whats' => 'whatsapp',
        'x' => 'twitter',
        'tweet' => 'twitter',
        'yt' => 'youtube',
        'tt' => 'tiktok',
        'web' => 'website',
        'site' => 'website',
        'snap' => 'snapchat',
    ];

    /**
     * @return list<array{platform:string,url:string,sort_order:int}>
     */
    public static function normalize(Request $request): array
    {
        $raw = self::extractRaw($request);

        if ($raw === null || $raw === [] || $raw === '') {
            return [];
        }

        // JSON string payload
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        // Single link object at root: { platform, url }
        if (self::looksLikeSingleLink($raw)) {
            $raw = [$raw];
        }

        // Map style: { "facebook": "https://...", "instagram": "..." }
        if (self::looksLikePlatformMap($raw)) {
            $items = [];
            $i = 0;
            foreach ($raw as $platform => $url) {
                $items[] = [
                    'platform' => (string) $platform,
                    'url' => $url,
                    'sort_order' => $i++,
                ];
            }
            $raw = $items;
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach (array_values($raw) as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $platform = self::canonicalizePlatform((string) (
                $row['platform'] ?? $row['type'] ?? $row['name'] ?? ''
            ));

            $url = trim((string) (
                $row['url']
                ?? $row['link']
                ?? $row['href']
                ?? $row['value']
                ?? $row['social_url']
                ?? ''
            ));

            if ($platform === '' || $url === '') {
                continue;
            }

            if (! array_key_exists($platform, SocialLink::PLATFORMS)) {
                continue;
            }

            $out[] = [
                'platform' => $platform,
                'url' => self::normalizeUrl($platform, $url),
                'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : $i,
            ];
        }

        return $out;
    }

    public static function mergeIntoRequest(Request $request): void
    {
        $request->merge(['links' => self::normalize($request)]);
    }

    private static function extractRaw(Request $request): mixed
    {
        if ($request->exists('links')) {
            return $request->input('links');
        }

        if ($request->exists('social_links')) {
            return $request->input('social_links');
        }

        if ($request->filled('data.links')) {
            return $request->input('data.links');
        }

        $all = $request->all();

        // Prefer nested data.links when present inside "data"
        if (isset($all['data']) && is_array($all['data']) && array_key_exists('links', $all['data'])) {
            return $all['data']['links'];
        }

        return $all;
    }

    private static function canonicalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return self::PLATFORM_ALIASES[$platform] ?? $platform;
    }

    private static function looksLikeSingleLink(array $raw): bool
    {
        if ($raw === [] || array_is_list($raw)) {
            return false;
        }

        $hasPlatform = isset($raw['platform']) || isset($raw['type']) || isset($raw['name']);
        $hasUrl = isset($raw['url']) || isset($raw['link']) || isset($raw['href'])
            || isset($raw['value']) || isset($raw['social_url']);

        return $hasPlatform && $hasUrl;
    }

    private static function looksLikePlatformMap(array $raw): bool
    {
        if ($raw === [] || array_is_list($raw)) {
            return false;
        }

        if (self::looksLikeSingleLink($raw)) {
            return false;
        }

        $keys = array_map('strtolower', array_map('strval', array_keys($raw)));
        $platformKeys = array_keys(SocialLink::PLATFORMS);
        $aliasKeys = array_keys(self::PLATFORM_ALIASES);
        $known = array_merge($platformKeys, $aliasKeys);

        return count(array_intersect($keys, $known)) > 0
            && ! isset($raw[0])
            && ! isset($raw['platform'])
            && ! isset($raw['type']);
    }

    private static function normalizeUrl(string $platform, string $url): string
    {
        $url = trim($url);

        // WhatsApp: accept phone numbers
        if ($platform === 'whatsapp') {
            $digits = preg_replace('/\D+/', '', $url) ?? '';
            if ($digits !== '' && ! str_contains($url, 'http') && ! str_contains($url, 'wa.me')) {
                return 'https://wa.me/'.$digits;
            }
        }

        // @handle → platform profile URL
        if (str_starts_with($url, '@')) {
            $handle = ltrim($url, '@');

            return match ($platform) {
                'instagram' => 'https://instagram.com/'.$handle,
                'tiktok' => 'https://tiktok.com/@'.$handle,
                'twitter' => 'https://twitter.com/'.$handle,
                'youtube' => 'https://youtube.com/@'.$handle,
                'facebook' => 'https://facebook.com/'.$handle,
                'snapchat' => 'https://snapchat.com/add/'.$handle,
                default => 'https://'.ltrim($url, '/'),
            };
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.ltrim($url, '/');
        }

        return $url;
    }
}
