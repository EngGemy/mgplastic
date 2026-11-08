<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\OcrImage;
use App\Services\Ocr\OcrStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temporary signed access to an OCR upload (signature is the gate).
 */
class OcrImagePreviewController extends Controller
{
    public function __construct(protected OcrStorage $ocrStorage) {}

    public function show(Request $request, OcrImage $image): Response
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'رابط المعاينة منتهٍ أو غير صالح');
        }

        if ($image->path === '' || ! $this->ocrStorage->exists($image->path)) {
            abort(404, 'الصورة غير موجودة');
        }

        $bytes = $this->ocrStorage->get($image->path);
        if ($bytes === null) {
            abort(404, 'الصورة غير موجودة');
        }

        return response($bytes, 200, [
            'Content-Type' => $this->ocrStorage->mimeType($image->path),
            'Cache-Control' => 'private, max-age=60',
        ]);
    }
}
