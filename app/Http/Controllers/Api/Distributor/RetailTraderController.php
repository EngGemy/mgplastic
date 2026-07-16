<?php

namespace App\Http\Controllers\Api\Distributor;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\UserProfileResource;
use App\Models\User;
use App\Services\RetailNetworkLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetailTraderController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $items = app(RetailNetworkLinkService::class)
            ->linkedRetailersFor($request->user())
            ->load(['country', 'city']);

        $page = max(1, $request->integer('page', 1));
        $perPage = max(1, min(100, $request->integer('per_page', 50)));
        $slice = $items->forPage($page, $perPage)->values();

        return $this->success([
            'items' => UserProfileResource::collection($slice),
            'pagination' => [
                'current_page' => $page,
                'last_page' => (int) max(1, ceil($items->count() / $perPage)),
                'per_page' => $perPage,
                'total' => $items->count(),
            ],
        ]);
    }

    public function show(Request $request, User $retailTrader): JsonResponse
    {
        if ($retailTrader->role !== 'retail_trader'
            || ! app(RetailNetworkLinkService::class)->isLinked($request->user(), $retailTrader)) {
            return $this->error('غير مصرح', 403);
        }

        $retailTrader->load(['country', 'city']);

        return $this->success(new UserProfileResource($retailTrader));
    }

    /** POST /distributor/retail-traders/link — link by network code */
    public function link(Request $request): JsonResponse
    {
        $data = $request->validate([
            'network_code' => ['required', 'string', 'max:40'],
        ]);

        try {
            $result = app(RetailNetworkLinkService::class)->linkByCode(
                wholesaler: $request->user(),
                codeOrPhone: $data['network_code'],
                linkedBy: $request->user(),
            );

            return $this->success([
                'created_link' => $result['created_link'],
                'retail_trader' => new UserProfileResource($result['retail']),
            ], $result['message']);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
