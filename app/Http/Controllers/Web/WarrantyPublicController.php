<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Warranty;
use App\Services\Warranty\WarrantyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WarrantyPublicController extends Controller
{
    public function show(Request $request, string $code): View|JsonResponse
    {
        $warranty = Warranty::query()->where('code', $code)->with(['plumber', 'items.product'])->firstOrFail();
        $payload = [
            'code' => $warranty->code,
            'status' => $warranty->status,
            'issued_at' => $warranty->issued_at?->toDateString(),
            'expires_at' => $warranty->expires_at?->toDateString(),
            'plumber' => [
                'name' => $warranty->plumber?->name,
                'level' => \App\Models\PlumberScore::query()->where('user_id', $warranty->plumber_id)->value('level'),
            ],
            'customer_phone_masked' => $warranty->maskedPhone(),
            'items' => $warranty->items->map(fn ($i) => [
                'name' => $i->product ? localized_name($i->product, 'name', 'منتج') : 'منتج',
                'quantity' => $i->quantity,
                'warranty_months' => $i->warranty_months,
            ]),
        ];

        if ($request->expectsJson() || str_contains((string) $request->header('Accept'), 'application/json')) {
            return response()->json(['status' => true, 'message' => 'ضمان', 'data' => $payload]);
        }

        return view('web.warranty-verify', ['warranty' => $warranty, 'data' => $payload]);
    }

    public function claim(Request $request, string $code, WarrantyService $service): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $warranty = Warranty::query()->where('code', $code)->firstOrFail();
        $data = $request->validate([
            'phone_last4' => 'required|string|size:4',
            'note' => 'required|string|max:1000',
        ]);
        $claim = $service->fileClaim($warranty, $data['phone_last4'], $data['note']);

        if ($request->expectsJson() || str_contains((string) $request->header('Accept'), 'application/json')) {
            return response()->json([
                'status' => true,
                'message' => 'تم استلام المطالبة',
                'data' => ['id' => $claim->id, 'status' => $claim->status],
            ], 201);
        }

        return redirect()->route('warranty.verify', $code)->with('status', 'تم استلام المطالبة');
    }
}
