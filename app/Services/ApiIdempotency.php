<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Optional Idempotency-Key for POST endpoints (POS checkout + order place).
 */
class ApiIdempotency
{
    public const TTL_HOURS = 24;

    /**
     * @param  callable(): JsonResponse  $execute
     */
    public function handle(Request $request, string $scope, callable $execute): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '') {
            return $execute();
        }

        if (strlen($key) > 128) {
            return response()->json([
                'status' => false,
                'message' => 'مفتاح التكرار غير صالح',
                'message_code' => 'idempotency_key_invalid',
                'error_code' => 'IDEMPOTENCY_KEY_INVALID',
            ], 422);
        }

        $userId = (int) $request->user()->id;
        $cacheKey = $this->cacheKey($scope, $userId, $key);
        $payloadHash = $this->payloadHash($request->all());

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['payload_hash'], $cached['body'], $cached['status'])) {
            if (($cached['payload_hash'] ?? '') !== $payloadHash) {
                return response()->json([
                    'status' => false,
                    'message' => 'نفس مفتاح التكرار بجسم طلب مختلف',
                    'message_code' => 'idempotency_key_reused',
                    'error_code' => 'IDEMPOTENCY_KEY_REUSED',
                ], 422);
            }

            return response()->json($cached['body'], (int) $cached['status']);
        }

        $response = $execute();
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            Cache::put($cacheKey, [
                'payload_hash' => $payloadHash,
                'status' => $status,
                'body' => $response->getData(true),
            ], now()->addHours(self::TTL_HOURS));
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function payloadHash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    protected function cacheKey(string $scope, int $userId, string $key): string
    {
        return 'api:idempotency:'.$scope.':'.$userId.':'.$key;
    }
}
