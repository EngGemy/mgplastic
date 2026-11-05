<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * GD-based image optimize + thumbnail for plumber work photos (no Intervention dependency).
 */
class ImageThumbnailService
{
    protected string $disk = 'public';

    /**
     * Store an optimized JPEG/WebP-friendly JPEG under $directory.
     */
    public function storeOptimized(UploadedFile $file, string $directory, int $maxEdge = 1600, int $quality = 82): string
    {
        $directory = trim($directory, '/');
        $name = Str::random(40).'.jpg';
        $relative = $directory.'/'.$name;
        $absolute = Storage::disk($this->disk)->path($relative);

        $this->ensureDirectory(dirname($absolute));

        if (! $this->writeResized($file->getRealPath(), $absolute, $maxEdge, $quality)) {
            // Fallback: store original as-is.
            return $file->store($directory, $this->disk);
        }

        return $relative;
    }

    /**
     * Re-encode an existing public-disk image in place (or replace path) when oversized.
     * Returns the relative path actually used (may be a new .jpg next to original).
     */
    public function optimizeExisting(string $sourceRelativePath, int $maxEdge = 1600, int $quality = 82, int $minBytes = 900_000): ?string
    {
        $absolute = Storage::disk($this->disk)->path($sourceRelativePath);
        if (! is_file($absolute)) {
            return null;
        }

        $size = (int) filesize($absolute);
        if ($size > 0 && $size <= $minBytes) {
            return $sourceRelativePath;
        }

        $directory = trim(dirname($sourceRelativePath), '/.');
        $name = Str::random(40).'.jpg';
        $relative = ($directory !== '' ? $directory.'/' : '').$name;
        $dest = Storage::disk($this->disk)->path($relative);
        $this->ensureDirectory(dirname($dest));

        if (! $this->writeResized($absolute, $dest, $maxEdge, $quality)) {
            return $sourceRelativePath;
        }

        if ($relative !== $sourceRelativePath) {
            Storage::disk($this->disk)->delete($sourceRelativePath);
        }

        return $relative;
    }

    /**
     * Create a small JPEG thumbnail next to (or under) $outputDir from an existing public-disk path.
     */
    public function makeThumbnail(string $sourceRelativePath, string $outputDir = 'work_photos/thumbnails', int $maxEdge = 480, int $quality = 72): ?string
    {
        $source = Storage::disk($this->disk)->path($sourceRelativePath);
        if (! is_file($source) || ! is_readable($source)) {
            Log::warning('[ImageThumbnail] Source missing', ['path' => $sourceRelativePath]);

            return null;
        }

        $outputDir = trim($outputDir, '/');
        $base = pathinfo($sourceRelativePath, PATHINFO_FILENAME);
        $relative = $outputDir.'/'.$base.'_thumb.jpg';
        $absolute = Storage::disk($this->disk)->path($relative);

        $this->ensureDirectory(dirname($absolute));

        if (! $this->writeResized($source, $absolute, $maxEdge, $quality)) {
            return null;
        }

        return $relative;
    }

    /**
     * Category card thumbnail (~400px). Prefers WebP when GD supports it; else JPEG.
     */
    public function makeCategoryThumb(string $sourceRelativePath, int $maxWidth = 400, int $quality = 78): ?string
    {
        return $this->makeDiskThumb($sourceRelativePath, 'categories/thumbs', $maxWidth, $quality, 'Category');
    }

    /**
     * Product list thumbnail (~320px) for mobile/public catalog rows.
     */
    public function makeProductThumb(string $sourceRelativePath, int $maxWidth = 320, int $quality = 78): ?string
    {
        return $this->makeDiskThumb($sourceRelativePath, 'products/thumbs', $maxWidth, $quality, 'Product');
    }

    /**
     * Resize an existing public-disk image into $outputDir/{base}_{width}.{webp|jpg}.
     */
    protected function makeDiskThumb(
        string $sourceRelativePath,
        string $outputDir,
        int $maxWidth,
        int $quality,
        string $label,
    ): ?string {
        $source = Storage::disk($this->disk)->path($sourceRelativePath);
        if (! is_file($source) || ! is_readable($source)) {
            Log::warning("[ImageThumbnail] {$label} source missing", ['path' => $sourceRelativePath]);

            return null;
        }

        $base = pathinfo($sourceRelativePath, PATHINFO_FILENAME);
        $outputDir = trim($outputDir, '/');
        $preferWebp = function_exists('imagewebp');
        $ext = $preferWebp ? 'webp' : 'jpg';
        $relative = $outputDir.'/'.$base.'_'.$maxWidth.'.'.$ext;
        $absolute = Storage::disk($this->disk)->path($relative);
        $this->ensureDirectory(dirname($absolute));

        if ($preferWebp) {
            if ($this->writeResizedWebp($source, $absolute, $maxWidth, $quality)) {
                return $relative;
            }
        }

        $relativeJpg = $outputDir.'/'.$base.'_'.$maxWidth.'.jpg';
        $absoluteJpg = Storage::disk($this->disk)->path($relativeJpg);
        if ($this->writeResized($source, $absoluteJpg, $maxWidth, $quality)) {
            return $relativeJpg;
        }

        return null;
    }

    protected function writeResizedWebp(string $sourcePath, string $destPath, int $maxEdge, int $quality): bool
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagewebp')) {
            return false;
        }

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return false;
        }

        [$width, $height] = $info;
        $mime = $info['mime'] ?? '';

        $src = match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            'image/gif' => @imagecreatefromgif($sourcePath),
            default => false,
        };

        if ($src === false) {
            return false;
        }

        if (in_array($mime, ['image/jpeg', 'image/jpg'], true) && function_exists('exif_read_data')) {
            $src = $this->applyExifOrientation($src, $sourcePath) ?? $src;
            $width = imagesx($src);
            $height = imagesy($src);
        }

        $scale = min(1, $maxEdge / max($width, $height, 1));
        $nw = max(1, (int) round($width * $scale));
        $nh = max(1, (int) round($height * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $width, $height);

        $ok = imagewebp($dst, $destPath, $quality);

        imagedestroy($src);
        imagedestroy($dst);

        return $ok && is_file($destPath) && filesize($destPath) > 0;
    }

    protected function writeResized(string $sourcePath, string $destPath, int $maxEdge, int $quality): bool
    {
        if (! function_exists('imagecreatetruecolor')) {
            return false;
        }

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return false;
        }

        [$width, $height] = $info;
        $mime = $info['mime'] ?? '';

        $src = match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            'image/gif' => @imagecreatefromgif($sourcePath),
            default => false,
        };

        if ($src === false) {
            return false;
        }

        // Honor EXIF orientation for JPEGs from phones.
        if (in_array($mime, ['image/jpeg', 'image/jpg'], true) && function_exists('exif_read_data')) {
            $src = $this->applyExifOrientation($src, $sourcePath) ?? $src;
            $width = imagesx($src);
            $height = imagesy($src);
        }

        $scale = min(1, $maxEdge / max($width, $height, 1));
        $nw = max(1, (int) round($width * $scale));
        $nh = max(1, (int) round($height * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $width, $height);

        $ok = imagejpeg($dst, $destPath, $quality);

        imagedestroy($src);
        imagedestroy($dst);

        return $ok && is_file($destPath) && filesize($destPath) > 0;
    }

    /** @param  \GdImage  $image */
    protected function applyExifOrientation($image, string $path)
    {
        try {
            $exif = @exif_read_data($path);
            $orientation = (int) ($exif['Orientation'] ?? 1);

            return match ($orientation) {
                3 => imagerotate($image, 180, 0),
                6 => imagerotate($image, -90, 0),
                8 => imagerotate($image, 90, 0),
                default => $image,
            };
        } catch (\Throwable) {
            return $image;
        }
    }

    protected function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
