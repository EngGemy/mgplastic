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
 * - { links: [{ platform, link|href|value }] }  // url aliases
 * - { facebook: "https://...", whatsapp: "09..." }  // map by platform
 * - Form data with empty urls for unused platforms (empties are dropped)
 */
final class SocialLinksPayload
{
    /**
     * @return list<array{platform:string,url:string,sort_order:int}>
     */
    public static function normalize(Request $request): array
    {
        $raw = $request->input('links', $request->input('social_links', $request->all()));

        // Map style: { "facebook": "https://...", "instagram": "..." }
        if (is_array($raw) && self::looksLikePlatformMap($raw)) {
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

            $platform = strtolower(trim((string) ($row['platform'] ?? $row['type'] ?? $row['name'] ?? '')));
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

    private static function looksLikePlatformMap(array $raw): bool
    {
        if ($raw === [] || array_is_list($raw)) {
            return false;
        }

        $keys = array_keys($raw);
        $platformKeys = array_keys(SocialLink::PLATFORMS);

        return count(array_intersect($keys, $platformKeys)) > 0
            && ! isset($raw[0])
            && ! isset($raw['platform']);
    }

    private static function normalizeUrl(string $platform, string $url): string
    {
        // WhatsApp: accept phone numbers
        if ($platform === 'whatsapp') {
            $digits = preg_replace('/\D+/', '', $url) ?? '';
            if ($digits !== '' && ! str_contains($url, 'http') && ! str_contains($url, 'wa.me')) {
                return 'https://wa.me/'.$digits;
            }
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.ltrim($url, '/');
        }

        return $url;
    }
}
