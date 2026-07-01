<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSliderImagesRequest;
use App\Http\Requests\UpdateSliderImageRequest;
use App\Http\Resources\PlumberStoreImageResource;
use App\Models\PlumberStore;
use App\Models\PlumberStoreImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class VendorStoreSliderController extends Controller
{


    /** List slider images for a store */
    public function index(Request $request, PlumberStore $store): JsonResponse
    {
        Gate::authorize('view', $store);

        $images = $store->images()->get();
        return response()->json([
            'state'   => true,
            'message' => 'Fetched slider images.',
            'data'    => PlumberStoreImageResource::collection($images),
        ]);
    }

    /** Upload multiple images */
    public function store(StoreSliderImagesRequest $request, PlumberStore $store): JsonResponse
    {
        Gate::authorize('update', $store);

        $files        = $request->file('images');
        $captions     = (array) $request->input('captions', []);
        $sortOrders   = (array) $request->input('sort_orders', []);
        $primaryIndex = $request->integer('set_primary_idx', null);

        $created = [];
        DB::transaction(function () use ($files, $captions, $sortOrders, $store, &$created, $primaryIndex) {
            foreach ($files as $i => $file) {
                $path = $file->store('plumber_stores/slider', 'public');

                $image = $store->images()->create([
                    'path'       => $path,
                    'caption'    => $captions[$i]     ?? null,
                    'sort_order' => $sortOrders[$i]    ?? 0,
                    'is_active'  => true,
                    'is_primary' => false,
                ]);

                $created[] = $image;
            }

            // If requested, set one of the newly uploaded as primary
            if ($primaryIndex !== null && isset($created[$primaryIndex])) {
                $this->makePrimary($store, $created[$primaryIndex]);
            }
        });

        return response()->json([
            'state'   => true,
            'message' => 'Images uploaded.',
            'data'    => PlumberStoreImageResource::collection(collect($created)),
        ], 201);
    }

    /** Update a single image (caption / active / primary / sort) */
    public function update(UpdateSliderImageRequest $request, PlumberStore $store, PlumberStoreImage $image): JsonResponse
    {
        Gate::authorize('update', $store);
        $this->assertBelongsToStore($image, $store);

        $data = $request->validated();

        DB::transaction(function () use ($data, $image, $store) {
            // Handle primary shift
            if (array_key_exists('is_primary', $data) && $data['is_primary']) {
                $this->makePrimary($store, $image);
            }
            unset($data['is_primary']); // avoid double-setting post-transaction

            $image->update($data);
        });

        $image->refresh();

        return response()->json([
            'state'   => true,
            'message' => 'Image updated.',
            'data'    => new PlumberStoreImageResource($image),
        ]);
    }

    /** Reorder images in bulk: [{id, sort_order}] */
    public function reorder(Request $request, PlumberStore $store): JsonResponse
    {
        Gate::authorize('update', $store);

        $payload = $request->validate([
            'items'              => ['required','array','min:1'],
            'items.*.id'         => ['required','integer','exists:plumber_store_images,id'],
            'items.*.sort_order' => ['required','integer','min:0','max:100000'],
        ]);

        DB::transaction(function () use ($payload, $store) {
            foreach ($payload['items'] as $row) {
                $img = $store->images()->whereKey($row['id'])->first();
                if ($img) {
                    $img->update(['sort_order' => $row['sort_order']]);
                }
            }
        });

        $images = $store->images()->get();
        return response()->json([
            'state'   => true,
            'message' => 'Order updated.',
            'data'    => PlumberStoreImageResource::collection($images),
        ]);
    }

    /** Set primary image explicitly */
    public function setPrimary(Request $request, PlumberStore $store, PlumberStoreImage $image): JsonResponse
    {
        Gate::authorize('update', $store);
        $this->assertBelongsToStore($image, $store);

        DB::transaction(fn () => $this->makePrimary($store, $image));

        $image->refresh();
        return response()->json([
            'state'   => true,
            'message' => 'Primary image set.',
            'data'    => new PlumberStoreImageResource($image),
        ]);
    }

    /** Delete image */
    public function destroy(Request $request, PlumberStore $store, PlumberStoreImage $image): JsonResponse
    {
        Gate::authorize('update', $store);
        $this->assertBelongsToStore($image, $store);

        DB::transaction(function () use ($image) {
            // Remove file
            if ($image->path && Storage::disk('public')->exists($image->path)) {
                Storage::disk('public')->delete($image->path);
            }
            $image->delete();
        });

        return response()->json([
            'state'   => true,
            'message' => 'Image deleted.',
            'data'    => null,
        ]);
    }

    /** Helpers */
    protected function makePrimary(PlumberStore $store, PlumberStoreImage $image): void
    {
        // Clear any existing primary
        $store->images()->where('is_primary', true)->update(['is_primary' => false]);
        // Set this one
        $image->update(['is_primary' => true]);
    }

    protected function assertBelongsToStore(PlumberStoreImage $image, PlumberStore $store): void
    {
        abort_unless((int) $image->plumber_store_id === (int) $store->id, 404, 'Image not found for this store.');
    }
}
