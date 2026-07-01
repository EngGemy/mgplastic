<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StoreMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class StoreMediaUploadService
{
    public function __construct(
        protected VideoThumbnailService $thumbnails,
    ) {}

    /**
     * @param  UploadedFile[]  $files
     * @return array{created: array<int, array>, skipped: array<int, array>}
     */
    public function upload(
        Model $owner,
        array $files,
        string $kind,
        ?int $productId = null,
        ?string $title = null,
    ): array {
        if ($productId && ! Product::whereKey($productId)->exists()) {
            throw new \InvalidArgumentException('Product not found');
        }

        $created = [];
        $skipped = [];

        DB::transaction(function () use ($owner, $files, $kind, $productId, $title, &$created, &$skipped) {
            $baseSort = (int) $owner->storeMedia()->where('kind', $kind)->max('sort_order');

            foreach ($files as $index => $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $mime = strtolower($file->getMimeType() ?? '');
                $isVideo = str_starts_with($mime, 'video/');
                $isImage = str_starts_with($mime, 'image/');

                if ($kind === 'video' && ! $isVideo) {
                    $skipped[] = ['filename' => $file->getClientOriginalName(), 'reason' => 'يجب أن يكون الملف فيديو'];
                    continue;
                }

                if (in_array($kind, ['banner', 'product_image', 'gallery'], true) && ! $isImage) {
                    $skipped[] = ['filename' => $file->getClientOriginalName(), 'reason' => 'يجب أن يكون الملف صورة'];
                    continue;
                }

                try {
                    $folder = match ($kind) {
                        'video' => 'store_media/videos',
                        'banner' => 'store_media/banners',
                        'product_image' => 'store_media/products',
                        default => 'store_media/gallery',
                    };

                    $path = $file->store($folder, 'public');
                    $thumbnailPath = null;

                    if ($isVideo) {
                        $thumbnailPath = $this->thumbnails->generate($path, 'store_media/thumbnails');
                    }

                    $media = $owner->storeMedia()->create([
                        'kind' => $kind,
                        'product_id' => $kind === 'product_image' ? $productId : null,
                        'file_path' => $path,
                        'thumbnail_path' => $thumbnailPath,
                        'title' => $title,
                        'sort_order' => $baseSort + $index + 1,
                        'is_active' => true,
                    ]);

                    $created[] = $media->fresh()->toApiArray();
                } catch (\Throwable $e) {
                    $skipped[] = [
                        'filename' => $file->getClientOriginalName(),
                        'reason' => $e->getMessage(),
                    ];
                }
            }
        });

        return compact('created', 'skipped');
    }

    public function deleteMedia(Model $owner, int $mediaId): bool
    {
        $media = StoreMedia::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->whereKey($mediaId)
            ->first();

        if (! $media) {
            return false;
        }

        $media->delete();

        return true;
    }
}
