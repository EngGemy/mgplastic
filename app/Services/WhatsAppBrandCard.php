<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generates branded MG WhatsApp cover cards (logo + scene) for WAHA sendImage.
 */
class WhatsAppBrandCard
{
    public const SCENES = [
        'welcome' => ['MG WELCOME', 'أهلاً بك في شبكة MG'],
        'otp' => ['SECURE CODE', 'رمز التحقق'],
        'invoice' => ['NEW INVOICE', 'فاتورة جديدة'],
        'order' => ['NEW ORDER', 'طلبية جديدة'],
        'points' => ['POINTS ADDED', 'نقاط جديدة'],
        'return' => ['RETURN UPDATE', 'مرتجع'],
        'withdrawal' => ['WITHDRAWAL', 'طلب سحب'],
        'status' => ['STATUS UPDATE', 'تحديث الحالة'],
        'default' => ['MG PLASTIC', 'إشعار رسمي'],
    ];

    /**
     * Absolute public URL for a scene card (cached on disk).
     */
    public function urlFor(string $scene = 'default', ?string $subtitle = null): ?string
    {
        $configured = trim((string) config('services.waha.brand_image_url', ''));
        if ($configured !== '' && $scene === 'default') {
            return $configured;
        }

        try {
            $relative = $this->ensureCard($scene, $subtitle);

            return $this->absoluteUrl($relative);
        } catch (\Throwable $e) {
            Log::warning('[WhatsAppBrandCard] generate failed', ['error' => $e->getMessage()]);

            return $this->fallbackLogoUrl();
        }
    }

    protected function ensureCard(string $scene, ?string $subtitle): string
    {
        $scene = array_key_exists($scene, self::SCENES) ? $scene : 'default';
        $meta = self::SCENES[$scene];
        $subtitle = $subtitle ?: $meta[1];
        $hash = substr(md5($scene.'|'.$subtitle.'|v2'), 0, 10);
        $relative = "whatsapp-cards/mg-{$scene}-{$hash}.png";

        if (Storage::disk('public')->exists($relative)) {
            return $relative;
        }

        $png = $this->renderPng($meta[0], $subtitle);
        Storage::disk('public')->put($relative, $png);

        return $relative;
    }

    protected function renderPng(string $badgeEn, string $subtitleAr): string
    {
        $w = 1080;
        $h = 1080;
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        // Deep navy → electric blue gradient
        for ($y = 0; $y < $h; $y++) {
            $t = $y / max(1, $h - 1);
            $r = (int) (8 + (26 - 8) * $t);
            $g = (int) (35 + (86 - 35) * $t);
            $b = (int) (90 + (219 - 90) * $t);
            $color = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $w, $y, $color);
        }

        // Soft gold accent circles
        $gold = imagecolorallocatealpha($img, 212, 175, 55, 90);
        imagefilledellipse($img, 120, 180, 280, 280, $gold);
        imagefilledellipse($img, 980, 900, 360, 360, $gold);

        // Glass card panel
        $panel = imagecolorallocatealpha($img, 255, 255, 255, 95);
        imagefilledrectangle($img, 90, 160, $w - 90, $h - 160, $panel);
        $border = imagecolorallocatealpha($img, 255, 255, 255, 60);
        imagerectangle($img, 90, 160, $w - 90, $h - 160, $border);
        imagerectangle($img, 94, 164, $w - 94, $h - 164, $border);

        // Logo
        $this->paintLogo($img, (int) ($w / 2), 380);

        $white = imagecolorallocate($img, 255, 255, 255);
        $goldSolid = imagecolorallocate($img, 232, 197, 71);
        $muted = imagecolorallocate($img, 220, 230, 255);

        $font = $this->fontPath();
        if ($font) {
            // Brand
            $this->centerText($img, $font, 42, 520, 'MG PLASTIC', $white);
            $this->centerText($img, $font, 20, 580, 'للصناعات البلاستيكية', $goldSolid);

            // Scene badge
            $badgeW = 420;
            $badgeH = 64;
            $bx = (int) (($w - $badgeW) / 2);
            $by = 640;
            $badgeBg = imagecolorallocate($img, 13, 45, 110);
            imagefilledrectangle($img, $bx, $by, $bx + $badgeW, $by + $badgeH, $badgeBg);
            imagefilledrectangle($img, $bx, $by, $bx + 8, $by + $badgeH, $goldSolid);
            $this->centerText($img, $font, 22, $by + 44, $badgeEn, $goldSolid);

            // Arabic subtitle (may render LTR; caption carries the full Arabic copy)
            $this->centerText($img, $font, 28, 780, $subtitleAr, $white);
            $this->centerText($img, $font, 18, 860, 'Official Notification', $muted);
            $this->centerText($img, $font, 16, 920, 'mg-plastic.com', $muted);
        } else {
            imagestring($img, 5, (int) ($w / 2 - 60), 520, 'MG PLASTIC', $white);
            imagestring($img, 4, (int) ($w / 2 - 50), 640, $badgeEn, $goldSolid);
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
                $target = 220;
                $scale = $target / max($lw, $lh);
                $nw = (int) max(1, $lw * $scale);
                $nh = (int) max(1, $lh * $scale);
                $dx = (int) ($cx - $nw / 2);
                $dy = (int) ($cy - $nh / 2 - 40);
                imagecopyresampled($canvas, $logo, $dx, $dy, 0, 0, $nw, $nh, $lw, $lh);
                imagedestroy($logo);

                return;
            }
        }

        // Fallback monogram if logo file missing
        $ring = imagecolorallocate($canvas, 232, 197, 71);
        $fill = imagecolorallocate($canvas, 13, 45, 110);
        imagefilledellipse($canvas, $cx, $cy - 30, 200, 200, $ring);
        imagefilledellipse($canvas, $cx, $cy - 30, 170, 170, $fill);
        $font = $this->fontPath();
        if ($font) {
            $this->centerText($canvas, $font, 48, $cy - 12, 'MG', $ring);
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
            resource_path('fonts/WhatsAppBrand.ttf'),
            'C:/Windows/Fonts/segoeui.ttf',
            'C:/Windows/Fonts/tahoma.ttf',
            'C:/Windows/Fonts/arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
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
        $url = Storage::disk('public')->url($relative);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    protected function fallbackLogoUrl(): ?string
    {
        $configured = trim((string) config('services.waha.brand_image_url', ''));
        if ($configured !== '') {
            return $configured;
        }

        if (is_file(public_path('images/logo-light.png'))) {
            return asset('images/logo-light.png');
        }

        return null;
    }
}
