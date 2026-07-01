<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\NetworkInventoryService;
use App\Services\PlumberDistributionPosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PosController extends Controller
{
    use ApiResponds;

    public function __construct(
        protected NetworkInventoryService $inventory,
        protected PlumberDistributionPosService $pos,
    ) {}

    public function stock(Request $request): JsonResponse
    {
        $stock = $this->inventory->stockForRetailTrader($request->user());

        return $this->success($stock->values());
    }

    public function checkout(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'plumber_id' => ['required', 'exists:users,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        if ($v->fails()) {
            return $this->error('بيانات غير صالحة', 422, $v->errors());
        }

        $plumber = User::findOrFail($request->integer('plumber_id'));

        try {
            $distributions = $this->pos->issueToPlumber(
                $request->user(),
                $plumber,
                $request->input('lines'),
                $request->user(),
            );

            return $this->created([
                'distributions' => $distributions,
                'total_points' => collect($distributions)->sum(fn ($d) => $d->total_points),
            ], 'تم توزيع النقاط للسبّاك');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
