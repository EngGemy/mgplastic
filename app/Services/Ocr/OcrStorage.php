<?php

namespace App\Services\Ocr;

use App\Models\OcrImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * OCR uploads live on the private ("local") disk. Older rows may still point at public.
 */
class OcrStorage
{
    public const DISK_PRIVATE = 'local';

    public const DISK_PUBLIC_LEGACY = 'public';

    public function privateDisk(): Filesystem
    {
        return Storage::disk(self::DISK_PRIVATE);
    }

    public function resolveDisk(string $path): Filesystem
    {
        if ($path !== '' && $this->privateDisk()->exists($path)) {
            return $this->privateDisk();
        }

        return Storage::disk(self::DISK_PUBLIC_LEGACY);
    }

    public function absolutePath(string $path): string
    {
        return $this->resolveDisk($path)->path($path);
    }

    public function exists(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        return $this->privateDisk()->exists($path)
            || Storage::disk(self::DISK_PUBLIC_LEGACY)->exists($path);
    }

    public function get(string $path): ?string
    {
        if ($path === '' || ! $this->exists($path)) {
            return null;
        }

        return $this->resolveDisk($path)->get($path);
    }

    public function delete(string $path): void
    {
        if ($path === '') {
            return;
        }
        if ($this->privateDisk()->exists($path)) {
            $this->privateDisk()->delete($path);
        }
        if (Storage::disk(self::DISK_PUBLIC_LEGACY)->exists($path)) {
            Storage::disk(self::DISK_PUBLIC_LEGACY)->delete($path);
        }
    }

    public function mimeType(string $path): string
    {
        try {
            return $this->resolveDisk($path)->mimeType($path) ?: 'image/jpeg';
        } catch (\Throwable) {
            return 'image/jpeg';
        }
    }

    /**
     * Temporary signed preview URL (regenerate on every present).
     */
    public function signedPreviewUrl(OcrImage $image): ?string
    {
        if ($image->path === '' || ! $this->exists($image->path)) {
            return null;
        }

        $minutes = max(1, (int) config('ocr_uploads.signed_preview_minutes', 30));

        try {
            return URL::temporarySignedRoute(
                'ocr.images.preview',
                now()->addMinutes($minutes),
                ['image' => $image->id],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    public function signedPreviewExpiresAt(): string
    {
        $minutes = max(1, (int) config('ocr_uploads.signed_preview_minutes', 30));

        return now()->addMinutes($minutes)->toIso8601String();
    }
}
