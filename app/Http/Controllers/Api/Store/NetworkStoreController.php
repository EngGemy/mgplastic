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
     * GET /api/v1/network-stores/{user}
     */
    public function publicShow(User $user): JsonResponse
    {
        if (! $user->isNetworkStore()) {
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
