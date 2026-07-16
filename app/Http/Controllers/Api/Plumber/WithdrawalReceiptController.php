<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WithdrawalReceiptController extends Controller
{
    public function show(Request $request, WithdrawalRequest $withdrawal): Response
    {
        $this->authorizeOwner($request, $withdrawal);

        if ($withdrawal->status !== 'paid') {
            abort(response()->json([
                'status' => false,
                'message' => 'الإيصال متاح بعد تأكيد التحويل فقط',
            ], 422));
        }

        $withdrawal->loadMissing(['plumber', 'wallet']);

        return response()->view('withdrawals.receipt', [
            'withdrawal' => $withdrawal,
            'mode' => 'view',
        ]);
    }

    public function download(Request $request, WithdrawalRequest $withdrawal): StreamedResponse
    {
        $this->authorizeOwner($request, $withdrawal);

        if ($withdrawal->status !== 'paid') {
            abort(response()->json([
                'status' => false,
                'message' => 'الإيصال متاح بعد تأكيد التحويل فقط',
            ], 422));
        }

        $withdrawal->loadMissing(['plumber', 'wallet']);

        $html = view('withdrawals.receipt', [
            'withdrawal' => $withdrawal,
            'mode' => 'download',
        ])->render();

        $filename = 'withdrawal-receipt-'.$withdrawal->id.'.html';

        return response()->streamDownload(
            fn () => print($html),
            $filename,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }

    protected function authorizeOwner(Request $request, WithdrawalRequest $withdrawal): void
    {
        $user = $request->user();

        if (! $user || (int) $withdrawal->plumber_id !== (int) $user->id) {
            abort(response()->json([
                'status' => false,
                'message' => 'غير مصرح',
            ], 403));
        }
    }
}
