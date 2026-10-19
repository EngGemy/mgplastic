<?php

namespace App\Services;

use ArPHP\I18N\Arabic;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generates branded MG WhatsApp cover cards (logo + scene) for WAHA sendImage.
 *
 * @phpstan-type CardAsset array{
 *   relative: string,
 *   path: string,
 *   url: string,
 *   base64: string,
 *   mimetype: string,
 *   filename: string
 * }
 */
class WhatsAppBrandCard
{
    public const SCENES = [
        'welcome' => ['ترحيب', 'أهلاً بك في شبكة MG'],
        'invoice' => ['فاتورة جديدة', 'إشعار فاتورة'],
        'order' => ['طلبية جديدة', 'إشعار طلب'],
        'points' => ['نقاط جديدة', 'تم إضافة نقاط'],
        'return' => ['مرتجع', 'تحديث مرتجع'],
        'withdrawal' => ['طلب سحب', 'إشعار سحب'],
        'status' => ['تحديث الحالة', 'تحديث'],
        'default' => ['إشعار رسمي', 'MG للصناعات البلاستيكية'],
    ];

    /**
     * Absolute public URL (may be unreachable from remote WAHA — prefer assetFor + base64).
     */
    public function urlFor(string $scene = 'default', ?string $subtitle = null): ?string
    {
        $asset = $this->assetFor($scene, $subtitle);

        return $asset['url'] ?? null;
    }

    /**
     * Full asset for WAHA: local path + public URL + base64.
     *
     * @return CardAsset|null
     */
    public function assetFor(string $scene = 'default', ?string $subtitle = null): ?array
    {
        try {
            $relative = $this->ensureCard($scene, $subtitle);
            $path = Storage::disk('public')->path($relative);
            if (! is_file($path)) {
                Log::error('[WhatsAppBrandCard] file missing after generate', ['relative' => $relative]);

                return null;
            }

            $binary = (string) file_get_contents($path);
            $url = $this->absoluteUrl($relative);

            Log::info('[WhatsAppBrandCard] ready', [
                'scene' => $scene,
                'relative' => $relative,
                'path' => $path,
                'url' => $url,
                'bytes' => strlen($binary),
                'url_looks_local' => $this->urlLooksLocal($url),
            ]);

            return [
                'relative' => $relative,
                'path' => $path,
                'url' => $url,
                'base64' => base64_encode($binary),
                'mimetype' => 'image/png',
                'filename' => basename($relative),
            ];
        } catch (\Throwable $e) {
            Log::error('[WhatsAppBrandCard] generate failed', [
                'scene' => $scene,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function ensureCard(string $scene, ?string $subtitle): string
    {
        $scene = array_key_exists($scene, self::SCENES) ? $scene : 'default';
        $meta = self::SCENES[$scene];
        $subtitle = $subtitle ?: $meta[1];
        $hash = substr(md5($scene.'|'.$subtitle.'|v3-ar'), 0, 10);
        $relative = "whatsapp-cards/mg-{$scene}-{$hash}.png";

        if (Storage::disk('public')->exists($relative)) {
            return $relative;
        }

        Storage::disk('public')->makeDirectory('whatsapp-cards');
        $png = $this->renderPng($meta[0], $subtitle);
        Storage::disk('public')->put($relative, $png);

        return $relative;
    }

    protected function renderPng(string $badgeAr, string $subtitleAr): string
    {
        $w = 1080;
        $h = 1080;
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        for ($y = 0; $y < $h; $y++) {
            $t = $y / max(1, $h - 1);
            $r = (int) (8 + (26 - 8) * $t);
            $g = (int) (35 + (86 - 35) * $t);
            $b = (int) (90 + (219 - 90) * $t);
            $color = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $w, $y, $color);
        }

        $gold = imagecolorallocatealpha($img, 212, 175, 55, 90);
        imagefilledellipse($img, 120, 180, 280, 280, $gold);
        imagefilledellipse($img, 980, 900, 360, 360, $gold);

        $panel = imagecolorallocatealpha($img, 255, 255, 255, 95);
        imagefilledrectangle($img, 90, 160, $w - 90, $h - 160, $panel);
        $border = imagecolorallocatealpha($img, 255, 255, 255, 60);
        imagerectangle($img, 90, 160, $w - 90, $h - 160, $border);

        $this->paintLogo($img, (int) ($w / 2), 360);

        $white = imagecolorallocate($img, 255, 255, 255);
        $goldSolid = imagecolorallocate($img, 232, 197, 71);
        $muted = imagecolorallocate($img, 220, 230, 255);

        $font = $this->fontPath();
        if ($font) {
            $this->centerArabic($img, $font, 36, 500, 'MG للصناعات البلاستيكية', $white);
            $this->centerArabic($img, $font, 22, 560, 'الجودة الليبية… ثقة السباكين', $goldSolid);

            $badgeW = 480;
            $badgeH = 72;
            $bx = (int) (($w - $badgeW) / 2);
            $by = 620;
            $badgeBg = imagecolorallocate($img, 13, 45, 110);
            imagefilledrectangle($img, $bx, $by, $bx + $badgeW, $by + $badgeH, $badgeBg);
            imagefilledrectangle($img, $bx, $by, $bx + 10, $by + $badgeH, $goldSolid);
            $this->centerArabic($img, $font, 26, $by + 50, $badgeAr, $goldSolid);

            $this->centerArabic($img, $font, 30, 780, $subtitleAr, $white);
            $this->centerArabic($img, $font, 18, 860, 'إشعار رسمي من MG', $muted);
            $this->centerText($img, $font, 16, 920, 'mg-plastic.com', $muted);
        } else {
            imagestring($img, 5, (int) ($w / 2 - 80), 520, 'MG PLASTIC', $white);
        }

        ob_start();
        imagepng($img, null, 6);
        $binary = (string) ob_get_clean();
        imagedestroy($img);

        return $binary;
    }

    protected function paintLogo(\GdImage $canvas, int $cx, int $cy): void
    {
        $logoPath = public_path('images/logo-light.png');
        if (! is_file($logoPath)) {
            $logoPath = public_path('images/logo.png');
        }

        if (is_file($logoPath)) {
            $logo = @imagecreatefrompng($logoPath) ?: @imagecreatefromjpeg($logoPath);
            if ($logo) {
                $lw = imagesx($logo);
                $lh = imagesy($logo);
                $target = 240;
                $scale = $target / max($lw, $lh);
                $nw = (int) max(1, $lw * $scale);
                $nh = (int) max(1, $lh * $scale);
                $dx = (int) ($cx - $nw / 2);
                $dy = (int) ($cy - $nh / 2 - 20);
                imagecopyresampled($canvas, $logo, $dx, $dy, 0, 0, $nw, $nh, $lw, $lh);
                imagedestroy($logo);

                return;
            }
        }

        Log::warning('[WhatsAppBrandCard] logo file missing', [
            'expected' => public_path('images/logo-light.png'),
        ]);

        $ring = imagecolorallocate($canvas, 232, 197, 71);
        $fill = imagecolorallocate($canvas, 13, 45, 110);
        imagefilledellipse($canvas, $cx, $cy - 10, 200, 200, $ring);
        imagefilledellipse($canvas, $cx, $cy - 10, 170, 170, $fill);
        $font = $this->fontPath();
        if ($font) {
            $this->centerText($canvas, $font, 48, $cy + 8, 'MG', $ring);
        }
    }

    /** Shape Arabic (connected glyphs) then draw centered. */
    protected function centerArabic(\GdImage $img, string $font, float $size, int $y, string $text, int $color): void
    {
        $this->centerText($img, $font, $size, $y, $this->shapeArabic($text), $color);
    }

    protected function shapeArabic(string $text): string
    {
        try {
            $arabic = new Arabic;
            // Connected glyphs + force RTL presentation for GD imagettftext
            return $arabic->utf8Glyphs($text, 120, false, true);
        } catch (\Throwable $e) {
            Log::warning('[WhatsAppBrandCard] arabic shape failed', ['error' => $e->getMessage()]);

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
            resource_path('fonts/tahoma.ttf'),
            resource_path('fonts/arial.ttf'),
            resource_path('fonts/WhatsAppBrand.ttf'),
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
        // Prefer APP_URL + /storage/... (works with php artisan storage:link)
        $url = $base.'/storage/'.$relative;

        if ($this->urlLooksLocal($url)) {
            Log::warning('[WhatsAppBrandCard] public URL looks local — WAHA remote cannot fetch it; will send base64', [
                'url' => $url,
            ]);
        }

        return $url;
    }

    protected function urlLooksLocal(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';

        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with(strtolower($host), '.test')
            || str_ends_with(strtolower($host), '.local');
    }
}
