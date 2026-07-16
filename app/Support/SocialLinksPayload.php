<?php

namespace App\Support;

use App\Models\SocialLink;
use Illuminate\Http\Request;

/**
 * Normalizes mobile/client social-link payloads before validation.
 *
 * Accepted shapes:
 * - { links: [{ platform, url }] }
 * - { social_links: [...] } / { data: { links: [...] } }
 * - { links: "[{...}]" }  // JSON string (common in multipart)
 * - { links: [{ platform, link|href|value }] }
 * - { facebook: "https://...", whatsapp: "09..." }
 * - { platform: "facebook", url: "https://..." }
 * - Platform aliases: fb, ig, wa, x, yt, tt, snap, ...
 * - Empty urls are dropped (apps often send every platform slot)
 */
final class SocialLinksPayload
{
    /** @var array<string, string> */
    private const PLATFORM_ALIASES = [
        'fb' => 'facebook',
        'face' => 'facebook',
        'facebook.com' => 'facebook',
        'فيسبوك' => 'facebook',
        'ig' => 'instagram',
        'insta' => 'instagram',
        'instagram.com' => 'instagram',
        'إنستغرام' => 'instagram',
        'انستغرام' => 'instagram',
        'wa' => 'whatsapp',
        'whatsapp_number' => 'whatsapp',
        'واتساب' => 'whatsapp',
        'واتس' => 'whatsapp',
        'x' => 'twitter',
        'twitter.com' => 'twitter',
        'تويتر' => 'twitter',
        'yt' => 'youtube',
        'youtube.com' => 'youtube',
        'يوتيوب' => 'youtube',
        'tt' => 'tiktok',
        'tiktok.com' => 'tiktok',
        'تيك توك' => 'tiktok',
        'تيكتوك' => 'tiktok',
        'web' => 'website',
        'site' => 'website',
        'www' => 'website',
        'موقع' => 'website',
        'snap' => 'snapchat',
        'سناب' => 'snapchat',
        'سناب شات' => 'snapchat',
    ];

    /**
     * @return list<array{platform:string,url:string,sort_order:int}>
     */
    public static function normalize(Request $request): array
    {
        $raw = self::extractRawList($request);

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        // Single associative link: { platform, url }
        if (! array_is_list($raw) && self::rowHasPlatform($raw)) {
            $raw = [$raw];
        }

        // Platform map: { facebook: "url", whatsapp: "09..." }
        if (! array_is_list($raw) && self::looksLikePlatformMap($raw)) {
            $raw = self::mapToRows($raw);
        }

        $out = [];
        foreach (array_values($raw) as $i => $row) {
            if (is_string($row)) {
                $decoded = self::decodeJson($row);
                if (is_array($decoded)) {
                    $row = $decoded;
                } else {
                    continue;
                }
            }

            if (! is_array($row)) {
                continue;
            }

            // Nested accidentally: { "0": { platform, url } } already handled by array_values
            if (! self::rowHasPlatform($row) && self::looksLikePlatformMap($row)) {
                foreach (self::mapToRows($row) as $mapped) {
                    $normalized = self::normalizeRow($mapped, $i);
                    if ($normalized !== null) {
                        $out[] = $normalized;
                    }
                }
                continue;
            }

            $normalized = self::normalizeRow($row, $i);
            if ($normalized !== null) {
                $out[] = $normalized;
            }
        }

        // Deduplicate by platform (last wins)
        $byPlatform = [];
        foreach ($out as $item) {
            $byPlatform[$item['platform']] = $item;
        }

        return array_values($byPlatform);
    }

    public static function mergeIntoRequest(Request $request): void
    {
        $request->merge(['links' => self::normalize($request)]);
    }

    /**
     * Keys present on the request (for clearer 422 hints).
     *
     * @return list<string>
     */
    public static function receivedKeys(Request $request): array
    {
        return array_values(array_keys($request->all()));
    }

    private static function extractRawList(Request $request): mixed
    {
        // Single link at root
        if ($request->filled('platform') && self::requestHasUrl($request)) {
            return [[
                'platform' => $request->input('platform'),
                'url' => self::requestUrl($request),
                'sort_order' => (int) $request->input('sort_order', 0),
            ]];
        }

        $candidates = [
            $request->input('links'),
            $request->input('social_links'),
            $request->input('socialLinks'),
            $request->input('data.links'),
            $request->input('data.social_links'),
            $request->input('data'),
        ];

        foreach ($candidates as $candidate) {
            $candidate = self::maybeDecode($candidate);
            if ($candidate === null || $candidate === '' || $candidate === []) {
                continue;
            }
            if (is_array($candidate)) {
                // data: { platform, url }
                if (self::rowHasPlatform($candidate)) {
                    return [$candidate];
                }
                // data: { facebook: "..." }
                if (self::looksLikePlatformMap($candidate)) {
                    return self::mapToRows($candidate);
                }
                // data: { links: [...] }
                if (isset($candidate['links'])) {
                    $inner = self::maybeDecode($candidate['links']);
                    if (is_array($inner) && $inner !== []) {
                        return $inner;
                    }
                }
                if (isset($candidate['social_links'])) {
                    $inner = self::maybeDecode($candidate['social_links']);
                    if (is_array($inner) && $inner !== []) {
                        return $inner;
                    }
                }

                return $candidate;
            }
        }

        // Root platform map even when empty links[] was sent
        if (self::looksLikePlatformMap($request->except(['links', 'social_links', 'socialLinks', 'data']))) {
            return self::mapToRows($request->except(['links', 'social_links', 'socialLinks', 'data']));
        }

        if (self::looksLikePlatformMap($request->all())) {
            return self::mapToRows($request->all());
        }

        return [];
    }

    private static function requestHasUrl(Request $request): bool
    {
        foreach (['url', 'link', 'href', 'value', 'social_url'] as $key) {
            if ($request->filled($key)) {
                return true;
            }
        }

        return false;
    }

    private static function requestUrl(Request $request): mixed
    {
        foreach (['url', 'link', 'href', 'value', 'social_url'] as $key) {
            if ($request->filled($key)) {
                return $request->input($key);
            }
        }

        return null;
    }

    private static function rowHasPlatform(array $row): bool
    {
        return isset($row['platform']) || isset($row['type']) || isset($row['name']);
    }

    /**
     * @return list<array{platform:string,url:mixed,sort_order:int}>
     */
    private static function mapToRows(array $raw): array
    {
        $items = [];
        $i = 0;
        foreach ($raw as $platform => $url) {
            if (! is_string($platform) || in_array($platform, ['links', 'social_links', 'socialLinks', 'data', 'platform', 'url'], true)) {
                continue;
            }
            $items[] = [
                'platform' => $platform,
                'url' => $url,
                'sort_order' => $i++,
            ];
        }

        return $items;
    }

    private static function looksLikePlatformMap(array $raw): bool
    {
        if ($raw === [] || array_is_list($raw)) {
            return false;
        }

        if (isset($raw['links']) || isset($raw['social_links']) || isset($raw['socialLinks'])) {
            return false;
        }

        if (self::rowHasPlatform($raw) && (isset($raw['url']) || isset($raw['link']) || isset($raw['href']))) {
            return false;
        }

        foreach (array_keys($raw) as $key) {
            if (! is_string($key)) {
                continue;
            }
            if (self::resolvePlatform($key) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{platform:string,url:string,sort_order:int}|null
     */
    private static function normalizeRow(array $row, int $index): ?array
    {
        $platformRaw = strtolower(trim((string) ($row['platform'] ?? $row['type'] ?? $row['name'] ?? '')));
        $platform = self::resolvePlatform($platformRaw);

        $url = trim((string) (
            $row['url']
            ?? $row['link']
            ?? $row['href']
            ?? $row['value']
            ?? $row['social_url']
            ?? $row['username']
            ?? ''
        ));

        if ($platform === null || $url === '' || in_array(strtolower($url), ['null', 'undefined', '-', 'n/a'], true)) {
            return null;
        }

        return [
            'platform' => $platform,
            'url' => self::normalizeUrl($platform, $url),
            'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : $index,
        ];
    }

    private static function resolvePlatform(string $key): ?string
    {
        $key = strtolower(trim($key));
        if ($key === '') {
            return null;
        }

        if (array_key_exists($key, SocialLink::PLATFORMS)) {
            return $key;
        }

        return self::PLATFORM_ALIASES[$key] ?? null;
    }

    private static function maybeDecode(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return self::decodeJson($trimmed) ?? $value;
    }

    private static function decodeJson(string $value): mixed
    {
        if ($value === '' || ($value[0] !== '[' && $value[0] !== '{')) {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private static function normalizeUrl(string $platform, string $url): string
    {
        // Bare @username for social platforms
        if (str_starts_with($url, '@')) {
            $handle = ltrim($url, '@');
            $url = match ($platform) {
                'instagram' => 'https://instagram.com/'.$handle,
                'facebook' => 'https://facebook.com/'.$handle,
                'twitter' => 'https://x.com/'.$handle,
                'tiktok' => 'https://tiktok.com/@'.$handle,
                'youtube' => 'https://youtube.com/@'.$handle,
                'snapchat' => 'https://snapchat.com/add/'.$handle,
                default => $url,
            };
        }

        if ($platform === 'whatsapp') {
            $digits = preg_replace('/\D+/', '', $url) ?? '';
            if ($digits !== '' && ! str_contains(strtolower($url), 'http') && ! str_contains(strtolower($url), 'wa.me')) {
                return 'https://wa.me/'.$digits;
            }
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.ltrim($url, '/');
        }

        return $url;
    }
}
