<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Controller;
use App\Models\ConversionRule;
use App\Services\PointsConversionService;
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

        try {
            $result = app(PointsConversionService::class)
                ->convert($user, $points, $currency);
        } catch (\DomainException $e) {
            $rule = ConversionRule::globalSettings();
            $wallet = $user->wallet($currency);

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'data' => [
                    'balance_points' => (int) $wallet->balance_points,
                    'requested_points' => $points,
                    'min_redeem_points' => (int) $rule->min_redeem_points,
                    'max_redeem_points' => $rule->max_redeem_points ? (int) $rule->max_redeem_points : null,
                    'can_convert_full_balance' => (int) $wallet->balance_points > 0,
                    'hint' => (int) $wallet->balance_points > 0
                        ? 'أرسل points='.(int) $wallet->balance_points.' لتحويل كامل الرصيد'
                        : 'لا يوجد رصيد نقاط للتحويل',
                ],
            ], 400);
        }

        $rule = ConversionRule::globalSettings();
        if ($rule->notify_on_conversion && filled($rule->notification_message_ar)) {
            \Filament\Notifications\Notification::make()
                ->title('تحويل نقاط')
                ->body($rule->notification_message_ar)
                ->success()
                ->sendToDatabase($user);
        }

        return response()->json([
            'status' => true,
            'message' => 'تم تحويل النقاط بنجاح حسب تحويل كل منتج',
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

        $withdrawal->loadMissing('plumber');
        \App\Services\WithdrawalRequestService::notifySubmitted($withdrawal);

        return response()->json([
            'status' => true,
            'message' => 'تم إنشاء طلب السحب — سنُعلمك عند التحويل',
            'data' => (new \App\Http\Resources\Mobile\WithdrawalResource($withdrawal))->resolve(),
            'ux' => [
                'title' => 'طلبك قيد المراجعة',
                'body' => 'استلمنا طلب سحب '.$withdrawal->formattedAmount().'. ستصلك إشعار وإيصال عند إتمام التحويل.',
            ],
        ], 201);
    }
}
