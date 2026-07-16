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
            'slider' => $owner->storeMedia->where('kind', 'banner')->values()->map->toApiArray(),
            'videos' => $owner->storeMedia->where('kind', 'video')->values()->map->toApiArray(),
            'product_images' => $owner->storeMedia->where('kind', 'product_image')->values()->map->toApiArray(),
            'gallery' => $owner->storeMedia->where('kind', 'gallery')->values()->map->toApiArray(),
            'my_products' => $owner->storeMedia->where('kind', 'my_product')->values()->map->toApiArray(),
            'social_links' => $owner->socialLinks->map->toApiArray(),
        ];
    }

    public function listMyProducts(Request $request): JsonResponse
    {
        $owner = $this->resolveStoreMediaOwner($request);
        $owner->loadMissing('storeMedia');

        $items = $owner->storeMedia
            ->where('kind', 'my_product')
            ->sortBy('sort_order')
            ->values()
            ->map->toApiArray();

        return response()->json([
            'status' => true,
            'data' => [
                'items' => $items,
                'count' => $items->count(),
            ],
        ]);
    }

    public function uploadMyProduct(Request $request, StoreMediaUploadService $uploader): JsonResponse
    {
        $owner = $this->resolveStoreMediaOwner($request);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $name = $request->input('name') ?: $request->input('title');

        $result = $uploader->upload(
            $owner,
            [$request->file('image')],
            'my_product',
            null,
            $name,
            $request->input('description'),
        );

        return response()->json([
            'status' => true,
            'message' => 'تمت إضافة المنتج',
            'data' => $result['created'][0] ?? null,
            'skipped' => $result['skipped'],
        ], 201);
    }

    public function updateMyProduct(Request $request, int $mediaId): JsonResponse
    {
        $owner = $this->resolveStoreMediaOwner($request);

        $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $media = StoreMedia::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('kind', 'my_product')
            ->whereKey($mediaId)
            ->first();

        if (! $media) {
            return response()->json(['status' => false, 'message' => 'المنتج غير موجود'], 404);
        }

        $data = [];
        $name = $request->input('name', $request->input('title'));
        if ($name !== null) {
            $data['title'] = $name;
        }
        if ($request->exists('description')) {
            $data['description'] = $request->input('description');
        }
        if ($request->exists('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }
        if ($request->exists('sort_order')) {
            $data['sort_order'] = $request->integer('sort_order');
        }

        if ($request->hasFile('image')) {
            if ($media->file_path) {
                Storage::disk('public')->delete($media->file_path);
            }
            $data['file_path'] = $request->file('image')->store('store_media/my-products', 'public');
        }

        if ($data !== []) {
            $media->update($data);
        }

        return response()->json([
            'status' => true,
            'message' => 'تم التحديث',
            'data' => $media->fresh()->toApiArray(),
        ]);
    }

    public function deleteMyProduct(Request $request, int $mediaId, StoreMediaUploadService $uploader): JsonResponse
    {
        $owner = $this->resolveStoreMediaOwner($request);

        $media = StoreMedia::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('kind', 'my_product')
            ->whereKey($mediaId)
            ->first();

        if (! $media) {
            return response()->json(['status' => false, 'message' => 'المنتج غير موجود'], 404);
        }

        $uploader->deleteMedia($owner, $mediaId);

        return response()->json([
            'status' => true,
            'message' => 'تم حذف المنتج',
        ]);
    }

    public function uploadStoreMedia(Request $request, StoreMediaUploadService $uploader): JsonResponse
    {
        $owner = $this->resolveStoreMediaOwner($request);

        $request->validate([
            'kind' => ['required', 'in:banner,slider,video,product_image,gallery,my_product'],
            'media' => ['required', 'array', 'min:1'],
            'media.*' => ['required', 'file', 'max:512000'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        // "slider" is the public-facing name for store banners.
        $kind = $request->input('kind') === 'slider' ? 'banner' : $request->input('kind');
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
            $request->input('name') ?: $request->input('title'),
            $request->input('description'),
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

    /** Dedicated slider endpoints — same data as kind=banner. */
    public function listSlider(Request $request): JsonResponse
    {
        $owner = $this->resolveStoreMediaOwner($request);
        $owner->loadMissing('storeMedia');

        $slides = $owner->storeMedia->where('kind', 'banner')->values()->map->toApiArray();

        return response()->json([
            'status' => true,
            'data' => $slides,
        ]);
    }

    public function uploadSlider(Request $request, StoreMediaUploadService $uploader): JsonResponse
    {
        $request->merge(['kind' => 'banner']);

        return $this->uploadStoreMedia($request, $uploader);
    }

    public function deleteSlider(Request $request, int $mediaId, StoreMediaUploadService $uploader): JsonResponse
    {
        return $this->deleteStoreMedia($request, $mediaId, $uploader);
    }

    public function reorderSlider(Request $request): JsonResponse
    {
        $owner = $this->resolveStoreMediaOwner($request);

        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'integer', 'distinct'],
        ]);

        $ids = array_values($data['order']);

        $owned = StoreMedia::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('kind', 'banner')
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        if (count($owned) !== count($ids)) {
            return response()->json([
                'status' => false,
                'message' => 'بعض صور السلايدر غير موجودة أو لا تملكها',
            ], 422);
        }

        foreach ($ids as $index => $id) {
            StoreMedia::query()->whereKey($id)->update(['sort_order' => $index]);
        }

        $owner->load('storeMedia');

        return response()->json([
            'status' => true,
            'message' => 'تم تحديث ترتيب السلايدر',
            'data' => $owner->storeMedia->where('kind', 'banner')->values()->map->toApiArray(),
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
