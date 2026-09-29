<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Traits\SendsMarsolSmsOtp;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    use SendsMarsolSmsOtp;

    public function register(RegisterRequest $request)
    {
        $phone = $request->phone;
        $lang = app()->getLocale() === 'ar' ? 'AR' : 'EN';

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $phone,
            'password' => Hash::make($request->password),
            'role' => 'customer',
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

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
            'message' => 'User registered. OTP sent.',
            'data' => [
                'user' => $user->fresh(),
                'token' => $user->createToken('auth_token')->plainTextToken,
            ],
        ]);
    }
}
