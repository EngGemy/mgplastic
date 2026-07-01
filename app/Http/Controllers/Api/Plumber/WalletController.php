<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Controller;
use App\Models\ConversionRule;
use App\Models\PointsEntry;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    public function show(Request $request)
    {
        $wallet = $user->wallet('LYD')->load(['transactions' => function ($q) {
            $q->latest()->limit(50);
        }]);
        return response()->json(['status'=>200,'data'=>$wallet]);
    }

    public function convert(Request $request)
    {
        $v = Validator::make($request->all(), [
            'points' => 'required|integer|min:1',
            'vendor_store_id' => 'nullable|exists:plumber_stores,id',
            'currency' => 'nullable|string|size:3',
        ]);
        if ($v->fails()) return response()->json(['status'=>422,'errors'=>$v->errors()],422);

        $user = $request->user();
        $points = (int) $request->input('points');
        $currency = $request->input('currency', 'LYD');

        $rule = ConversionRule::globalSettings();

        if (! $rule->isRedemptionOpen()) {
            return response()->json([
                'status' => 400,
                'message' => 'صرف النقاط غير متاح حالياً — خارج فترة السماح',
            ], 400);
        }

        $wallet = $user->wallet($currency);
        if ($wallet->balance_points < $points) {
            return response()->json(['status'=>400,'message'=>'Insufficient points'],400);
        }

        if ($points < $rule->min_redeem_points) {
            return response()->json(['status'=>400,'message'=>'Below minimum'],400);
        }
        if ($rule->max_redeem_points && $points > $rule->max_redeem_points) {
            return response()->json(['status'=>400,'message'=>'Above maximum'],400);
        }

        $result = DB::transaction(function () use ($wallet, $user, $rule, $points) {
            $amountCents = (int) floor(($points / $rule->points_per_currency_unit) * 100);
            $feeCents = (int) floor($amountCents * ($rule->fee_percent / 100)) + (int) $rule->fee_fixed_cents;
            $netCents = max(0, $amountCents - $feeCents);

            $wallet->decrement('balance_points', $points);
            $wallet->increment('balance_cents', $netCents);

            PointsEntry::create([
                'plumber_id'  => $user->id,
                'points_delta'=> -$points,
                'source_type' => WalletTransaction::class,
                'source_id'   => null,
                'meta'        => ['reason'=>'conversion','rule_id'=>$rule->id],
            ]);

            $wallet->transactions()->create([
                'type'         => 'conversion',
                'amount_cents' => $netCents,
                'points_delta' => -$points,
                'description'  => 'Converted points to money',
                'meta'         => [
                    'gross_amount_cents'=>$amountCents,
                    'fee_cents'=>$feeCents,
                    'rule_id'=>$rule->id
                ],
                'related_type' => self::class, // just a tag
                'related_id'   => 0,
                'created_by'   => $user->id,
            ]);

            return compact('amountCents','feeCents','netCents');
        });

        if ($rule->notify_on_conversion && filled($rule->notification_message_ar)) {
            \Filament\Notifications\Notification::make()
                ->title('تحويل نقاط')
                ->body($rule->notification_message_ar)
                ->success()
                ->sendToDatabase($user);
        }

        return response()->json(['status'=>200,'message'=>'Converted successfully','data'=>$result]);
    }

    public function requestWithdrawal(Request $request)
    {
        $v = Validator::make($request->all(), [
            'amount_cents' => 'required|integer|min:100',
            'method'       => 'required|in:bank_transfer,mobile_wallet',
            'details'      => 'array|required',
        ]);
        if ($v->fails()) return response()->json(['status'=>422,'errors'=>$v->errors()],422);

        $user = $request->user();
        $wallet = $user->wallet('LYD');
        $amount = (int) $request->input('amount_cents');

        if ($wallet->balance_cents < $amount) {
            return response()->json(['status'=>400,'message'=>'Insufficient balance'],400);
        }

        $withdrawal = DB::transaction(function () use ($wallet, $user, $amount, $request) {
            $wallet->decrement('balance_cents', $amount);

            $withdrawal = \App\Models\WithdrawalRequest::create([
                'plumber_id' => $user->id,
                'wallet_account_id' => $wallet->id,
                'amount_cents' => $amount,
                'status' => 'pending',
                'method' => $request->input('method'),
                'details' => $request->input('details'),
            ]);

            $wallet->transactions()->create([
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

        return response()->json(['status'=>201,'message'=>'Withdrawal requested','data'=>$withdrawal],201);
    }
}
