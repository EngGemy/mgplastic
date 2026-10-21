<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\OtpTicketService;
use App\Services\PhoneOtpService;
use Illuminate\Support\Facades\Hash;

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
            'is_phone_verified' => false,
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

        $otp = app(PhoneOtpService::class);
        $ticket = app(OtpTicketService::class)->issue($user);
        $channels = $otp->channelOptions();
        $fresh = $user->fresh();

        return response()->json([
            'status' => true,
            'message' => 'تم التسجيل — اختر طريقة إرسال رمز التحقق',
            'code' => 'otp_required',
            'requires_otp' => true,
            'otp_sent' => false,
            'token' => null,
            'otp_ticket' => $ticket,
            'otp_channels' => $channels,
            'otp_ticket_ttl_minutes' => OtpTicketService::TTL_MINUTES,
            'user' => $fresh,
            'data' => [
                'user' => $fresh,
                'token' => null,
                'requires_otp' => true,
                'otp_sent' => false,
                'otp_ticket' => $ticket,
                'otp_channels' => $channels,
                'otp_ticket_ttl_minutes' => OtpTicketService::TTL_MINUTES,
            ],
        ]);
    }
}
