<?php

namespace App\Services\Ocr;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Validate / normalize uploads before OCR storage (size, type, HEIC, EXIF strip, resize).
 */
class OcrImageIngest
{
    /**
     * @return array{path:string, mime:string, original_name:?string}
     */
    public function accept(UploadedFile $file): array
    {
        $maxBytes = max(1, (int) config('ocr.max_upload_bytes', 8 * 1024 * 1024));
        if ($file->getSize() > $maxBytes) {
            throw new OcrImageIngestException(
                'الصورة أكبر من الحد المسموح ('.(int) round($maxBytes / 1024 / 1024).' ميجا)',
                'ocr_image_too_large',
                413,
            );
        }

        $mime = strtolower((string) ($file->getMimeType() ?: ''));
        $ext = strtolower((string) $file->getClientOriginalExtension());

        $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        $heicMimes = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];
        $isHeic = in_array($mime, $heicMimes, true) || in_array($ext, ['heic', 'heif'], true);

        if ($isHeic) {
            if (! (bool) config('ocr.accept_heic', true) || ! $this->imagickSupportsHeic()) {
                throw new OcrImageIngestException(
                    'صيغة HEIC غير مدعومة على السيرفر — حوّل الصورة إلى JPEG أولاً',
                    'ocr_image_type_unsupported',
                    415,
                );
            }

            return $this->convertHeicToJpeg($file);
        }

        if (! in_array($mime, $allowed, true) && ! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new OcrImageIngestException(
                'نوع الصورة غير مدعوم — استخدم jpg أو png أو webp',
                'ocr_image_type_unsupported',
                415,
            );
        }

        return [
            'path' => $file->getRealPath() ?: $file->getPathname(),
            'mime' => $mime ?: 'image/jpeg',
            'original_name' => $file->getClientOriginalName(),
        ];
    }

    public function imagickSupportsHeic(): bool
    {
        if (! extension_loaded('imagick') || ! class_exists(\Imagick::class)) {
            return false;
        }

        try {
            $formats = array_map('strtoupper', \Imagick::queryFormats());

            return in_array('HEIC', $formats, true) || in_array('HEIF', $formats, true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{path:string, mime:string, original_name:?string}
     */
    protected function convertHeicToJpeg(UploadedFile $file): array
    {
        try {
            $imagick = new \Imagick($file->getRealPath() ?: $file->getPathname());
            $imagick->setImageFormat('jpeg');
            $imagick->stripImage();
            $dir = storage_path('app/ocr-tmp');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $out = $dir.'/'.uniqid('heic_', true).'.jpg';
            $imagick->writeImage($out);
            $imagick->clear();
            $imagick->destroy();

            return [
                'path' => $out,
                'mime' => 'image/jpeg',
                'original_name' => $file->getClientOriginalName(),
            ];
        } catch (\Throwable $e) {
            throw new OcrImageIngestException(
                'تعذّر تحويل HEIC — حوّل الصورة إلى JPEG أولاً',
                'ocr_image_type_unsupported',
                415,
                $e,
            );
        }
    }
}

class OcrImageIngestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $messageCode,
        public readonly int $httpStatus = 422,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
