<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Api\Concerns\HandlesStoreMedia;
use App\Http\Controllers\Controller;
use App\Http\Resources\NetworkStoreResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class NetworkStoreController extends Controller
{
    use HandlesStoreMedia;

    protected function resolveStoreMediaOwner(Request $request): Model
    {
        return $this->networkStoreUser();
    }

    protected function networkStoreUser(): User
    {
        $user = Auth::user();

        if (! $user || ! $this->isNetworkStoreRole($user)) {
            abort(response()->json([
                'status' => false,
                'message' => 'هذه الخدمة متاحة لمتاجر شبكة النقاط فقط',
            ], 403));
        }

        return $user;
    }

    protected function isNetworkStoreRole(User $user): bool
    {
        return $user->isWholesaleDistributor() || $user->isRetailTrader();
    }

    /**
     * GET /api/v1/my-store
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->networkStoreUser();
        $user->load(['city', 'country', 'storeMedia.product', 'socialLinks']);

        return response()->json([
            'status' => true,
            'data' => new NetworkStoreResource($user),
        ]);
    }

    /**
     * PUT /api/v1/my-store
     */
    public function update(Request $request): JsonResponse
    {
        $user = $this->networkStoreUser();

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'store_description' => ['sometimes', 'nullable', 'string'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'long_description' => ['sometimes', 'nullable', 'string'],
            'about_me' => ['sometimes', 'nullable', 'string'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'city_id' => ['sometimes', 'nullable', 'exists:cities,id'],
            'country_id' => ['sometimes', 'nullable', 'exists:countries,id'],
            'profile_photo' => ['sometimes', 'nullable', 'image', 'max:4096'],
        ]);

        foreach (['name', 'phone', 'address', 'store_description', 'short_description', 'long_description', 'about_me', 'latitude', 'longitude', 'city_id', 'country_id'] as $field) {
            if ($request->has($field)) {
                $user->{$field} = $request->input($field);
            }
        }

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo) {
                Storage::disk('public')->delete($user->profile_photo);
            }
            $user->profile_photo = $request->file('profile_photo')->store('profile_photos', 'public');
        }

        $user->save();
        $user->load(['city', 'country', 'storeMedia.product', 'socialLinks']);

        return response()->json([
            'status' => true,
            'message' => 'تم تحديث بيانات المتجر',
            'data' => new NetworkStoreResource($user),
        ]);
    }

    /**
     * GET /api/v1/network-stores/{user}
     */
    public function publicShow(User $user): JsonResponse
    {
        if (! $this->isNetworkStoreRole($user)) {
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
}
