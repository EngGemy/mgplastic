<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\UserProfileResource;
use App\Models\BiometricDevice;
use App\Models\User;
use App\Services\BiometricAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BiometricController extends Controller
{
    use ApiResponds;

    public function __construct(protected BiometricAuthService $biometric) {}

    /** POST /mobile/biometric/register — auth required */
    public function register(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'device_id' => ['required', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'in:ios,android'],
            'public_key' => ['nullable', 'string', 'max:8000'],
        ]);

        if ($v->fails()) {
            return $this->error('بيانات الجهاز غير صالحة', 422, $v->errors());
        }

        try {
            $result = $this->biometric->register(
                user: $request->user(),
                deviceId: (string) $request->input('device_id'),
                deviceName: $request->input('device_name'),
                platform: $request->input('platform'),
                publicKeyPem: $request->input('public_key'),
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        /** @var BiometricDevice $device */
        $device = $result['device'];

        return $this->created([
            'device' => $this->mapDevice($device),
            'unlock_token' => $result['unlock_token'],
            'instructions' => [
                'store_unlock_token_in_secure_storage_behind_biometric',
                'never_log_or_sync_unlock_token',
                'use_challenge_then_verify_to_get_a_new_sanctum_token',
            ],
        ], 'تم تفعيل التحقق البيومتري لهذا الجهاز — احفظ unlock_token فوراً');
    }

    /** GET /mobile/biometric/devices — auth */
    public function devices(Request $request): JsonResponse
    {
        $items = collect($this->biometric->listForUser($request->user()))
            ->map(fn (BiometricDevice $d) => $this->mapDevice($d))
            ->values();

        return $this->success(['items' => $items], 'أجهزة البصمة');
    }

    /** DELETE /mobile/biometric/devices/{deviceId} — auth */
    public function revoke(Request $request, string $deviceId): JsonResponse
    {
        $this->biometric->revoke($request->user(), $deviceId);

        return $this->success(null, 'تم إلغاء التحقق البيومتري لهذا الجهاز');
    }

    /** POST /mobile/biometric/revoke-all — auth */
    public function revokeAll(Request $request): JsonResponse
    {
        $this->biometric->revokeAll($request->user());

        return $this->success(null, 'تم إلغاء التحقق البيومتري من كل الأجهزة');
    }

    /**
     * POST /mobile/biometric/challenge — public
     * Body: login|phone|email + device_id
     */
    public function challenge(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'login' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'string'],
            'device_id' => ['required', 'string', 'max:120'],
        ]);

        if ($v->fails()) {
            return $this->error('بيانات غير صالحة', 422, $v->errors());
        }

        $identifier = trim((string) (
            $request->input('login')
            ?? $request->input('phone')
            ?? $request->input('email')
            ?? ''
        ));

        if ($identifier === '') {
            return $this->error('أدخل رقم الهاتف أو البريد', 422);
        }

        $user = User::findByLoginIdentifier($identifier);
        if (! $user) {
            // Avoid user enumeration
            return $this->error('تعذّر بدء التحقق البيومتري', 422);
        }

        try {
            $challenge = $this->biometric->issueChallenge($user, (string) $request->input('device_id'));
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($challenge, 'تحدي بيومتري — صالح لدقيقتين');
    }

    /**
     * POST /mobile/biometric/verify — public
     * Body: challenge_id, device_id, unlock_token? , signature? (base64 of signed nonce)
     */
    public function verify(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'challenge_id' => ['required', 'string', 'max:80'],
            'device_id' => ['required', 'string', 'max:120'],
            'unlock_token' => ['nullable', 'string', 'max:128'],
            'signature' => ['nullable', 'string', 'max:4000'],
        ]);

        if ($v->fails()) {
            return $this->error('بيانات التحقق غير صالحة', 422, $v->errors());
        }

        if (! $request->filled('unlock_token') && ! $request->filled('signature')) {
            return $this->error('أرسل unlock_token أو signature', 422);
        }

        try {
            $result = $this->biometric->verify(
                challengeId: (string) $request->input('challenge_id'),
                deviceId: (string) $request->input('device_id'),
                unlockToken: $request->input('unlock_token'),
                signatureBase64: $request->input('signature'),
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 401);
        }

        $user = $result['user']->load(['country', 'city']);

        return $this->success([
            'token' => $result['token'],
            'user' => new UserProfileResource($user),
            'device' => $this->mapDevice($result['device']),
            'auth_method' => 'biometric',
        ], 'تم تسجيل الدخول بالتحقق البيومتري');
    }

    /** @return array<string, mixed> */
    protected function mapDevice(BiometricDevice $device): array
    {
        return [
            'id' => $device->id,
            'device_id' => $device->device_id,
            'device_name' => $device->device_name,
            'platform' => $device->platform,
            'has_public_key' => filled($device->public_key),
            'is_active' => (bool) $device->is_active,
            'last_used_at' => $device->last_used_at?->toIso8601String(),
            'created_at' => $device->created_at?->toIso8601String(),
        ];
    }
}
