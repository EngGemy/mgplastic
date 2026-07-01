<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlumberStoreResource;
use App\Models\PlumberStore;
use App\Models\PlumberStoreImage;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PlumberStoreController extends Controller
{
    /**
     * GET /api/v1/plumber-stores
     * Public list with optional filters + PAGINATION
     * Query params: city_id, page, per_page
     */
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->input('per_page', 15), 100));

        $query = PlumberStore::with([
            'city',
            'vendor',
            'images',
            'storeMedia.product',
            'socialLinks',
            'nearestPlumbers' => fn ($q) => $q->latest()->take(10),
        ])->latest();

        if ($request->filled('city_id')) {
            $query->where('city_id', (int) $request->input('city_id'));
        }

        $stores = $query->paginate($perPage);

        return response()->json([
            'status'  => true,
            'message' => __('All plumber stores'),
            'data'    => PlumberStoreResource::collection($stores),
            'meta'    => [
                'current_page' => $stores->currentPage(),
                'per_page'     => $stores->perPage(),
                'total'        => $stores->total(),
                'last_page'    => $stores->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/plumber-stores/{id}
     * Show one store (public) — includes slider images
     */
    public function show(Request $request, $id)
    {
        $store = PlumberStore::with([
            'city',
            'vendor',
            'images',
            'storeMedia.product',
            'socialLinks',
            'nearestPlumbers' => fn ($q) => $q->latest()->take(10),
        ])->find($id);

        if (! $store) {
            return response()->json(['status' => false, 'message' => __('Store not found')], 404);
        }

        return response()->json([
            'status' => true,
            'data'   => new PlumberStoreResource($store),
        ]);
    }

    /**
     * GET /api/v1/plumber-stores/related/{id}
     * Related stores (same city, excluding current), public
     */
    public function related($id)
    {
        $store = PlumberStore::find($id);
        if (! $store) {
            return response()->json(['status'=>false,'message'=>__('Store not found')],404);
        }

        $related = PlumberStore::with([
            'city',
            'vendor',
            'images',
            'storeMedia.product',
            'socialLinks',
            'nearestPlumbers' => fn ($q) => $q->latest()->take(10),
        ])
            ->where('city_id', $store->city_id)
            ->where('id', '!=', $store->id)
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'status'  => true,
            'message' => __('Related plumber stores'),
            'data'    => PlumberStoreResource::collection($related),
        ]);
    }

    /**
     * GET /api/v1/plumber-stores/my   (auth vendors only)
     */
    public function myStores(Request $request)
    {
        $user = Auth::user();
        if (! $user || ! $user->isVendor()) {
            return response()->json(['status' => false, 'message' => 'Only vendors can view their stores'], 403);
        }

        $stores = $user->vendorStores()
            ->with([
                'city',
                'images',
                'storeMedia.product',
                'socialLinks',
                'nearestPlumbers' => fn ($q) => $q->latest()->take(10),
            ])
            ->latest()
            ->get();

        return response()->json([
            'status'  => true,
            'message' => __('Your stores'),
            'data'    => PlumberStoreResource::collection($stores),
        ]);
    }

    /**
     * POST /api/v1/plumber-stores (auth vendors only)
     * Create a store.
     *
     * Also supports optional slider images in the SAME request:
     *  - image (main) [optional]
     *  - images[] (slider, multiple) [optional]
     *  - captions[] (parallel with images[]) [optional]
     *  - sort_orders[] (parallel with images[]) [optional]
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        if (! $user || ! $user->isVendor()) {
            return response()->json(['status' => false, 'message' => 'Only vendors can create stores'], 403);
        }

        $request->validate([
            'city_id'         => ['required', 'exists:cities,id'],
            'address'         => ['required', 'string'],
            'available_date'  => ['nullable', 'date'],
            'available_time'  => ['nullable', 'date_format:H:i'],
            'phone'           => ['required', 'string', 'max:20'],
            'image'           => ['nullable', 'image', 'max:2048'],
            'latitude'        => ['nullable', 'numeric'],
            'longitude'       => ['nullable', 'numeric'],
            'name_en'         => ['required', 'string', 'max:255'],
            'description_en'  => ['nullable', 'string'],
            'name_ar'         => ['nullable', 'string', 'max:255'],
            'description_ar'  => ['nullable', 'string'],

            // Slider in create
            'images'          => ['sometimes','array'],
            'images.*'        => ['image','max:4096'],
            'captions'        => ['sometimes','array'],
            'captions.*'      => ['nullable','string','max:255'],
            'sort_orders'     => ['sometimes','array'],
            'sort_orders.*'   => ['nullable','integer','min:0'],
        ]);

        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('plumber_stores', 'public')
            : null;

        $store = DB::transaction(function () use ($request, $imagePath, $user) {
            $store = new PlumberStore([
                'vendor_id'      => $user->id,
                'city_id'        => $request->city_id,
                'address'        => $request->address,
                'available_date' => $request->available_date,
                'available_time' => $request->available_time,
                'phone'          => $request->phone,
                'image'          => $imagePath,
                'latitude'       => $request->latitude,
                'longitude'      => $request->longitude,
            ]);
            $store->save();

            // Translations
            $store->translateOrNew('en')->name        = $request->name_en;
            $store->translateOrNew('en')->description = $request->description_en ?? '';
            if ($request->filled('name_ar') || $request->filled('description_ar')) {
                $store->translateOrNew('ar')->name        = $request->name_ar ?? '';
                $store->translateOrNew('ar')->description = $request->description_ar ?? '';
            }
            $store->save();

            // Slider images (optional)
            if ($request->hasFile('images')) {
                $captions = $request->input('captions', []);
                $orders   = $request->input('sort_orders', []);
                foreach ($request->file('images') as $idx => $file) {
                    $path = $file->store('plumber_store_slider', 'public');
                    $store->images()->create([
                        'path'       => $path,
                        'caption'    => Arr::get($captions, $idx),
                        'sort_order' => (int) Arr::get($orders, $idx, $idx),
                    ]);
                }
            }

            return $store->refresh();
        });

        // Eager-load everything including slider
        $store->load([
            'city',
            'vendor',
            'images',
            'nearestPlumbers' => fn ($q) => $q->latest()->take(10),
        ]);

        return response()->json([
            'status'  => true,
            'message' => __('Plumber store created successfully'),
            'data'    => new PlumberStoreResource($store),
        ], 201);
    }

    /**
     * POST /api/v1/plumber-stores/{id}
     * (or PUT/PATCH if you prefer) — Vendor-only: update own store
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        if (! $user || ! $user->isVendor()) {
            return response()->json(['status' => false, 'message' => 'Only vendors can update stores'], 403);
        }

        $store = PlumberStore::find($id);
        if (! $store) {
            return response()->json(['status' => false, 'message' => __('Store not found')], 404);
        }
        if ((int) $store->vendor_id !== (int) $user->id) {
            return response()->json(['status' => false, 'message' => 'You do not own this store'], 403);
        }

        $request->validate([
            'city_id'         => ['sometimes', 'exists:cities,id'],
            'address'         => ['sometimes', 'string'],
            'available_date'  => ['sometimes', 'nullable', 'date'],
            'available_time'  => ['sometimes', 'nullable', 'date_format:H:i'],
            'phone'           => ['sometimes', 'string', 'max:20'],
            'image'           => ['sometimes', 'nullable', 'image', 'max:2048'],
            'latitude'        => ['sometimes', 'nullable', 'numeric'],
            'longitude'       => ['sometimes', 'nullable', 'numeric'],
            'name_en'         => ['sometimes', 'string', 'max:255'],
            'description_en'  => ['sometimes', 'nullable', 'string'],
            'name_ar'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'description_ar'  => ['sometimes', 'nullable', 'string'],
        ]);

        DB::transaction(function () use ($request, $store) {
            foreach (['city_id','address','available_date','available_time','phone','latitude','longitude'] as $field) {
                if ($request->has($field)) {
                    $store->{$field} = $request->input($field);
                }
            }

            if ($request->hasFile('image')) {
                if ($store->image) {
                    Storage::disk('public')->delete($store->image);
                }
                $store->image = $request->file('image')->store('plumber_stores', 'public');
            } elseif ($request->has('image') && $request->input('image') === null) {
                if ($store->image) {
                    Storage::disk('public')->delete($store->image);
                }
                $store->image = null;
            }

            $store->save();

            if ($request->has('name_en') || $request->has('description_en')) {
                $store->translateOrNew('en')->name        = $request->input('name_en', $store->translate('en')->name ?? '');
                $store->translateOrNew('en')->description = $request->input('description_en', $store->translate('en')->description ?? '');
            }
            if ($request->has('name_ar') || $request->has('description_ar')) {
                $store->translateOrNew('ar')->name        = $request->input('name_ar', $store->translate('ar')->name ?? '');
                $store->translateOrNew('ar')->description = $request->input('description_ar', $store->translate('ar')->description ?? '');
            }

            $store->save();
        });

        $store->load([
            'city',
            'vendor',
            'images',                // << slider included
            'nearestPlumbers' => fn ($q) => $q->latest()->take(10),
        ]);

        return response()->json([
            'status'  => true,
            'message' => __('Plumber store updated successfully'),
            'data'    => new PlumberStoreResource($store),
        ]);
    }

    /**
     * DELETE /api/v1/plumber-stores/{id}
     * Vendor-only: delete own store
     */
    public function destroy($id)
    {
        $user = Auth::user();
        if (! $user || ! $user->isVendor()) {
            return response()->json(['status' => false, 'message' => 'Only vendors can delete stores'], 403);
        }

        $store = PlumberStore::find($id);
        if (! $store) {
            return response()->json(['status' => false, 'message' => __('Store not found')], 404);
        }
        if ((int) $store->vendor_id !== (int) $user->id) {
            return response()->json(['status' => false, 'message' => 'You do not own this store'], 403);
        }

        // delete main image
        if ($store->image) {
            Storage::disk('public')->delete($store->image);
        }
        // delete slider images
        foreach ($store->images as $img) {
            if ($img->path) {
                Storage::disk('public')->delete($img->path);
            }
            $img->delete();
        }

        // delete store media + social links
        foreach ($store->storeMedia as $media) {
            $media->delete();
        }
        $store->socialLinks()->delete();

        $store->delete();

        return response()->json(['status' => true, 'message' => __('Store deleted successfully')]);
    }

    /* =======================
     * Slider management
     * ======================= */

    /**
     * POST /api/v1/plumber-stores/{id}/slider
     * Vendor-only: upload slider images (one or many)
     * form-data: images[] (file, multiple), captions[] (optional), sort_orders[] (optional)
     */
    public function uploadSlider(Request $request, $id)
    {
        $user = Auth::user();
        if (! $user || ! $user->isVendor()) {
            return response()->json(['status' => false, 'message' => 'Only vendors can modify sliders'], 403);
        }

        $store = PlumberStore::find($id);
        if (! $store) {
            return response()->json(['status'=>false,'message'=>'Store not found'],404);
        }
        if ((int) $store->vendor_id !== (int) $user->id) {
            return response()->json(['status'=>false,'message'=>'You do not own this store'],403);
        }

        $request->validate([
            'images'        => ['required','array'],
            'images.*'      => ['required','image','max:4096'],
            'captions'      => ['sometimes','array'],
            'captions.*'    => ['nullable','string','max:255'],
            'sort_orders'   => ['sometimes','array'],
            'sort_orders.*' => ['nullable','integer','min:0'],
        ]);

        $created = [];
        DB::transaction(function () use ($request, $store, &$created) {
            $captions    = $request->input('captions', []);
            $sortOrders  = $request->input('sort_orders', []);
            foreach ($request->file('images', []) as $idx => $file) {
                $path = $file->store('plumber_store_slider', 'public');
                $img  = $store->images()->create([
                    'path'       => $path,
                    'caption'    => Arr::get($captions, $idx),
                    'sort_order' => (int) Arr::get($sortOrders, $idx, 0),
                ]);
                $created[] = $img->fresh();
            }
        });

        $store->load('images');
        return response()->json([
            'status'=>true,
            'message'=>'Slider images uploaded',
            'data'=>[
                'store_id'=>$store->id,
                'slider'=> $store->images->map(fn($img)=>[
                    'id'=>$img->id,'url'=>$img->url,'caption'=>$img->caption,'sort_order'=>$img->sort_order
                ]),
                'created'=> collect($created)->map(fn($img)=>[
                    'id'=>$img->id,'url'=>$img->url,'caption'=>$img->caption,'sort_order'=>$img->sort_order
                ]),
            ]
        ], 201);
    }

    /**
     * DELETE /api/v1/plumber-stores/{id}/slider/{imageId}
     * Vendor-only: delete one slider image
     */
    public function deleteSliderImage($id, $imageId)
    {
        $user = Auth::user();
        if (! $user || ! $user->isVendor()) {
            return response()->json(['status'=>false,'message'=>'Only vendors can modify sliders'],403);
        }

        $store = PlumberStore::find($id);
        if (! $store) return response()->json(['status'=>false,'message'=>'Store not found'],404);
        if ((int)$store->vendor_id !== (int)$user->id) {
            return response()->json(['status'=>false,'message'=>'You do not own this store'],403);
        }

        $img = $store->images()->whereKey($imageId)->first();
        if (! $img) return response()->json(['status'=>false,'message'=>'Image not found'],404);

        DB::transaction(function () use ($img) {
            if ($img->path) Storage::disk('public')->delete($img->path);
            $img->delete();
        });

        return response()->json(['status'=>true,'message'=>'Slider image deleted']);
    }

    /**
     * PUT /api/v1/plumber-stores/{id}/slider/order
     * Vendor-only: reorder slider images (bulk)
     * body (json): { "orders": [{ "id": 12, "sort_order": 0 }, ...] }
     */
    public function reorderSlider(Request $request, $id)
    {
        $user = Auth::user();
        if (! $user || ! $user->isVendor()) {
            return response()->json(['status'=>false,'message'=>'Only vendors can modify sliders'],403);
        }

        $store = PlumberStore::find($id);
        if (! $store) return response()->json(['status'=>false,'message'=>'Store not found'],404);
        if ((int)$store->vendor_id !== (int)$user->id) {
            return response()->json(['status'=>false,'message'=>'You do not own this store'],403);
        }

        $data = $request->validate([
            'orders'               => ['required','array'],
            'orders.*.id'          => ['required','integer'],
            'orders.*.sort_order'  => ['required','integer','min:0'],
        ]);

        DB::transaction(function () use ($store, $data) {
            foreach ($data['orders'] as $row) {
                $store->images()
                    ->whereKey($row['id'])
                    ->update(['sort_order' => (int)$row['sort_order']]);
            }
        });

        $store->load('images');
        return response()->json([
            'status'=>true,
            'message'=>'Slider order updated',
            'data'=>[
                'slider'=>$store->images->map(fn($img)=>[
                    'id'=>$img->id,'url'=>$img->url,'caption'=>$img->caption,'sort_order'=>$img->sort_order
                ])
            ]
        ]);
    }

    /**
     * POST /api/v1/plumber-stores/{id}/slider/replace
     * Vendor-only: replace ALL slider images in one go
     * form-data: images[] (files), captions[] (optional), sort_orders[] (optional)
     */
    public function replaceSlider(Request $request, $id)
    {
        $user = Auth::user();
        if (! $user || ! $user->isVendor()) {
            return response()->json(['status'=>false,'message'=>'Only vendors can modify sliders'],403);
        }

        $store = PlumberStore::find($id);
        if (! $store) return response()->json(['status'=>false,'message'=>'Store not found'],404);
        if ((int)$store->vendor_id !== (int)$user->id) {
            return response()->json(['status'=>false,'message'=>'You do not own this store'],403);
        }

        $request->validate([
            'images'        => ['required','array'],
            'images.*'      => ['required','image','max:4096'],
            'captions'      => ['sometimes','array'],
            'captions.*'    => ['nullable','string','max:255'],
            'sort_orders'   => ['sometimes','array'],
            'sort_orders.*' => ['nullable','integer','min:0'],
        ]);

        DB::transaction(function () use ($request, $store) {
            // delete old
            foreach ($store->images as $img) {
                if ($img->path) Storage::disk('public')->delete($img->path);
                $img->delete();
            }
            // add new
            $captions   = $request->input('captions', []);
            $sortOrders = $request->input('sort_orders', []);
            foreach ($request->file('images', []) as $idx => $file) {
                $path = $file->store('plumber_store_slider', 'public');
                $store->images()->create([
                    'path'       => $path,
                    'caption'    => Arr::get($captions, $idx),
                    'sort_order' => (int) Arr::get($sortOrders, $idx, $idx), // default by index
                ]);
            }
        });

        $store->load('images');
        return response()->json([
            'status'=>true,
            'message'=>'Slider replaced',
            'data'=>[
                'slider'=>$store->images->map(fn($img)=>[
                    'id'=>$img->id,'url'=>$img->url,'caption'=>$img->caption,'sort_order'=>$img->sort_order
                ])
            ]
        ]);
    }
}
