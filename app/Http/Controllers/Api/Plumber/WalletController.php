<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Controller;
use App\Models\ConversionRule;
use App\Models\PointsEntry;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $wallet = $user->wallet('LYD')->load(['transactions' => function ($q) {
            $q->latest()->limit(50);
        }]);

        return response()->json([
            'status' => 200,
            'data' => [
                'balance_points' => (int) $wallet->balance_points,
                'balance_cents' => (int) $wallet->balance_cents,
                'currency' => $wallet->currency,
                'recent_transactions' => $wallet->transactions,
            ],
        ]);
    }

    public function convert(Request $request)
    {
        $v = Validator::make($request->all(), [
            'points' => 'required|integer|min:1',
            'vendor_store_id' => 'nullable|exists:plumber_stores,id',
            'currency' => 'nullable|string|size:3',
        ]);

        if ($v->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'بيانات غير صالحة',
                'errors' => $v->errors(),
            ], 422);
        }

        $user = $request->user();
        $points = (int) $request->input('points');
        $currency = $request->input('currency', 'LYD');

        $rule = ConversionRule::globalSettings();
        $minPoints = (int) $rule->min_redeem_points;
        $maxPoints = $rule->max_redeem_points ? (int) $rule->max_redeem_points : null;
        $rate = (float) $rule->points_per_currency_unit;

        if (! $rule->isRedemptionOpen()) {
            return response()->json([
                'status' => false,
                'message' => 'صرف النقاط غير متاح حالياً — خارج فترة السماح',
                'data' => [
                    'is_redemption_open' => false,
                    'starts_at' => $rule->starts_at?->toIso8601String(),
                    'ends_at' => $rule->ends_at?->toIso8601String(),
                    'min_redeem_points' => $minPoints,
                    'max_redeem_points' => $maxPoints,
                ],
            ], 400);
        }

        if ($rate <= 0) {
            return response()->json([
                'status' => false,
                'message' => 'إعدادات التحويل غير مكتملة — تواصل مع الإدارة',
            ], 400);
        }

        $wallet = $user->wallet($currency);
        $balance = (int) $wallet->balance_points;

        if ($balance < $points) {
            return response()->json([
                'status' => false,
                'message' => "رصيدك غير كافٍ. رصيدك الحالي {$balance} نقطة وطلبت تحويل {$points}",
                'data' => [
                    'balance_points' => $balance,
                    'requested_points' => $points,
                    'min_redeem_points' => $minPoints,
                    'max_redeem_points' => $maxPoints,
                ],
            ], 400);
        }

        // اسمح بتحويل الرصيد بالكامل حتى لو أقل من الحد الأدنى
        $isFullBalance = $points === $balance;

        if ($points < $minPoints && ! $isFullBalance) {
            return response()->json([
                'status' => false,
                'message' => "الحد الأدنى للتحويل هو {$minPoints} نقطة. رصيدك {$balance} نقطة — يمكنك تحويل الرصيد بالكامل.",
                'data' => [
                    'balance_points' => $balance,
                    'requested_points' => $points,
                    'min_redeem_points' => $minPoints,
                    'max_redeem_points' => $maxPoints,
                    'can_convert_full_balance' => $balance > 0,
                    'hint' => $balance > 0
                        ? "أرسل points={$balance} لتحويل كامل الرصيد"
                        : 'لا يوجد رصيد نقاط للتحويل',
                ],
            ], 400);
        }

        if ($maxPoints && $points > $maxPoints) {
            return response()->json([
                'status' => false,
                'message' => "الحد الأقصى للتحويل هو {$maxPoints} نقطة",
                'data' => [
                    'balance_points' => $balance,
                    'requested_points' => $points,
                    'min_redeem_points' => $minPoints,
                    'max_redeem_points' => $maxPoints,
                ],
            ], 400);
        }

        $result = DB::transaction(function () use ($wallet, $user, $rule, $points, $rate) {
            $locked = $wallet->newQuery()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->balance_points < $points) {
                throw new \DomainException('رصيد النقاط تغيّر — أعد المحاولة');
            }

            $amountCents = (int) floor(($points / $rate) * 100);
            $feeCents = (int) floor($amountCents * ((float) $rule->fee_percent / 100)) + (int) $rule->fee_fixed_cents;
            $netCents = max(0, $amountCents - $feeCents);

            $locked->decrement('balance_points', $points);
            $locked->increment('balance_cents', $netCents);

            if (class_exists(PointsEntry::class) && Schema::hasTable('points_entries')) {
                PointsEntry::create([
                    'plumber_id' => $user->id,
                    'points_delta' => -$points,
                    'source_type' => WalletTransaction::class,
                    'source_id' => null,
                    'meta' => ['reason' => 'conversion', 'rule_id' => $rule->id],
                ]);
            }

            $locked->transactions()->create([
                'type' => 'conversion',
                'amount_cents' => $netCents,
                'points_delta' => -$points,
                'description' => 'تحويل نقاط إلى رصيد مالي',
                'meta' => [
                    'reason' => 'conversion',
                    'gross_amount_cents' => $amountCents,
                    'fee_cents' => $feeCents,
                    'rule_id' => $rule->id,
                    'points_per_currency_unit' => $rate,
                ],
                'related_type' => ConversionRule::class,
                'related_id' => $rule->id,
                'created_by' => $user->id,
            ]);

            return [
                'points_converted' => $points,
                'gross_amount_cents' => $amountCents,
                'fee_cents' => $feeCents,
                'net_amount_cents' => $netCents,
                'net_amount_formatted' => number_format($netCents / 100, 2).' د.ل',
                'balance_points' => (int) $locked->fresh()->balance_points,
                'balance_cents' => (int) $locked->fresh()->balance_cents,
            ];
        });

        if ($rule->notify_on_conversion && filled($rule->notification_message_ar)) {
            \Filament\Notifications\Notification::make()
                ->title('تحويل نقاط')
                ->body($rule->notification_message_ar)
                ->success()
                ->sendToDatabase($user);
        }

        return response()->json([
            'status' => true,
            'message' => 'تم تحويل النقاط بنجاح',
            'data' => $result,
        ]);
    }

    public function requestWithdrawal(Request $request)
    {
        $v = Validator::make($request->all(), [
            'amount_cents' => 'required|integer|min:100',
            'method' => 'required|in:bank_transfer,mobile_wallet',
            'details' => 'array|required',
        ]);

        if ($v->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'بيانات غير صالحة',
                'errors' => $v->errors(),
            ], 422);
        }

        $user = $request->user();
        $wallet = $user->wallet('LYD');
        $amount = (int) $request->input('amount_cents');

        if ($wallet->balance_cents < $amount) {
            return response()->json([
                'status' => false,
                'message' => 'الرصيد المالي غير كافٍ',
                'data' => [
                    'balance_cents' => (int) $wallet->balance_cents,
                    'requested_cents' => $amount,
                ],
            ], 400);
        }

        try {
            $withdrawal = DB::transaction(function () use ($wallet, $user, $amount, $request) {
                $locked = $wallet->newQuery()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();

                if ((int) $locked->balance_cents < $amount) {
                    throw new \DomainException('الرصيد تغيّر — أعد المحاولة');
                }

                $locked->decrement('balance_cents', $amount);

                $payload = [
                    'plumber_id' => $user->id,
                    'amount_cents' => $amount,
                    'status' => 'pending',
                    'method' => $request->input('method'),
                    'details' => $request->input('details'),
                ];

                if (Schema::hasColumn('withdrawal_requests', 'wallet_account_id')) {
                    $payload['wallet_account_id'] = $locked->id;
                }

                $withdrawal = \App\Models\WithdrawalRequest::create($payload);

                $locked->transactions()->create([
                    'type' => 'withdrawal',
                    'amount_cents' => -$amount,
                    'points_delta' => 0,
                    'description' => 'حجز رصيد لطلب سحب #'.$withdrawal->id,
                    'meta' => [
                        'withdrawal_id' => $withdrawal->id,
                        'method' => $request->input('method'),
                        'hold' => true,
                    ],
                    'related_type' => \App\Models\WithdrawalRequest::class,
                    'related_id' => $withdrawal->id,
                    'created_by' => $user->id,
                ]);

                return $withdrawal;
            });
        } catch (\DomainException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'status' => true,
            'message' => 'تم إنشاء طلب السحب',
            'data' => $withdrawal,
        ], 201);
    }
}
