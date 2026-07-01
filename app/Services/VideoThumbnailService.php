<?php

namespace App\Services;

use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VideoThumbnailService
{
    protected string $disk = 'public';

    public function generate(string $videoPath, ?string $outputDir = null): ?string
    {
        $localVideoPath = Storage::disk($this->disk)->path($videoPath);

        if (! is_file($localVideoPath) || ! is_readable($localVideoPath)) {
            Log::warning('[VideoThumbnail] Video not readable', ['path' => $videoPath]);

            return $this->createFallbackThumbnail($outputDir);
        }

        $outputDir = trim($outputDir ?? dirname($videoPath).'/thumbnails', '/');
        $thumbFileName = pathinfo($videoPath, PATHINFO_FILENAME).'_cover.jpg';
        $thumbRelativePath = $outputDir.'/'.$thumbFileName;
        $thumbFullPath = Storage::disk($this->disk)->path($thumbRelativePath);

        $this->ensureDirectory(dirname($thumbFullPath));

        foreach ([1, 3, 5, 0.5] as $seconds) {
            if ($this->extractFrame($localVideoPath, $thumbFullPath, $seconds)) {
                return $thumbRelativePath;
            }
        }

        Log::warning('[VideoThumbnail] FFmpeg failed, using fallback', ['video' => $videoPath]);

        return $this->createFallbackThumbnail($outputDir, $thumbFileName);
    }

    protected function extractFrame(string $localVideoPath, string $thumbFullPath, float $seconds): bool
    {
        try {
            if (! $this->ffmpegAvailable()) {
                return false;
            }

            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries' => config('services.ffmpeg.ffmpeg_binaries'),
                'ffprobe.binaries' => config('services.ffmpeg.ffprobe_binaries'),
                'timeout' => 3600,
                'ffmpeg.threads' => 2,
            ]);

            $video = $ffmpeg->open($localVideoPath);
            $frame = $video->frame(TimeCode::fromSeconds($seconds));
            $frame->save($thumbFullPath);

            return is_file($thumbFullPath) && filesize($thumbFullPath) > 0;
        } catch (\Throwable $e) {
            Log::debug('[VideoThumbnail] Frame extraction failed', [
                'seconds' => $seconds,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function createFallbackThumbnail(?string $outputDir = null, ?string $fileName = null): string
    {
        $outputDir = trim($outputDir ?? 'media/thumbnails', '/');
        $fileName = $fileName ?? 'video_cover_'.uniqid('', true).'.jpg';
        $relativePath = $outputDir.'/'.$fileName;
        $fullPath = Storage::disk($this->disk)->path($relativePath);

        $this->ensureDirectory(dirname($fullPath));

        if (function_exists('imagecreatetruecolor')) {
            $width = 1280;
            $height = 720;
            $image = imagecreatetruecolor($width, $height);

            for ($y = 0; $y < $height; $y++) {
                $ratio = $y / $height;
                $r = (int) (15 + (45 - 15) * $ratio);
                $g = (int) (23 + (62 - 23) * $ratio);
                $b = (int) (42 + (95 - 42) * $ratio);
                $color = imagecolorallocate($image, $r, $g, $b);
                imageline($image, 0, $y, $width, $y, $color);
            }

            $white = imagecolorallocatealpha($image, 255, 255, 255, 30);
            $centerX = (int) ($width / 2);
            $centerY = (int) ($height / 2);
            $radius = 72;
            imagefilledellipse($image, $centerX, $centerY, $radius * 2, $radius * 2, $white);

            $playColor = imagecolorallocate($image, 255, 255, 255);
            $triangle = [
                $centerX - 18, $centerY - 28,
                $centerX - 18, $centerY + 28,
                $centerX + 32, $centerY,
            ];
            imagefilledpolygon($image, $triangle, $playColor);

            imagejpeg($image, $fullPath, 90);
            imagedestroy($image);

            return $relativePath;
        }

        $defaultPath = 'media/video-default-thumbnail.jpg';
        $defaultFull = Storage::disk($this->disk)->path($defaultPath);

        if (is_file($defaultFull)) {
            copy($defaultFull, $fullPath);

            return $relativePath;
        }

        file_put_contents($fullPath, '');

        return $relativePath;
    }

    protected function ffmpegAvailable(): bool
    {
        $ffmpeg = config('services.ffmpeg.ffmpeg_binaries');

        if (str_contains($ffmpeg, DIRECTORY_SEPARATOR) || str_contains($ffmpeg, '/')) {
            return is_executable($ffmpeg) || is_file($ffmpeg);
        }

        $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';
        $result = shell_exec("$which ".escapeshellarg($ffmpeg).' 2>&1');

        return is_string($result) && trim($result) !== '';
    }

    protected function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }
}
