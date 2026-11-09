<?php

namespace App\Services\Ocr;

use RuntimeException;

class InvoiceImagePreprocessor
{
    /**
     * @return array{path:string, mime:string, sha256:string, width:int, height:int}
     */
    public function prepare(string $absolutePath): array
    {
        if (! is_readable($absolutePath)) {
            throw new RuntimeException('تعذّر قراءة ملف الصورة.');
        }

        $info = @getimagesize($absolutePath);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            throw new RuntimeException('الصورة تالفة أو غير مدعومة.');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $maxPixels = max(1_000_000, (int) config('services.ocr.max_pixels', 40_000_000));
        if (($width * $height) > $maxPixels) {
            throw new RuntimeException('دقة الصورة أكبر من المسموح — صغّر الصورة ثم أعد المحاولة.');
        }

        $raw = file_get_contents($absolutePath);
        if ($raw === false || $raw === '') {
            throw new RuntimeException('ملف الصورة فارغ.');
        }

        if (! function_exists('imagecreatefromstring')) {
            $sha = hash('sha256', $raw);

            return [
                'path' => $absolutePath,
                'mime' => $info['mime'] ?? 'image/jpeg',
                'sha256' => $sha,
                'width' => $width,
                'height' => $height,
            ];
        }

        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            throw new RuntimeException('تعذّر فك ترميز الصورة.');
        }

        $src = $this->exifOrient($src, $absolutePath);
        $width = imagesx($src);
        $height = imagesy($src);

        // Prefer config/ocr.php; fall back to legacy services.ocr.*
        $maxSide = max(400, (int) config('ocr.max_image_side', config('services.ocr.max_image_side', 2000)));
        $scale = 1.0;
        $longest = max($width, $height);
        if ($longest > $maxSide) {
            $scale = $maxSide / $longest;
        }

        $dstW = max(1, (int) round($width * $scale));
        $dstH = max(1, (int) round($height * $scale));
        $dst = imagecreatetruecolor($dstW, $dstH);
        if ($dst === false) {
            imagedestroy($src);
            throw new RuntimeException('تعذّر تجهيز الصورة.');
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $width, $height);
        imagedestroy($src);

        // Light contrast boost (cheap unsharp-ish brightness normalize).
        imagefilter($dst, IMG_FILTER_CONTRAST, -8);

        $dir = storage_path('app/ocr-tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $outPath = $dir.'/'.uniqid('ocr_', true).'.jpg';
        $quality = max(60, min(92, (int) config('ocr.jpeg_quality', config('services.ocr.jpeg_quality', 85))));
        // Re-encode JPEG → strips EXIF / GPS before storage and Gemini.
        imagejpeg($dst, $outPath, $quality);
        imagedestroy($dst);

        $bytes = file_get_contents($outPath);
        if ($bytes === false) {
            throw new RuntimeException('تعذّر حفظ الصورة المُعالجة.');
        }

        return [
            'path' => $outPath,
            'mime' => 'image/jpeg',
            'sha256' => hash('sha256', $bytes),
            'width' => $dstW,
            'height' => $dstH,
        ];
    }

    /** @param  \GdImage  $image */
    protected function exifOrient($image, string $path)
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        return match ($orientation) {
            3 => imagerotate($image, 180, 0) ?: $image,
            6 => imagerotate($image, -90, 0) ?: $image,
            8 => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };
    }
}
