<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PosCheckoutIdempotency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosCheckoutIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_key_same_payload_returns_cached_body(): void
    {
        Cache::flush();
        $user = User::factory()->create(['role' => 'wholesale_distributor', 'is_approved' => true, 'is_active' => true]);
        Sanctum::actingAs($user);

        $svc = app(PosCheckoutIdempotency::class);
        $calls = 0;
        $request = Request::create('/api/v1/mobile/distributor/pos/checkout', 'POST', [
            'retail_trader_id' => 1,
            'lines' => [['product_id' => 1, 'quantity' => 2]],
        ]);
        $request->headers->set('Idempotency-Key', '11111111-1111-1111-1111-111111111111');
        $request->setUserResolver(fn () => $user);

        $first = $svc->handle($request, function () use (&$calls) {
            $calls++;

            return response()->json(['status' => true, 'message' => 'ok', 'message_code' => 'ok', 'data' => ['n' => 1]], 201);
        });
        $second = $svc->handle($request, function () use (&$calls) {
            $calls++;

            return response()->json(['status' => true, 'message' => 'ok', 'message_code' => 'ok', 'data' => ['n' => 2]], 201);
        });

        $this->assertSame(1, $calls);
        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(201, $second->getStatusCode());
        $this->assertSame(1, $second->getData(true)['data']['n']);
    }

    public function test_same_key_different_payload_returns_422(): void
    {
        Cache::flush();
        $user = User::factory()->create(['role' => 'wholesale_distributor', 'is_approved' => true, 'is_active' => true]);
        $svc = app(PosCheckoutIdempotency::class);

        $req1 = Request::create('/x', 'POST', ['a' => 1]);
        $req1->headers->set('Idempotency-Key', 'key-a');
        $req1->setUserResolver(fn () => $user);

        $svc->handle($req1, fn () => response()->json(['status' => true, 'data' => 1], 201));

        $req2 = Request::create('/x', 'POST', ['a' => 2]);
        $req2->headers->set('Idempotency-Key', 'key-a');
        $req2->setUserResolver(fn () => $user);

        $res = $svc->handle($req2, fn () => response()->json(['status' => true, 'data' => 9], 201));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $res->getData(true)['error_code']);
    }

    public function test_without_header_always_executes(): void
    {
        $user = User::factory()->create(['role' => 'wholesale_distributor']);
        $svc = app(PosCheckoutIdempotency::class);
        $calls = 0;
        $req = Request::create('/x', 'POST', ['a' => 1]);
        $req->setUserResolver(fn () => $user);

        $svc->handle($req, function () use (&$calls) {
            $calls++;

            return new JsonResponse(['ok' => true], 201);
        });
        $svc->handle($req, function () use (&$calls) {
            $calls++;

            return new JsonResponse(['ok' => true], 201);
        });

        $this->assertSame(2, $calls);
    }
}
