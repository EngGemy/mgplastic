<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\PhoneOtpService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $phone = $request->phone;

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $phone,
            'password' => Hash::make($request->password),
            'role' => 'customer',
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

        try {
            app(PhoneOtpService::class)->issue($user, reason: 'register');
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?: 'تم إنشاء الحساب لكن تعذّر إرسال رمز التحقق',
            ], 429);
        }

        return response()->json([
            'status' => true,
            'message' => 'تم التسجيل — تم إرسال رمز التحقق',
            'data' => [
                'user' => $user->fresh(),
                'token' => $user->createToken('auth_token')->plainTextToken,
            ],
        ]);
    }
}
