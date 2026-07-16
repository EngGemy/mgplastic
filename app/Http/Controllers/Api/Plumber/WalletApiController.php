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
        $rate = (float) $rule->points_per_currency_unit;
        $examplePoints = max((int) $rule->min_redeem_points, (int) $rate);
        $grossCents = $rate > 0 ? (int) floor(($examplePoints / $rate) * 100) : 0;
        $feeCents = (int) floor($grossCents * ((float) $rule->fee_percent / 100)) + (int) $rule->fee_fixed_cents;
        $netCents = max(0, $grossCents - $feeCents);

        return $this->success([
            'is_active' => $rule->is_active,
            'is_redemption_open' => $rule->isRedemptionOpen(),
            'currency' => $rule->currency ?? 'LYD',
            'points_per_currency_unit' => $rate,
            'min_redeem_points' => (int) $rule->min_redeem_points,
            'max_redeem_points' => $rule->max_redeem_points ? (int) $rule->max_redeem_points : null,
            'fee_percent' => (float) $rule->fee_percent,
            'fee_fixed_cents' => (int) $rule->fee_fixed_cents,
            'starts_at' => $rule->starts_at?->toIso8601String(),
            'ends_at' => $rule->ends_at?->toIso8601String(),
            // كيف تتحول النقاط لفلوس (مش سعر المنتج في الفاتورة)
            'how_it_works' => [
                'earn' => 'النقاط تُكتسب من الفاتورة: نقاط البند = الكمية × points_per_unit للمنتج',
                'convert' => 'التحويل لفلوس يتم بسعر صرف عام من الإدارة: LYD = points ÷ points_per_currency_unit ثم تُخصم الرسوم',
                'withdraw' => 'السحب من balance_cents بعد التحويل (مش مباشرة من النقاط)',
            ],
            'formula' => [
                'gross_cents' => 'floor((points / points_per_currency_unit) * 100)',
                'fee_cents' => 'floor(gross_cents * fee_percent / 100) + fee_fixed_cents',
                'net_cents' => 'max(0, gross_cents - fee_cents)',
                'note' => '100 cents = 1.00 د.ل',
            ],
            'example' => [
                'points' => $examplePoints,
                'gross_amount_cents' => $grossCents,
                'fee_cents' => $feeCents,
                'net_amount_cents' => $netCents,
                'net_amount_formatted' => number_format($netCents / 100, 2).' د.ل',
            ],
        ]);
    }
}
