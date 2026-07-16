<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\WalletResource;
use App\Http\Resources\Mobile\WalletTransactionResource;
use App\Http\Resources\Mobile\WithdrawalResource;
use App\Models\ConversionRule;
use App\Models\WithdrawalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletApiController extends Controller
{
    use ApiResponds;

    public function show(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet('LYD');
        $wallet->load(['transactions' => fn ($q) => $q->latest()->limit(30)]);

        return $this->success((new WalletResource($wallet))->resolve());
    }

    public function transactions(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet('LYD');

        $query = $wallet->transactions()->latest();

        // ?points_only=1 → حركات النقاط فقط
        if ($request->boolean('points_only')) {
            $query->where('points_delta', '!=', 0);
        }

        $transactions = $query->paginate($request->integer('per_page', 20));

        return $this->success([
            'balance_points' => (int) $wallet->balance_points,
            'items' => WalletTransactionResource::collection($transactions->items())->resolve(),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    public function conversionRules(): JsonResponse
    {
        $rule = ConversionRule::globalSettings();

        return $this->success([
            'is_active' => $rule->is_active,
            'is_redemption_open' => $rule->isRedemptionOpen(),
            'points_per_currency_unit' => (float) $rule->points_per_currency_unit,
            'min_redeem_points' => (int) $rule->min_redeem_points,
            'max_redeem_points' => $rule->max_redeem_points ? (int) $rule->max_redeem_points : null,
            'fee_percent' => (float) $rule->fee_percent,
            'fee_fixed_cents' => (int) $rule->fee_fixed_cents,
        ]);
    }
}
