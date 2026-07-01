<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\WithdrawalResource;
use App\Models\WithdrawalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $withdrawals = WithdrawalRequest::query()
            ->where('plumber_id', $request->user()->id)
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => WithdrawalResource::collection($withdrawals->items()),
            'pagination' => [
                'current_page' => $withdrawals->currentPage(),
                'last_page' => $withdrawals->lastPage(),
                'per_page' => $withdrawals->perPage(),
                'total' => $withdrawals->total(),
            ],
        ]);
    }

    public function show(Request $request, WithdrawalRequest $withdrawal): JsonResponse
    {
        if ((int) $withdrawal->plumber_id !== (int) $request->user()->id) {
            return $this->error('غير مصرح', 403);
        }

        return $this->success(new WithdrawalResource($withdrawal));
    }
}
