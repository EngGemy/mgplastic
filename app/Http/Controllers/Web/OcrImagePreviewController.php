<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\OcrImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temporary signed access to an OCR upload (no app token required; signature is the gate).
 */
class OcrImagePreviewController extends Controller
{
    public function show(Request $request, OcrImage $image): Response
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'رابط المعاينة منتهٍ أو غير صالح');
        }

        $disk = Storage::disk('public');
        if ($image->path === '' || ! $disk->exists($image->path)) {
            abort(404, 'الصورة غير موجودة');
        }

        $mime = $disk->mimeType($image->path) ?: 'image/jpeg';

        return response($disk->get($image->path), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=60',
        ]);
    }
}
