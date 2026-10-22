<?php

namespace App\Http\Controllers\Api\Distributor;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\WholesalerJoinRequest;
use App\Services\WholesalerJoinRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class JoinRequestController extends Controller
{
    use ApiResponds;

    public function __construct(
        protected WholesalerJoinRequestService $requests,
    ) {}

    /** GET /api/v1/mobile/distributor/join-requests?status=pending */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');
        $status = is_string($status) ? $status : 'pending';
        if ($status === 'all') {
            $status = null;
        }

        $items = $this->requests->incomingFor($request->user(), $status)
            ->map->toApiArray()
            ->values();

        return $this->success([
            'items' => $items,
            'pending_count' => $this->requests->pendingIncomingCount($request->user()),
        ], 'طلبات انضمام التجار القطاعيين');
    }

    /** POST /api/v1/mobile/distributor/join-requests/{joinRequest}/approve */
    public function approve(Request $request, WholesalerJoinRequest $joinRequest): JsonResponse
    {
        try {
            $approved = $this->requests->approve($request->user(), $joinRequest);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'request' => $approved->toApiArray(),
        ], 'تمت الموافقة — التاجر أُضيف لشبكتك');
    }

    /** POST /api/v1/mobile/distributor/join-requests/{joinRequest}/reject */
    public function reject(Request $request, WholesalerJoinRequest $joinRequest): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($v->fails()) {
            return $this->error('سبب الرفض غير صالح', 422, $v->errors());
        }

        try {
            $rejected = $this->requests->reject(
                $request->user(),
                $joinRequest,
                $request->input('reason')
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'request' => $rejected->toApiArray(),
        ], 'تم رفض طلب الانضمام');
    }
}
