<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\NetworkStoreResource;
use App\Models\User;
use App\Services\NetworkStoreDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteStoreController extends Controller
{
    use ApiResponds;

    public function __construct(
        protected NetworkStoreDirectoryService $directory,
    ) {}

    /** GET /api/v1/mobile/{role}/favorite-stores */
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $origin = $this->directory->resolveOrigin($request, $viewer);
        $listedRole = $this->directory->directoryRoleFor($viewer);

        $stores = $viewer->favoriteStores()
            ->where('users.role', $listedRole)
            ->where('users.is_approved', true)
            ->where('users.is_active', true)
            ->with(['city', 'country', 'storeMedia', 'socialLinks'])
            ->orderByPivot('created_at', 'desc')
            ->get();

        $favoriteIds = $stores->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->directory->annotatePage($stores, $origin, $favoriteIds);

        if ($origin['source'] !== 'none') {
            $stores = $stores->sortBy(fn (User $s) => $s->distance_km ?? PHP_FLOAT_MAX)->values();
        }

        return $this->success([
            'items' => NetworkStoreResource::collection($stores)->resolve(),
            'count' => $stores->count(),
            'origin' => $origin,
        ], 'المحلات المفضلة');
    }

    /** POST /api/v1/mobile/{role}/favorite-stores/{store} */
    public function store(Request $request, string $store): JsonResponse
    {
        $viewer = $request->user();
        $shop = $this->resolveStore($store);

        if (! $shop) {
            return $this->error('المحل غير موجود', 404);
        }

        try {
            $this->directory->assertFavoriteableStore($viewer, $shop);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $viewer->favoriteStores()->syncWithoutDetaching([$shop->id]);

        $shop->load(['city', 'country', 'storeMedia', 'socialLinks']);
        $origin = $this->directory->resolveOrigin($request, $viewer);
        $this->directory->annotatePage(collect([$shop]), $origin, [$shop->id]);

        return $this->success([
            'store' => (new NetworkStoreResource($shop))->resolve(),
            'is_favorite' => true,
        ], 'تمت إضافة المحل للمفضلة');
    }

    /** DELETE /api/v1/mobile/{role}/favorite-stores/{store} */
    public function destroy(Request $request, string $store): JsonResponse
    {
        $viewer = $request->user();
        $shop = $this->resolveStore($store);

        if (! $shop) {
            return $this->error('المحل غير موجود', 404);
        }

        $viewer->favoriteStores()->detach($shop->id);

        return $this->success([
            'store_id' => (int) $shop->id,
            'is_favorite' => false,
        ], 'تمت إزالة المحل من المفضلة');
    }

    protected function resolveStore(string $store): ?User
    {
        $key = trim($store);
        if ($key === '') {
            return null;
        }

        if (ctype_digit($key)) {
            return User::query()->find((int) $key);
        }

        $code = app(\App\Services\NetworkCodeService::class)->normalize($key);

        return User::query()->where('network_code', $code)->first();
    }
}
