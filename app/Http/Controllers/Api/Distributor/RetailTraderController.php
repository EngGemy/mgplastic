<?php

namespace App\Http\Controllers\Api\Distributor;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\UserProfileResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetailTraderController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $traders = User::query()
            ->where('role', 'retail_trader')
            ->where('parent_distributor_id', $request->user()->id)
            ->with(['country', 'city'])
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return $this->success([
            'items' => UserProfileResource::collection($traders->items()),
            'pagination' => [
                'current_page' => $traders->currentPage(),
                'last_page' => $traders->lastPage(),
                'per_page' => $traders->perPage(),
                'total' => $traders->total(),
            ],
        ]);
    }

    public function show(Request $request, User $retailTrader): JsonResponse
    {
        if ($retailTrader->role !== 'retail_trader'
            || (int) $retailTrader->parent_distributor_id !== (int) $request->user()->id) {
            return $this->error('غير مصرح', 403);
        }

        $retailTrader->load(['country', 'city']);

        return $this->success(new UserProfileResource($retailTrader));
    }
}
