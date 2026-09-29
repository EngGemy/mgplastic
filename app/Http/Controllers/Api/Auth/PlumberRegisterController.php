<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\City;
use App\Traits\SendsMarsolSmsOtp;

class PlumberRegisterController extends Controller
{
    use SendsMarsolSmsOtp;

    public function register(Request $request)
    {
        // Normalize role
        $request->merge([
            'role' => strtolower($request->input('role', $request->input('type', 'plumber')))
        ]);

        // Validate input
        $validator = Validator::make($request->all(), [
            'name'          => 'required|string|max:255',
            'phone'         => 'required|string|unique:users,phone',
            'country_id'    => 'required|exists:countries,id',
            'city_id'       => 'required|exists:cities,id',
            'password'      => 'required|string|min:6|confirmed',
            'profile_photo' => 'nullable|image|max:2048',
            'role'          => 'required|in:plumber,vendor',
        ], [
            'role.in' => 'Role must be either plumber or vendor.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        // Validate city belongs to country
        $city = City::where('id', $request->city_id)
            ->where('country_id', $request->country_id)
            ->first();

        if (! $city) {
            return response()->json([
                'status'  => false,
                'message' => 'City does not belong to the selected country'
            ], 422);
        }

        // Save image if exists
        $imagePath = $request->hasFile('profile_photo')
            ? $request->file('profile_photo')->store('profile_photos', 'public')
            : null;

        $phone = $request->phone;
        $lang = app()->getLocale() === 'ar' ? 'AR' : 'EN';

        // Create user
        $user = DB::transaction(function () use ($request, $imagePath) {
            return User::create([
                'name' => $request->name,
                'phone' => $request->phone,
                'country_id' => $request->country_id,
                'city_id' => $request->city_id,
                'password' => Hash::make($request->password),
                'role' => $request->role,
                'profile_photo' => $imagePath,
                'otp_code' => null,
                'otp_expires_at' => null,
                'is_approved' => true,
                'is_active' => true,
            ]);
        });

        // Load relationships for response
        $user->load(['country:id,name_en,name_ar', 'city:id,country_id,name_en,name_ar']);
        $nameCol = app()->getLocale() === 'ar' ? 'name_ar' : 'name_en';

        $issued = $this->issueMarsolPhoneOtp($phone, 6, 300, 'WEB', $lang, 'CODE');

        if (! $issued) {
            return response()->json([
                'status' => false,
                'message' => 'User created, but OTP sending failed.',
            ], 500);
        }

        $this->applyMarsolOtpToUser($user, $issued);

        return response()->json([
            'status' => true,
            'message' => ucfirst($request->role).' registered. OTP sent.',
            'data' => [
                'user' => $user->fresh(['country:id,name_en,name_ar', 'city:id,country_id,name_en,name_ar']),

                'location' => [
                    'country' => $user->country ? ['id' => $user->country->id, 'name' => $user->country->{$nameCol}] : null,
                    'city'    => $user->city ? ['id' => $user->city->id, 'name' => $user->city->{$nameCol}] : null,
                ],
            ]
        ], 201);
    }
}
