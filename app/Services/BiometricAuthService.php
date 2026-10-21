<?php

namespace App\Services;

use App\Models\BiometricDevice;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BiometricAuthService
{
    public const CHALLENGE_TTL_SECONDS = 120;

    /**
     * Register / refresh biometric unlock for a device.
     * Returns plain unlock_token once — app must store it behind Face ID / fingerprint.
     *
     * @return array{device: BiometricDevice, unlock_token: string}
     */
    public function register(
        User $user,
        string $deviceId,
        ?string $deviceName = null,
        ?string $platform = null,
        ?string $publicKeyPem = null,
    ): array {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            throw new \DomainException('device_id مطلوب');
        }

        $unlockToken = Str::random(64);

        $device = BiometricDevice::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'device_id' => $deviceId,
            ],
            [
                'device_name' => $deviceName,
                'platform' => $platform ? strtolower($platform) : null,
                'public_key' => $this->normalizePublicKey($publicKeyPem),
                'unlock_token_hash' => hash('sha256', $unlockToken),
                'is_active' => true,
            ],
        );

        return [
            'device' => $device->fresh(),
            'unlock_token' => $unlockToken,
        ];
    }

    /**
     * @return array{challenge_id: string, nonce: string, expires_at: string}
     */
    public function issueChallenge(User $user, string $deviceId): array
    {
        $device = $this->findActiveDevice($user, $deviceId);
        if (! $device) {
            throw new \DomainException('الجهاز غير مسجّل للتحقق البيومتري — سجّل الدخول ثم فعّل البصمة أولاً');
        }

        $challengeId = (string) Str::uuid();
        $nonce = Str::random(48);
        $expiresAt = now()->addSeconds(self::CHALLENGE_TTL_SECONDS);

        Cache::put($this->challengeCacheKey($challengeId), [
            'user_id' => $user->id,
            'device_id' => $device->device_id,
            'nonce' => $nonce,
        ], $expiresAt);

        return [
            'challenge_id' => $challengeId,
            'nonce' => $nonce,
            'expires_at' => $expiresAt->toIso8601String(),
            'device_id' => $device->device_id,
        ];
    }

    /**
     * Verify unlock_token and/or signature of nonce, then issue Sanctum token.
     *
     * @return array{user: User, token: string, device: BiometricDevice}
     */
    public function verify(
        string $challengeId,
        string $deviceId,
        ?string $unlockToken = null,
        ?string $signatureBase64 = null,
    ): array {
        $payload = Cache::pull($this->challengeCacheKey($challengeId));
        if (! is_array($payload)) {
            throw new \DomainException('انتهت صلاحية التحدي البيومتري — اطلب تحدياً جديداً');
        }

        if (($payload['device_id'] ?? '') !== $deviceId) {
            throw new \DomainException('الجهاز غير مطابق للتحدي');
        }

        $user = User::query()->find($payload['user_id'] ?? 0);
        if (! $user) {
            throw new \DomainException('المستخدم غير موجود');
        }

        $device = $this->findActiveDevice($user, $deviceId);
        if (! $device) {
            throw new \DomainException('الجهاز غير مفعّل للتحقق البيومتري');
        }

        $nonce = (string) ($payload['nonce'] ?? '');
        $ok = false;

        if (filled($unlockToken) && $device->matchesUnlockToken($unlockToken)) {
            $ok = true;
        }

        if (! $ok && filled($signatureBase64) && filled($device->public_key)) {
            $ok = $this->verifySignature($device->public_key, $nonce, $signatureBase64);
        }

        if (! $ok) {
            throw new \DomainException('فشل التحقق البيومتري — unlock_token أو التوقيع غير صالح');
        }

        if (! $user->is_active) {
            throw new \DomainException('حسابك موقوف أو تم حذفه — سجّل الدخول من جديد');
        }

        if (! $user->isNetworkStore() && method_exists($user, 'isApprovedAndActive') && ! $user->isApprovedAndActive()) {
            throw new \DomainException($user->is_approved
                ? 'حسابك موقوف — تواصل مع الدعم'
                : 'حسابك بانتظار موافقة الإدارة');
        }

        $device->forceFill(['last_used_at' => now()])->save();

        $token = $user->createToken('biometric_'.$device->device_id)->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
            'device' => $device,
        ];
    }

    public function revoke(User $user, string $deviceId): void
    {
        BiometricDevice::query()
            ->where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->update(['is_active' => false, 'unlock_token_hash' => null, 'public_key' => null]);
    }

    public function revokeAll(User $user): void
    {
        BiometricDevice::query()
            ->where('user_id', $user->id)
            ->update(['is_active' => false, 'unlock_token_hash' => null, 'public_key' => null]);
    }

    /** @return list<BiometricDevice> */
    public function listForUser(User $user): array
    {
        return BiometricDevice::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    protected function findActiveDevice(User $user, string $deviceId): ?BiometricDevice
    {
        return BiometricDevice::query()
            ->where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->where('is_active', true)
            ->first();
    }

    protected function challengeCacheKey(string $challengeId): string
    {
        return 'biometric_challenge:'.$challengeId;
    }

    protected function normalizePublicKey(?string $pem): ?string
    {
        if (! filled($pem)) {
            return null;
        }

        $pem = trim($pem);
        if (! str_contains($pem, 'BEGIN')) {
            // Accept base64 DER → wrap as SPKI PEM
            $pem = "-----BEGIN PUBLIC KEY-----\n"
                .chunk_split(preg_replace('/\s+/', '', $pem) ?? '', 64, "\n")
                ."-----END PUBLIC KEY-----";
        }

        return $pem;
    }

    protected function verifySignature(string $publicKeyPem, string $nonce, string $signatureBase64): bool
    {
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false || $nonce === '') {
            return false;
        }

        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            return false;
        }

        $result = openssl_verify($nonce, $signature, $key, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }
}
