<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\UserProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    use ApiResponds;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['country', 'city']);

        return $this->success(new UserProfileResource($user));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'about_me' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'long_description' => ['sometimes', 'nullable', 'string'],
            'store_description' => ['sometimes', 'nullable', 'string'],
            'video_url' => ['sometimes', 'nullable', 'url'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'country_id' => ['sometimes', 'nullable', 'exists:countries,id'],
            'city_id' => ['sometimes', 'nullable', 'exists:cities,id'],
            'profile_photo' => ['sometimes', 'nullable', 'image', 'max:4096'],
        ]);

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo) {
                Storage::disk('public')->delete($user->profile_photo);
            }
            $user->profile_photo = $request->file('profile_photo')->store('profile_photos', 'public');
        }

        foreach ([
            'name', 'email', 'about_me', 'short_description', 'long_description',
            'store_description', 'video_url', 'address', 'latitude', 'longitude',
            'country_id', 'city_id',
        ] as $field) {
            if ($request->has($field)) {
                $user->{$field} = $request->input($field);
            }
        }

        $user->save();

        return $this->success(
            new UserProfileResource($user->fresh(['country', 'city'])),
            'تم تحديث الملف الشخصي'
        );
    }
}
