<?php

namespace App\Services;

use ArPHP\I18N\Arabic;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Themed MG WhatsApp cover cards (JPEG) for WAHA sendImage.
 * Size from WHATSAPP_CARD_SIZE (e.g. 1080x1350 or 1080x1080).
 *
 * @phpstan-type CardAsset array{
 *   relative: string, path: string, url: string, base64: string,
 *   mimetype: string, filename: string, theme: string
 * }
 */
class WhatsAppBrandCard
{
    /** @var \GdImage|null */
    protected static $logoCache = null;

    protected static ?int $logoCacheW = null;

    protected static ?int $logoCacheH = null;

    /**
     * @param  array<string, mixed>  $payload
     * @return CardAsset|null
     */
    public function assetFor(string $themeKey, array $payload = []): ?array
    {
        try {
            $themeKey = $this->resolveThemeKey($themeKey);
            $theme = $this->theme($themeKey);
            $relative = $this->ensureCard($themeKey, $theme, $payload);
            $path = Storage::disk('public')->path($relative);
            if (! is_file($path)) {
                return null;
            }

            $binary = (string) file_get_contents($path);

            return [
                'relative' => $relative,
                'path' => $path,
                'url' => $this->absoluteUrl($relative),
                'base64' => base64_encode($binary),
                'mimetype' => 'image/jpeg',
                'filename' => basename($relative),
                'theme' => $themeKey,
            ];
        } catch (\Throwable $e) {
            Log::error('[WhatsAppBrandCard] generate failed', [
                'theme' => $themeKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function resolveThemeKey(string $key): string
    {
        $key = trim($key);
        $aliases = (array) config('whatsapp_cards.aliases', []);
        if (isset($aliases[$key])) {
            $key = $aliases[$key];
        }
        $themes = (array) config('whatsapp_cards.themes', []);

        return array_key_exists($key, $themes) ? $key : 'default';
    }

    public function themeForOrderStatus(?string $status): string
    {
        return ((array) config('whatsapp_cards.order_status_theme', []))[$status] ?? 'order_status_review';
    }

    public function themeForReturnStatus(?string $status): string
    {
        return ((array) config('whatsapp_cards.return_status_theme', []))[$status] ?? 'return_created';
    }

    /** @return array<string, mixed> */
    public function theme(string $key): array
    {
        $themes = (array) config('whatsapp_cards.themes', []);

        return $themes[$this->resolveThemeKey($key)] ?? $themes['default'];
    }

    /** @param  array<string, mixed>  $payload */
    public function renderToPath(string $themeKey, string $absolutePath, array $payload = []): bool
    {
        $themeKey = $this->resolveThemeKey($themeKey);
        $jpeg = $this->renderJpeg($themeKey, $this->theme($themeKey), $payload);
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($absolutePath, $jpeg) !== false;
    }

    /** @return array{0:int,1:int} */
    public function dimensions(): array
    {
        $raw = (string) (config('services.waha.card_size') ?: config('whatsapp_cards.card_size', '1080x1350'));
        if (preg_match('/^(\d{3,4})x(\d{3,4})$/i', trim($raw), $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        $w = (int) config('whatsapp_cards.width', 1080);
        $h = (int) config('whatsapp_cards.height', 1350);

        return [$w > 0 ? $w : 1080, $h > 0 ? $h : 1350];
    }

    /**
     * @param  array<string, mixed>  $theme
     * @param  array<string, mixed>  $payload
     */
    protected function ensureCard(string $themeKey, array $theme, array $payload): string
    {
        [$w, $h] = $this->dimensions();
        $hash = substr(md5($themeKey.'|'.json_encode($payload, JSON_UNESCAPED_UNICODE)."|v7-{$w}x{$h}"), 0, 12);
        $relative = "whatsapp-cards/mg-{$themeKey}-{$hash}.jpg";
        if (Storage::disk('public')->exists($relative)) {
            return $relative;
        }
        Storage::disk('public')->makeDirectory('whatsapp-cards');
        Storage::disk('public')->put($relative, $this->renderJpeg($themeKey, $theme, $payload));

        return $relative;
    }

    /**
     * @param  array<string, mixed>  $theme
     * @param  array<string, mixed>  $payload
     */
    protected function renderJpeg(string $themeKey, array $theme, array $payload): string
    {
        [$w, $h] = $this->dimensions();
        $img = imagecreatetruecolor($w, $h);

        // Brand blue + status accent only
        $mgBlue = [8, 40, 110];
        $accent = $theme['accent'] ?? [13, 71, 161];
        $this->fillGradient($img, $mgBlue, $accent);

        $accentCol = imagecolorallocate($img, $accent[0], $accent[1], $accent[2]);
        $white = imagecolorallocate($img, 255, 255, 255);
        $ink = imagecolorallocate($img, 18, 28, 50);
        $muted = imagecolorallocate($img, 100, 115, 145);
        $line = imagecolorallocate($img, 220, 228, 240);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 100);

        $pad = 64;
        $cardX = $pad;
        $cardY = (int) ($h * 0.08);
        $cardW = $w - ($pad * 2);
        $cardH = (int) ($h * 0.78);

        // Soft shadow
        $this->roundedRect($img, $cardX + 8, $cardY + 10, $cardW, $cardH, 36, $shadow);
        $this->roundedRect($img, $cardX, $cardY, $cardW, $cardH, 36, $white);
        imagefilledrectangle($img, $cardX, $cardY, $cardX + $cardW, $cardY + 8, $accentCol);

        $font = $this->fontPath();
        $this->paintLogo($img, (int) ($w / 2), $cardY + 90, 96);

        if ($font) {
            $this->centerArabic($img, $font, 26, $cardY + 175, 'MG للصناعات البلاستيكية', $ink, $cardW - 80);

            $badge = $this->truncate($payload['badge'] ?? $theme['badge_text'] ?? 'إشعار', 24);
            $this->drawBadge($img, $font, (int) ($w / 2), $cardY + 230, $badge, $accentCol, $white);

            $title = $this->truncate((string) ($payload['title'] ?? $theme['title_ar'] ?? ''), 36);
            $this->centerArabic($img, $font, 32, $cardY + 300, $title, $ink, $cardW - 100);

            $this->drawIcon($img, (string) ($theme['icon'] ?? 'bell'), (int) ($w / 2), $cardY + 380, $accentCol, $white);

            $y = $cardY + 460;
            $hero = trim((string) ($payload['hero'] ?? ''));
            if ($hero !== '') {
                $heroDraw = $this->containsArabic($hero) ? $this->shapeArabic($hero) : $hero;
                $this->centerText($img, $font, 48, $y, $this->truncateRaw($heroDraw, 28), $accentCol);
                $y += 48;
                $heroLabel = trim((string) ($payload['hero_label'] ?? ''));
                if ($heroLabel !== '') {
                    $this->centerArabic($img, $font, 17, $y, $this->truncate($heroLabel, 40), $muted, $cardW - 120);
                    $y += 36;
                }
            }

            imageline($img, $cardX + 70, $y, $cardX + $cardW - 70, $y, $line);
            $y += 40;

            // Client line (optional) + max 2 detail rows
            $client = trim((string) ($payload['client'] ?? ''));
            /** @var list<array{0:string,1:string}> $rows */
            $rows = [];
            if ($client !== '') {
                $rows[] = ['عميل', $client];
            }
            foreach (array_slice($payload['rows'] ?? [], 0, 2) as $row) {
                if (is_array($row) && count($row) >= 2) {
                    $rows[] = [(string) $row[0], (string) $row[1]];
                }
            }
            $rows = array_slice($rows, 0, 3);

            foreach ($rows as [$label, $value]) {
                $this->drawRow($img, $font, $cardX + 80, $cardX + $cardW - 80, $y, $label, $value, $muted, $ink);
                $y += 44;
            }

            if (! empty($theme['show_progress']) || isset($payload['progress_index'])) {
                $y += 12;
                $idx = (int) ($payload['progress_index'] ?? $theme['progress_index'] ?? 0);
                $this->drawProgress($img, $font, $cardX + 60, $y, $cardW - 120, $idx, $accentCol, $muted, $line, $white);
            }

            $this->centerArabic($img, $font, 15, $h - 48, 'إشعار رسمي · mg-plastic.com', $white, $w - 80);
        }

        $quality = (int) (config('services.waha.card_quality') ?: config('whatsapp_cards.jpeg_quality', 85));
        ob_start();
        imagejpeg($img, null, max(60, min(95, $quality)));
        $binary = (string) ob_get_clean();
        imagedestroy($img);

        return $binary;
    }

    /** @param  array{0:int,1:int,2:int}  $from @param  array{0:int,1:int,2:int}  $to */
    protected function fillGradient(\GdImage $img, array $from, array $to): void
    {
        $h = imagesy($img);
        $w = imagesx($img);
        for ($y = 0; $y < $h; $y++) {
            $t = $y / max(1, $h - 1);
            $color = imagecolorallocate(
                $img,
                (int) ($from[0] + ($to[0] - $from[0]) * $t),
                (int) ($from[1] + ($to[1] - $from[1]) * $t),
                (int) ($from[2] + ($to[2] - $from[2]) * $t),
            );
            imageline($img, 0, $y, $w, $y, $color);
        }
    }

    protected function roundedRect(\GdImage $img, int $x, int $y, int $w, int $h, int $r, int $color): void
    {
        imagefilledrectangle($img, $x + $r, $y, $x + $w - $r, $y + $h, $color);
        imagefilledrectangle($img, $x, $y + $r, $x + $w, $y + $h - $r, $color);
        imagefilledellipse($img, $x + $r, $y + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x + $w - $r, $y + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x + $r, $y + $h - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x + $w - $r, $y + $h - $r, $r * 2, $r * 2, $color);
    }

    protected function drawBadge(\GdImage $img, string $font, int $cx, int $cy, string $text, int $bg, int $fg): void
    {
        $shaped = $this->shapeArabic($text);
        $box = imagettfbbox(17, 0, $font, $shaped);
        $tw = $box ? abs($box[2] - $box[0]) : 80;
        $bw = $tw + 56;
        $bh = 38;
        $bx = (int) ($cx - $bw / 2);
        $by = (int) ($cy - $bh / 2);
        $this->roundedRect($img, $bx, $by, $bw, $bh, 19, $bg);
        imagettftext($img, 17, 0, (int) ($cx - $tw / 2), $by + 26, $fg, $font, $shaped);
    }

    protected function drawRow(
        \GdImage $img,
        string $font,
        int $left,
        int $right,
        int $y,
        string $label,
        string $value,
        int $muted,
        int $ink
    ): void {
        $labelShaped = $this->shapeArabic($this->truncate($label, 18));
        $valueDraw = $this->containsArabic($value)
            ? $this->shapeArabic($this->truncate($value, 28))
            : $this->truncateRaw($value, 28);

        $labelBox = imagettfbbox(15, 0, $font, $labelShaped);
        $lw = $labelBox ? abs($labelBox[2] - $labelBox[0]) : 40;
        imagettftext($img, 15, 0, (int) ($right - $lw), $y, $muted, $font, $labelShaped);
        imagettftext($img, 17, 0, $left, $y, $ink, $font, $valueDraw);
    }

    protected function drawProgress(
        \GdImage $img,
        string $font,
        int $x,
        int $y,
        int $w,
        int $activeIndex,
        int $accent,
        int $muted,
        int $line,
        int $white
    ): void {
        $labels = (array) config('whatsapp_cards.order_progress_labels', []);
        $n = max(1, count($labels));
        $activeIndex = max(0, min($n - 1, $activeIndex));
        $step = $w / max(1, $n - 1);
        imageline($img, $x, $y + 12, $x + $w, $y + 12, $line);
        if ($activeIndex > 0) {
            $done = imagecolorallocate($img, 40, 160, 90);
            imageline($img, (int) ($x + $w - $step * $activeIndex), $y + 12, $x + $w, $y + 12, $done);
        }
        for ($i = 0; $i < $n; $i++) {
            $cx = (int) ($x + $w - $step * $i);
            $col = $i <= $activeIndex ? $accent : $line;
            imagefilledellipse($img, $cx, $y + 12, 20, 20, $col);
            if ($i <= $activeIndex) {
                imagefilledellipse($img, $cx, $y + 12, 8, 8, $white);
            }
            $label = $this->shapeArabic((string) ($labels[$i] ?? ''));
            $box = imagettfbbox(10, 0, $font, $label);
            $tw = $box ? abs($box[2] - $box[0]) : 16;
            imagettftext($img, 10, 0, (int) ($cx - $tw / 2), $y + 42, $i === $activeIndex ? $accent : $muted, $font, $label);
        }
    }

    protected function drawIcon(\GdImage $img, string $icon, int $cx, int $cy, int $accent, int $white): void
    {
        imagefilledellipse($img, $cx, $cy, 88, 88, $accent);
        imagefilledellipse($img, $cx, $cy, 68, 68, $white);
        $ink = $accent;
        match ($icon) {
            'check' => (function () use ($img, $cx, $cy, $ink) {
                imageline($img, $cx - 14, $cy, $cx - 4, $cy + 12, $ink);
                imageline($img, $cx - 4, $cy + 12, $cx + 16, $cy - 12, $ink);
            })(),
            'x' => (function () use ($img, $cx, $cy, $ink) {
                imageline($img, $cx - 12, $cy - 12, $cx + 12, $cy + 12, $ink);
                imageline($img, $cx + 12, $cy - 12, $cx - 12, $cy + 12, $ink);
            })(),
            'box' => imagerectangle($img, $cx - 14, $cy - 10, $cx + 14, $cy + 12, $ink),
            'invoice' => (function () use ($img, $cx, $cy, $ink) {
                imagerectangle($img, $cx - 12, $cy - 16, $cx + 12, $cy + 16, $ink);
                imageline($img, $cx - 6, $cy - 6, $cx + 6, $cy - 6, $ink);
            })(),
            default => imagefilledellipse($img, $cx, $cy, 16, 16, $ink),
        };
    }

    protected function paintLogo(\GdImage $canvas, int $cx, int $cy, int $target = 96): void
    {
        if (self::$logoCache instanceof \GdImage) {
            $nw = self::$logoCacheW ?? imagesx(self::$logoCache);
            $nh = self::$logoCacheH ?? imagesy(self::$logoCache);
            imagecopy($canvas, self::$logoCache, (int) ($cx - $nw / 2), (int) ($cy - $nh / 2), 0, 0, $nw, $nh);

            return;
        }

        $logoPath = public_path('images/logo-light.png');
        if (! is_file($logoPath)) {
            $logoPath = public_path('images/logo.png');
        }
        if (! is_file($logoPath)) {
            return;
        }
        $logo = @imagecreatefrompng($logoPath) ?: @imagecreatefromjpeg($logoPath);
        if (! $logo) {
            return;
        }
        $lw = imagesx($logo);
        $lh = imagesy($logo);
        $scale = $target / max($lw, $lh);
        $nw = (int) max(1, $lw * $scale);
        $nh = (int) max(1, $lh * $scale);
        $resized = imagecreatetruecolor($nw, $nh);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $nw, $nh, $transparent);
        imagealphablending($resized, true);
        imagecopyresampled($resized, $logo, 0, 0, 0, 0, $nw, $nh, $lw, $lh);
        imagedestroy($logo);
        self::$logoCache = $resized;
        self::$logoCacheW = $nw;
        self::$logoCacheH = $nh;
        imagecopy($canvas, $resized, (int) ($cx - $nw / 2), (int) ($cy - $nh / 2), 0, 0, $nw, $nh);
    }

    protected function centerArabic(\GdImage $img, string $font, float $size, int $y, string $text, int $color, ?int $maxWidth = null): void
    {
        $shaped = $this->shapeArabic($text);
        if ($maxWidth) {
            $shaped = $this->fitWidth($font, $size, $shaped, $maxWidth);
        }
        $this->centerText($img, $font, $size, $y, $shaped, $color);
    }

    protected function centerText(\GdImage $img, string $font, float $size, int $y, string $text, int $color): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            return;
        }
        $x = (int) ((imagesx($img) - abs($box[2] - $box[0])) / 2);
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    }

    protected function fitWidth(string $font, float $size, string $shaped, int $maxWidth): string
    {
        $box = imagettfbbox($size, 0, $font, $shaped);
        if ($box && abs($box[2] - $box[0]) <= $maxWidth) {
            return $shaped;
        }
        $ellipsis = '…';
        while (mb_strlen($shaped) > 4) {
            $shaped = mb_substr($shaped, 0, -2);
            $try = $shaped.$ellipsis;
            $box = imagettfbbox($size, 0, $font, $try);
            if ($box && abs($box[2] - $box[0]) <= $maxWidth) {
                return $try;
            }
        }

        return $shaped.'…';
    }

    protected function truncate(string $text, int $maxChars): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, max(1, $maxChars - 1)).'…';
    }

    protected function truncateRaw(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, max(1, $maxChars - 1)).'…';
    }

    protected function containsArabic(string $text): bool
    {
        return (bool) preg_match('/\p{Arabic}/u', $text);
    }

    protected function shapeArabic(string $text): string
    {
        try {
            return (new Arabic)->utf8Glyphs($text, 120, false, true);
        } catch (\Throwable) {
            return $text;
        }
    }

    protected function fontPath(): ?string
    {
        foreach ([
            resource_path('fonts/Cairo-Regular.ttf'),
            resource_path('fonts/tahoma.ttf'),
            'C:/Windows/Fonts/tahoma.ttf',
            'C:/Windows/Fonts/arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function absoluteUrl(string $relative): string
    {
        return rtrim((string) config('app.url'), '/').'/storage/'.ltrim($relative, '/');
    }
}
