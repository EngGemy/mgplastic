<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Api\Concerns\HandlesStoreMedia;
use App\Http\Controllers\Controller;
use App\Http\Resources\NetworkStoreResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class NetworkStoreController extends Controller
{
    use HandlesStoreMedia;

    protected function resolveStoreMediaOwner(Request $request): Model
    {
        return $this->networkStoreUser($request);
    }

    protected function networkStoreUser(?Request $request = null): User
    {
        $user = $request?->user();

        if (! $user) {
            abort(response()->json([
                'status' => false,
                'message' => 'يجب تسجيل الدخول أولاً',
            ], 401));
        }

        if (! $user->isNetworkStore()) {
            abort(response()->json([
                'status' => false,
                'message' => 'هذه الخدمة متاحة لموزّعي الجملة وتجار التجزئة فقط',
            ], 403));
        }

        return $user;
    }

    /**
     * GET /api/v1/my-store
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->networkStoreUser($request);

        return $this->storeJsonResponse($user);
    }

    /**
     * PUT /api/v1/my-store
     */
    public function update(Request $request): JsonResponse
    {
        $user = $this->networkStoreUser($request);

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:20'],
            'brand_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'store_description' => ['sometimes', 'nullable', 'string'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'long_description' => ['sometimes', 'nullable', 'string'],
            'about_me' => ['sometimes', 'nullable', 'string'],
            'website' => ['sometimes', 'nullable', 'url', 'max:500'],
            'show_social_links' => ['sometimes', 'boolean'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'city_id' => ['sometimes', 'nullable', 'exists:cities,id'],
            'country_id' => ['sometimes', 'nullable', 'exists:countries,id'],
            'profile_photo' => ['sometimes', 'nullable', 'image', 'max:4096'],
            'brand_logo' => ['sometimes', 'nullable', 'image', 'max:4096'],
        ]);

        foreach (['name', 'phone', 'brand_name', 'address', 'store_description', 'short_description', 'long_description', 'about_me', 'website', 'latitude', 'longitude', 'city_id', 'country_id'] as $field) {
            if ($request->has($field)) {
                $user->{$field} = $request->input($field);
            }
        }

        if ($request->has('show_social_links')) {
            $user->show_social_links = $request->boolean('show_social_links');
        }

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo) {
                Storage::disk('public')->delete($user->profile_photo);
            }
            $user->profile_photo = $request->file('profile_photo')->store('profile_photos', 'public');
        }

        if ($request->hasFile('brand_logo')) {
            if ($user->brand_logo) {
                Storage::disk('public')->delete($user->brand_logo);
            }
            $user->brand_logo = $request->file('brand_logo')->store('brand_logos', 'public');
        }

        $user->save();

        return $this->storeJsonResponse($user, 'تم تحديث بيانات المتجر');
    }

    /**
     * GET /api/v1/network-stores
     * GET /api/v1/vendors  (alias)
     *
     * Directory depends on the logged-in role (send Bearer token):
     *  - plumber (or guest) → approved + active retail traders (order from shops)
     *  - retail_trader → approved + active wholesale distributors (order from wholesale)
     *
     * Query: city_id, country_id, q/search, per_page, page
     */
    public function publicIndex(Request $request): JsonResponse
    {
        $viewer = $this->optionalApiUser($request);
        $role = $this->directoryRoleFor($viewer);
        $perPage = max(1, min((int) $request->input('per_page', 15), 50));

        $query = User::query()
            ->where('role', $role)
            ->where('is_approved', true)
            ->where('is_active', true)
            ->with(['city', 'country', 'storeMedia', 'socialLinks']);

        if ($request->filled('city_id')) {
            $query->where('city_id', (int) $request->input('city_id'));
        }

        if ($request->filled('country_id')) {
            $query->where('country_id', (int) $request->input('country_id'));
        }

        $search = trim((string) ($request->input('q') ?? $request->input('search') ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('brand_name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('network_code', 'like', $like)
                    ->orWhere('address', 'like', $like);
            });
        }

        $stores = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => $role === 'wholesale_distributor'
                ? 'قائمة محلات الجملة'
                : 'قائمة محلات القطاعي',
            'data' => NetworkStoreResource::collection($stores->items()),
            'meta' => [
                'current_page' => $stores->currentPage(),
                'per_page' => $stores->perPage(),
                'total' => $stores->total(),
                'last_page' => $stores->lastPage(),
                'listed_role' => $role,
                'viewer_role' => $viewer?->role,
            ],
        ]);
    }

    /**
     * GET /api/v1/network-stores/{store}
     * {store} = user id (e.g. 92) or network_code (e.g. MG-W-000092 / MG-R-…)
     */
    public function publicShow(Request $request, string $store): JsonResponse
    {
        $viewer = $this->optionalApiUser($request);
        $user = $this->resolvePublicNetworkStore($store, $viewer);

        if (! $user) {
            return response()->json(['status' => false, 'message' => 'المتجر غير موجود'], 404);
        }

        if (! $user->is_approved || ! $user->is_active) {
            return response()->json(['status' => false, 'message' => 'المتجر غير متاح حالياً'], 404);
        }

        $user->load(['city', 'country', 'storeMedia.product', 'socialLinks']);

        return response()->json([
            'status' => true,
            'data' => new NetworkStoreResource($user),
        ]);
    }

    /**
     * Plumber → retail shops. Retail trader → wholesale shops.
     */
    protected function directoryRoleFor(?User $viewer): string
    {
        if ($viewer?->isRetailTrader()) {
            return 'wholesale_distributor';
        }

        return 'retail_trader';
    }

    protected function optionalApiUser(Request $request): ?User
    {
        $user = $request->user('sanctum') ?? $request->user();
        if ($user instanceof User) {
            return $user;
        }

        $bearer = $request->bearerToken();
        if (! $bearer) {
            return null;
        }

        $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($bearer);
        $tokenable = $accessToken?->tokenable;

        return $tokenable instanceof User ? $tokenable : null;
    }

    protected function resolvePublicNetworkStore(string $store, ?User $viewer = null): ?User
    {
        $key = trim($store);

        if ($key === '') {
            return null;
        }

        $role = $this->directoryRoleFor($viewer);
        $query = User::query()->where('role', $role);

        if (ctype_digit($key)) {
            return (clone $query)->whereKey((int) $key)->first();
        }

        $code = app(\App\Services\NetworkCodeService::class)->normalize($key);

        return (clone $query)->where('network_code', $code)->first();
    }

    protected function storeJsonResponse(User $user, ?string $actionMessage = null): JsonResponse
    {
        $user->load(['city', 'country', 'storeMedia.product', 'socialLinks']);
        $storeStatus = $user->networkStoreStatus();

        return response()->json([
            'status' => true,
            'message' => $actionMessage ?? $storeStatus['message'] ?? 'تم تحميل بيانات المتجر',
            'store_status' => [
                'code' => $storeStatus['code'],
                'is_approved' => (bool) $user->is_approved,
                'is_active' => (bool) $user->is_active,
                'is_public' => $storeStatus['is_public'],
                'notice' => $storeStatus['message'],
            ],
            'data' => new NetworkStoreResource($user),
        ]);
    }
}
