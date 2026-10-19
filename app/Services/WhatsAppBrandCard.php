<?php

namespace App\Services;

use ArPHP\I18N\Arabic;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Themed MG WhatsApp cover cards (JPEG 1080×1080) for WAHA sendImage.
 *
 * @phpstan-type CardAsset array{
 *   relative: string,
 *   path: string,
 *   url: string,
 *   base64: string,
 *   mimetype: string,
 *   filename: string,
 *   theme: string
 * }
 */
class WhatsAppBrandCard
{
    /** @var \GdImage|null resized logo cached for this request */
    protected static $logoCache = null;

    protected static ?int $logoCacheW = null;

    protected static ?int $logoCacheH = null;

    /**
     * @param  array{
     *   hero?: string|null,
     *   hero_label?: string|null,
     *   rows?: list<array{0:string,1:string}>,
     *   footnote?: string|null,
     *   progress_index?: int|null,
     *   badge?: string|null,
     *   title?: string|null
     * }  $payload
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
                Log::error('[WhatsAppBrandCard] file missing after generate', ['relative' => $relative]);

                return null;
            }

            $binary = (string) file_get_contents($path);
            $url = $this->absoluteUrl($relative);

            return [
                'relative' => $relative,
                'path' => $path,
                'url' => $url,
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

    /** @deprecated use assetFor */
    public function urlFor(string $scene = 'default', array|string|null $payload = null): ?string
    {
        $payload = is_array($payload) ? $payload : ['hero_label' => (string) ($payload ?: '')];

        return $this->assetFor($scene, $payload)['url'] ?? null;
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
        $map = (array) config('whatsapp_cards.order_status_theme', []);

        return $map[$status] ?? 'order_status_review';
    }

    public function themeForReturnStatus(?string $status): string
    {
        $map = (array) config('whatsapp_cards.return_status_theme', []);

        return $map[$status] ?? 'return_created';
    }

    /**
     * @return array<string, mixed>
     */
    public function theme(string $key): array
    {
        $themes = (array) config('whatsapp_cards.themes', []);

        return $themes[$this->resolveThemeKey($key)] ?? $themes['default'];
    }

    /**
     * Render to a local path (preview / artisan) without WAHA packaging.
     *
     * @param  array<string, mixed>  $payload
     */
    public function renderToPath(string $themeKey, string $absolutePath, array $payload = []): bool
    {
        $themeKey = $this->resolveThemeKey($themeKey);
        $theme = $this->theme($themeKey);
        $jpeg = $this->renderJpeg($themeKey, $theme, $payload);
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($absolutePath, $jpeg) !== false;
    }

    /**
     * @param  array<string, mixed>  $theme
     * @param  array<string, mixed>  $payload
     */
    protected function ensureCard(string $themeKey, array $theme, array $payload): string
    {
        $hash = substr(md5($themeKey.'|'.json_encode($payload, JSON_UNESCAPED_UNICODE).'|v6-rtl'), 0, 12);
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
        $w = (int) config('whatsapp_cards.size', 1080);
        $h = $w;
        $img = imagecreatetruecolor($w, $h);

        $from = $theme['gradient_from'] ?? [8, 35, 90];
        $to = $theme['gradient_to'] ?? [26, 86, 180];
        $this->fillGradient($img, $from, $to);

        $accent = $theme['accent'] ?? [13, 71, 161];
        $accentCol = imagecolorallocate($img, $accent[0], $accent[1], $accent[2]);
        $white = imagecolorallocate($img, 255, 255, 255);
        $ink = imagecolorallocate($img, 20, 30, 55);
        $muted = imagecolorallocate($img, 100, 115, 145);
        $line = imagecolorallocate($img, 220, 228, 240);

        // Soft accent blobs
        $soft = imagecolorallocatealpha($img, $accent[0], $accent[1], $accent[2], 90);
        imagefilledellipse($img, 140, 160, 260, 260, $soft);
        imagefilledellipse($img, 960, 940, 320, 320, $soft);

        // White data card with rounded corners
        $cardX = 70;
        $cardY = 140;
        $cardW = $w - 140;
        $cardH = $h - 260;
        $this->roundedRect($img, $cardX, $cardY, $cardW, $cardH, 36, $white);

        // Top accent bar on card
        imagefilledrectangle($img, $cardX, $cardY, $cardX + $cardW, $cardY + 10, $accentCol);

        $font = $this->fontPath();
        $this->paintLogo($img, (int) ($w / 2), $cardY + 95);

        if ($font) {
            $this->centerArabic($img, $font, 28, $cardY + 200, 'MG للصناعات البلاستيكية', $ink);

            $badge = (string) ($payload['badge'] ?? $theme['badge_text'] ?? 'إشعار');
            $this->drawBadge($img, $font, (int) ($w / 2), $cardY + 250, $badge, $accentCol, $white);

            $title = (string) ($payload['title'] ?? $theme['title_ar'] ?? '');
            $this->centerArabic($img, $font, 34, $cardY + 330, $title, $ink);

            $this->drawIcon($img, (string) ($theme['icon'] ?? 'bell'), (int) ($w / 2), $cardY + 400, $accentCol, $white);

            $hero = trim((string) ($payload['hero'] ?? ''));
            $heroLabel = trim((string) ($payload['hero_label'] ?? ''));
            $y = $cardY + 470;

            if ($hero !== '') {
                // Shape Arabic heroes; keep Latin digits/currency as-is
                if ($this->containsArabic($hero)) {
                    $this->centerArabic($img, $font, 52, $y, $hero, $accentCol);
                } else {
                    $this->centerText($img, $font, 52, $y, $hero, $accentCol);
                }
                $y += 50;
                if ($heroLabel !== '') {
                    $this->centerArabic($img, $font, 18, $y, $heroLabel, $muted);
                    $y += 40;
                }
            }

            // Thin divider
            imageline($img, $cardX + 80, $y, $cardX + $cardW - 80, $y, $line);
            $y += 36;

            /** @var list<array{0:string,1:string}> $rows */
            $rows = array_values(array_slice($payload['rows'] ?? [], 0, 5));
            foreach ($rows as [$label, $value]) {
                $this->drawRow($img, $font, $cardX + 90, $cardX + $cardW - 90, $y, (string) $label, (string) $value, $muted, $ink);
                $y += 42;
            }

            if (! empty($theme['show_progress']) || isset($payload['progress_index'])) {
                $y += 16;
                $idx = (int) ($payload['progress_index'] ?? $theme['progress_index'] ?? 0);
                $this->drawProgress($img, $font, $cardX + 70, $y, $cardW - 140, $idx, $accentCol, $muted, $line, $white);
                $y += 90;
            }

            $footnote = trim((string) ($payload['footnote'] ?? ''));
            if ($footnote !== '') {
                $this->centerArabic($img, $font, 16, min($cardY + $cardH - 40, $y + 20), $footnote, $muted);
            }

            // Footer outside card
            $this->centerArabic($img, $font, 16, $h - 70, 'إشعار رسمي · mg-plastic.com', $white);
        }

        $quality = (int) config('whatsapp_cards.jpeg_quality', 82);
        ob_start();
        imagejpeg($img, null, max(60, min(92, $quality)));
        $binary = (string) ob_get_clean();
        imagedestroy($img);

        return $binary;
    }

    /** @param  array{0:int,1:int,2:int}  $from  @param  array{0:int,1:int,2:int}  $to */
    protected function fillGradient(\GdImage $img, array $from, array $to): void
    {
        $h = imagesy($img);
        $w = imagesx($img);
        for ($y = 0; $y < $h; $y++) {
            $t = $y / max(1, $h - 1);
            $r = (int) ($from[0] + ($to[0] - $from[0]) * $t);
            $g = (int) ($from[1] + ($to[1] - $from[1]) * $t);
            $b = (int) ($from[2] + ($to[2] - $from[2]) * $t);
            $color = imagecolorallocate($img, $r, $g, $b);
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
        $box = imagettfbbox(18, 0, $font, $shaped);
        $tw = $box ? abs($box[2] - $box[0]) : 80;
        $padX = 28;
        $padY = 14;
        $bw = $tw + $padX * 2;
        $bh = 40;
        $bx = (int) ($cx - $bw / 2);
        $by = (int) ($cy - $bh / 2);
        $this->roundedRect($img, $bx, $by, $bw, $bh, 20, $bg);
        $tx = (int) ($cx - $tw / 2);
        imagettftext($img, 18, 0, $tx, $by + 28, $fg, $font, $shaped);
    }

    /**
     * RTL data row: label on the RIGHT, value on the LEFT.
     * Arabic strings are shaped via ArPHP so GD (LTR) renders them correctly.
     */
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
        $labelShaped = $this->shapeArabic($label);
        $valueDraw = $this->containsArabic($value) ? $this->shapeArabic($value) : $value;

        $labelBox = imagettfbbox(16, 0, $font, $labelShaped);
        $lw = $labelBox ? abs($labelBox[2] - $labelBox[0]) : 40;
        imagettftext($img, 16, 0, (int) ($right - $lw), $y, $muted, $font, $labelShaped);

        imagettftext($img, 18, 0, $left, $y, $ink, $font, $valueDraw);
    }

    /**
     * Progress bar in RTL: step 0 (مراجعة) on the RIGHT → مكتمل on the LEFT.
     */
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
            // Fill from the right toward the left
            $fromX = (int) ($x + $w);
            $toX = (int) ($x + $w - $step * $activeIndex);
            imageline($img, $toX, $y + 12, $fromX, $y + 12, $done);
        }

        for ($i = 0; $i < $n; $i++) {
            // Visual index from the right
            $cx = (int) ($x + $w - $step * $i);
            $filled = $i <= $activeIndex;
            $col = $filled ? $accent : $line;
            imagefilledellipse($img, $cx, $y + 12, 22, 22, $col);
            if ($filled) {
                imagefilledellipse($img, $cx, $y + 12, 10, 10, $white);
            }
            $label = $this->shapeArabic((string) ($labels[$i] ?? ''));
            $box = imagettfbbox(11, 0, $font, $label);
            $tw = $box ? abs($box[2] - $box[0]) : 20;
            imagettftext($img, 11, 0, (int) ($cx - $tw / 2), $y + 48, $i === $activeIndex ? $accent : $muted, $font, $label);
        }
    }

    protected function containsArabic(string $text): bool
    {
        return (bool) preg_match('/\p{Arabic}/u', $text);
    }

    protected function drawIcon(\GdImage $img, string $icon, int $cx, int $cy, int $accent, int $white): void
    {
        $r = 48;
        imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $accent);
        imagefilledellipse($img, $cx, $cy, (int) ($r * 1.55), (int) ($r * 1.55), $white);

        $ink = $accent;
        switch ($icon) {
            case 'check':
                imageline($img, $cx - 16, $cy, $cx - 4, $cy + 14, $ink);
                imageline($img, $cx - 4, $cy + 14, $cx + 18, $cy - 14, $ink);
                imageline($img, $cx - 15, $cy, $cx - 3, $cy + 14, $ink);
                imageline($img, $cx - 3, $cy + 14, $cx + 19, $cy - 14, $ink);
                break;
            case 'x':
                imageline($img, $cx - 14, $cy - 14, $cx + 14, $cy + 14, $ink);
                imageline($img, $cx + 14, $cy - 14, $cx - 14, $cy + 14, $ink);
                break;
            case 'star':
                imagefilledellipse($img, $cx, $cy, 28, 28, $ink);
                break;
            case 'box':
                imagerectangle($img, $cx - 16, $cy - 12, $cx + 16, $cy + 14, $ink);
                imageline($img, $cx - 16, $cy - 4, $cx + 16, $cy - 4, $ink);
                break;
            case 'truck':
                imagerectangle($img, $cx - 18, $cy - 8, $cx + 4, $cy + 8, $ink);
                imagerectangle($img, $cx + 4, $cy - 2, $cx + 18, $cy + 8, $ink);
                imagefilledellipse($img, $cx - 8, $cy + 12, 10, 10, $ink);
                imagefilledellipse($img, $cx + 12, $cy + 12, 10, 10, $ink);
                break;
            case 'wallet':
                imagerectangle($img, $cx - 18, $cy - 12, $cx + 18, $cy + 12, $ink);
                imagefilledrectangle($img, $cx + 4, $cy - 4, $cx + 16, $cy + 4, $ink);
                break;
            case 'return':
                imageline($img, $cx + 12, $cy - 10, $cx - 12, $cy - 10, $ink);
                imageline($img, $cx - 12, $cy - 10, $cx - 12, $cy + 10, $ink);
                imageline($img, $cx - 12, $cy + 10, $cx + 4, $cy + 10, $ink);
                break;
            case 'invoice':
                imagerectangle($img, $cx - 14, $cy - 18, $cx + 14, $cy + 18, $ink);
                imageline($img, $cx - 8, $cy - 8, $cx + 8, $cy - 8, $ink);
                imageline($img, $cx - 8, $cy, $cx + 8, $cy, $ink);
                imageline($img, $cx - 8, $cy + 8, $cx + 4, $cy + 8, $ink);
                break;
            default:
                imagefilledellipse($img, $cx, $cy, 18, 18, $ink);
        }
    }

    protected function paintLogo(\GdImage $canvas, int $cx, int $cy): void
    {
        $target = 110;
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

        if (is_file($logoPath)) {
            $logo = @imagecreatefrompng($logoPath) ?: @imagecreatefromjpeg($logoPath);
            if ($logo) {
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

                return;
            }
        }

        $ring = imagecolorallocate($canvas, 180, 140, 20);
        $fill = imagecolorallocate($canvas, 13, 45, 110);
        imagefilledellipse($canvas, $cx, $cy, 100, 100, $ring);
        imagefilledellipse($canvas, $cx, $cy, 80, 80, $fill);
    }

    protected function centerArabic(\GdImage $img, string $font, float $size, int $y, string $text, int $color): void
    {
        $this->centerText($img, $font, $size, $y, $this->shapeArabic($text), $color);
    }

    protected function shapeArabic(string $text): string
    {
        try {
            $arabic = new Arabic;

            return $arabic->utf8Glyphs($text, 120, false, true);
        } catch (\Throwable $e) {
            return $text;
        }
    }

    protected function centerText(\GdImage $img, string $font, float $size, int $y, string $text, int $color): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            return;
        }
        $textW = abs($box[2] - $box[0]);
        $x = (int) ((imagesx($img) - $textW) / 2);
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    }

    protected function fontPath(): ?string
    {
        $candidates = [
            resource_path('fonts/Cairo-Regular.ttf'),
            resource_path('fonts/cairo.ttf'),
            resource_path('fonts/tahoma.ttf'),
            resource_path('fonts/arial.ttf'),
            'C:/Windows/Fonts/tahoma.ttf',
            'C:/Windows/Fonts/arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function absoluteUrl(string $relative): string
    {
        $relative = ltrim($relative, '/');
        $base = rtrim((string) config('app.url'), '/');

        return $base.'/storage/'.$relative;
    }
}
