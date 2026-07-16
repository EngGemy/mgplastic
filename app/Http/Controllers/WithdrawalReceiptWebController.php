<?php

namespace App\Http\Controllers;

use App\Models\WithdrawalRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

class WithdrawalReceiptWebController extends Controller
{
    public function show(WithdrawalRequest $withdrawal): Response
    {
        if ($withdrawal->status !== 'paid') {
            abort(404, 'الإيصال غير متاح');
        }

        $withdrawal->loadMissing(['plumber', 'wallet']);

        return response()->view('withdrawals.receipt', [
            'withdrawal' => $withdrawal,
            'mode' => 'view',
        ]);
    }

    public static function signedUrl(WithdrawalRequest $withdrawal, int $days = 60): ?string
    {
        if ($withdrawal->status !== 'paid') {
            return null;
        }

        return URL::temporarySignedRoute(
            'withdrawals.receipt',
            now()->addDays($days),
            ['withdrawal' => $withdrawal->id],
        );
    }
}
