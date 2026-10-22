<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\WholesalerJoinRequest;
use App\Services\WholesalerJoinRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WholesalerJoinRequestController extends Controller
{
    use ApiResponds;

    public function __construct(
        protected WholesalerJoinRequestService $requests,
    ) {}

    /** GET /api/v1/mobile/trader/wholesaler-join-requests */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $status = is_string($status) && $status !== '' ? $status : null;

        $items = $this->requests->outgoingFor($request->user(), $status)
            ->map->toApiArray()
            ->values();

        return $this->success([
            'items' => $items,
            'pending_count' => $items->where('status', WholesalerJoinRequest::STATUS_PENDING)->count(),
        ], 'طلبات انضمامك لموزّعي الجملة');
    }

    /**
     * POST /api/v1/mobile/trader/wholesaler-join-requests
     * Body: network_code|code|phone + optional message
     */
    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'network_code' => ['required_without_all:code,phone', 'nullable', 'string', 'max:50'],
            'code' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        if ($v->fails()) {
            return $this->error('أدخل الرقم الموحّد أو رقم الهاتف لموزّع الجملة', 422, $v->errors());
        }

        $needle = trim((string) (
            $request->input('network_code')
            ?: $request->input('code')
            ?: $request->input('phone')
        ));

        try {
            $result = $this->requests->requestJoin(
                $request->user(),
                $needle,
                $request->input('message')
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'created' => $result['created'],
            'request' => $result['request']->toApiArray(),
        ], $result['message'], $result['created'] ? 201 : 200);
    }

    /** DELETE /api/v1/mobile/trader/wholesaler-join-requests/{joinRequest} */
    public function destroy(Request $request, WholesalerJoinRequest $joinRequest): JsonResponse
    {
        try {
            $cancelled = $this->requests->cancel($request->user(), $joinRequest);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'request' => $cancelled->toApiArray(),
        ], 'تم إلغاء طلب الانضمام');
    }
}
