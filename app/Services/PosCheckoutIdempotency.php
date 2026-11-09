<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backward-compatible wrapper around ApiIdempotency for POS checkout.
 */
class PosCheckoutIdempotency
{
    public const TTL_HOURS = ApiIdempotency::TTL_HOURS;

    public function __construct(protected ApiIdempotency $idempotency) {}

    /**
     * @param  callable(): JsonResponse  $execute
     */
    public function handle(Request $request, callable $execute): JsonResponse
    {
        return $this->idempotency->handle($request, 'pos:checkout', $execute);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function payloadHash(array $payload): string
    {
        return $this->idempotency->payloadHash($payload);
    }
}
