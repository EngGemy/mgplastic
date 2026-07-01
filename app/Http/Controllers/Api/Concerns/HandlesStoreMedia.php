<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\SocialLink;
use App\Models\StoreMedia;
use App\Services\StoreMediaUploadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

trait HandlesStoreMedia
{
    protected function storeMediaResponse(Model $owner): array
    {
        $owner->loadMissing(['storeMedia.product', 'socialLinks']);

        return [
            'banners' => $owner->storeMedia->where('kind', 'banner')->values()->map->toApiArray(),
            'videos' => $owner->storeMedia->where('kind', 'video')->values()->map->toApiArray(),
            'product_images' => $owner->storeMedia->where('kind', 'product_image')->values()->map->toApiArray(),
            'gallery' => $owner->storeMedia->where('kind', 'gallery')->values()->map->toApiArray(),
            'social_links' => $owner->socialLinks->map->toApiArray(),
        ];
    }

    public function uploadStoreMedia(Request $request, StoreMediaUploadService $uploader): JsonResponse
    {
        $owner = $this->resolveStoreMediaOwner($request);

        $request->validate([
            'kind' => ['required', 'in:banner,video,product_image,gallery'],
            'media' => ['required', 'array', 'min:1'],
            'media.*' => ['required', 'file', 'max:512000'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $kind = $request->input('kind');
        $rules = match ($kind) {
            'video' => 'mimes:mp4,mov,webm,mkv,avi',
            default => 'mimes:jpg,jpeg,png,webp',
        };

        $request->validate([
            'media.*' => ['file', $rules],
        ]);

        $result = $uploader->upload(
            $owner,
            $request->file('media', []),
            $kind,
            $request->integer('product_id') ?: null,
            $request->input('title'),
        );

        return response()->json([
            'status' => true,
            'message' => 'تم رفع الوسائط بنجاح',
            'data' => $result['created'],
            'skipped' => $result['skipped'],
        ], 201);
    }

    public function listStoreMedia(Request $request): JsonResponse
    {
        $owner = $this->resolveStoreMediaOwner($request);

        return response()->json([
            'status' => true,
            'data' => $this->storeMediaResponse($owner),
        ]);
    }

    public function deleteStoreMedia(Request $request, int $mediaId, StoreMediaUploadService $uploader): JsonResponse
    {
        $owner = $this->resolveStoreMediaOwner($request);

        $media = StoreMedia::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->whereKey($mediaId)
            ->first();

        if (! $media) {
            return response()->json(['status' => false, 'message' => 'الوسائط غير موجودة'], 404);
        }

        if ($media->file_path) {
            Storage::disk('public')->delete($media->file_path);
        }
        if ($media->thumbnail_path) {
            Storage::disk('public')->delete($media->thumbnail_path);
        }

        $media->delete();

        return response()->json(['status' => true, 'message' => 'تم حذف الوسائط']);
    }

    public function upsertSocialLinks(Request $request): JsonResponse
    {
        $owner = $this->resolveStoreMediaOwner($request);

        $data = $request->validate([
            'links' => ['required', 'array', 'min:1'],
            'links.*.platform' => ['required', 'string', 'max:32'],
            'links.*.url' => ['required', 'url', 'max:500'],
            'links.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $saved = [];
        foreach ($data['links'] as $row) {
            $link = $owner->socialLinks()->updateOrCreate(
                ['platform' => $row['platform']],
                [
                    'url' => $row['url'],
                    'sort_order' => $row['sort_order'] ?? 0,
                ],
            );
            $saved[] = $link->toApiArray();
        }

        return response()->json([
            'status' => true,
            'message' => 'تم حفظ روابط التواصل',
            'data' => $saved,
        ]);
    }

    public function deleteSocialLink(Request $request, int $linkId): JsonResponse
    {
        $owner = $this->resolveStoreMediaOwner($request);

        $deleted = $owner->socialLinks()->whereKey($linkId)->delete();

        if (! $deleted) {
            return response()->json(['status' => false, 'message' => 'الرابط غير موجود'], 404);
        }

        return response()->json(['status' => true, 'message' => 'تم حذف الرابط']);
    }

    abstract protected function resolveStoreMediaOwner(Request $request): Model;
}
