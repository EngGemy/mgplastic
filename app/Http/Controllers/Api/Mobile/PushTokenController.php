<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Services\FcmPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PushTokenController extends Controller
{
    use ApiResponds;

    public function store(Request $request, FcmPushService $fcm): JsonResponse
    {
        if (! Schema::hasTable('push_tokens')) {
            Log::error('[FCM] push_tokens table missing — run migrations');

            return $this->error('جدول رموز الإشعارات غير جاهز على السيرفر', 503);
        }

        $this->normalizePushPayload($request);

        try {
            $data = $request->validate([
                'token' => ['required', 'string', 'min:20', 'max:4096'],
                'platform' => ['required', 'string', 'in:ios,android'],
                'device_id' => ['required', 'string', 'min:4', 'max:191'],
            ]);
        } catch (ValidationException $e) {
            Log::warning('[FCM] push-token validation failed', [
                'user_id' => $request->user()?->id,
                'errors' => $e->errors(),
                'keys' => array_keys($request->all()),
            ]);

            throw $e;
        }

        $row = $fcm->register(
            $request->user(),
            $data['token'],
            $data['platform'],
            $data['device_id'],
        );

        Log::info('[FCM] push-token saved', [
            'user_id' => $request->user()->id,
            'device_id' => $row->device_id,
            'platform' => $row->platform,
            'token_suffix' => substr($row->token, -8),
        ]);

        return $this->success([
            'device_id' => $row->device_id,
            'platform' => $row->platform,
            'is_active' => (bool) ($row->is_active ?? true),
        ], 'تم حفظ رمز الإشعارات');
    }

    public function destroy(Request $request, FcmPushService $fcm): JsonResponse
    {
        if (! $request->filled('device_id') && $request->filled('deviceId')) {
            $request->merge(['device_id' => (string) $request->input('deviceId')]);
        }

        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:191'],
        ]);

        $fcm->forgetDevice($data['device_id']);

        return $this->success(null, 'تم حذف رمز الإشعارات');
    }

    /**
     * Accept common mobile payload shapes before validation.
     */
    protected function normalizePushPayload(Request $request): void
    {
        $token = $request->input('token')
            ?? $request->input('fcm_token')
            ?? $request->input('fcmToken')
            ?? $request->input('registration_token')
            ?? $request->input('registrationToken')
            ?? $request->input('push_token')
            ?? $request->input('pushToken');

        $deviceId = $request->input('device_id')
            ?? $request->input('deviceId')
            ?? $request->input('device_uuid')
            ?? $request->input('installation_id');

        $platform = $request->input('platform')
            ?? $request->input('os')
            ?? $request->input('device_platform');

        if (is_string($token)) {
            $token = trim($token);
        }

        if (is_string($deviceId)) {
            $deviceId = trim($deviceId);
        }

        if (is_string($platform)) {
            $platform = strtolower(trim($platform));
            $platform = match ($platform) {
                'iphone', 'ipad', 'ios' => 'ios',
                'android', 'andorid' => 'android', // common typo
                default => $platform,
            };
        }

        $request->merge(array_filter([
            'token' => is_string($token) && $token !== '' ? $token : null,
            'device_id' => is_string($deviceId) && $deviceId !== '' ? $deviceId : null,
            'platform' => is_string($platform) && $platform !== '' ? $platform : null,
        ], fn ($v) => $v !== null));
    }
}
