<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Resolve promotion videos into a playable preview (upload | YouTube | Vimeo | Drive | direct).
 *
 * @phpstan-type EmbedArray array{
 *   kind: 'empty'|'file'|'youtube'|'vimeo'|'drive'|'direct'|'unknown',
 *   embed_url: ?string,
 *   playback_url: ?string,
 *   label: string,
 *   can_iframe: bool,
 *   can_html5: bool
 * }
 */
final class VideoEmbed
{
    /**
     * @return EmbedArray
     */
    public static function resolve(?string $externalUrl = null, ?string $uploadedPath = null): array
    {
        $path = self::normalizeUploadPath($uploadedPath);
        if ($path !== null) {
            // Admin preview: prefer public URL even if storage:link check fails.
            $playback = PublicStorageUrl::try($path);
            if (! $playback && Storage::disk('public')->exists($path)) {
                $playback = Storage::disk('public')->url($path);
            }

            return [
                'kind' => 'file',
                'embed_url' => null,
                'playback_url' => $playback,
                'label' => 'ملف على السيرفر',
                'can_iframe' => false,
                'can_html5' => filled($playback),
            ];
        }

        $url = trim((string) $externalUrl);
        if ($url === '') {
            return self::empty();
        }

        if ($yt = self::youtubeId($url)) {
            return [
                'kind' => 'youtube',
                'embed_url' => 'https://www.youtube.com/embed/'.$yt.'?rel=0&modestbranding=1',
                'playback_url' => 'https://www.youtube.com/watch?v='.$yt,
                'label' => 'YouTube',
                'can_iframe' => true,
                'can_html5' => false,
            ];
        }

        if ($vm = self::vimeoId($url)) {
            return [
                'kind' => 'vimeo',
                'embed_url' => 'https://player.vimeo.com/video/'.$vm,
                'playback_url' => 'https://vimeo.com/'.$vm,
                'label' => 'Vimeo',
                'can_iframe' => true,
                'can_html5' => false,
            ];
        }

        if ($drive = self::driveId($url)) {
            return [
                'kind' => 'drive',
                'embed_url' => 'https://drive.google.com/file/d/'.$drive.'/preview',
                'playback_url' => 'https://drive.google.com/file/d/'.$drive.'/view',
                'label' => 'Google Drive',
                'can_iframe' => true,
                'can_html5' => false,
            ];
        }

        if (self::looksLikeDirectVideo($url)) {
            return [
                'kind' => 'direct',
                'embed_url' => null,
                'playback_url' => $url,
                'label' => 'رابط مباشر',
                'can_iframe' => false,
                'can_html5' => true,
            ];
        }

        return [
            'kind' => 'unknown',
            'embed_url' => null,
            'playback_url' => $url,
            'label' => 'رابط خارجي',
            'can_iframe' => false,
            'can_html5' => false,
        ];
    }

    /**
     * @return EmbedArray
     */
    private static function empty(): array
    {
        return [
            'kind' => 'empty',
            'embed_url' => null,
            'playback_url' => null,
            'label' => 'لا يوجد فيديو',
            'can_iframe' => false,
            'can_html5' => false,
        ];
    }

    private static function normalizeUploadPath(mixed $uploadedPath): ?string
    {
        if (is_array($uploadedPath)) {
            $uploadedPath = $uploadedPath[0] ?? null;
        }
        $path = trim((string) $uploadedPath);

        return $path !== '' ? $path : null;
    }

    private static function youtubeId(string $url): ?string
    {
        if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/|youtube\.com/shorts/)([A-Za-z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function vimeoId(string $url): ?string
    {
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function driveId(string $url): ?string
    {
        if (preg_match('~drive\.google\.com/file/d/([^/]+)~', $url, $m)) {
            return $m[1];
        }
        if (preg_match('~drive\.google\.com/open\?id=([^&]+)~', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function looksLikeDirectVideo(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return (bool) preg_match('~\.(mp4|webm|ogg|mov|m4v)(\?|$)~', $path);
    }
}
