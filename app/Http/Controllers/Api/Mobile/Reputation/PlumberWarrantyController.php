<?php

namespace App\Http\Controllers\Api\Mobile\Reputation;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Warranty;
use App\Services\Warranty\WarrantyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlumberWarrantyController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $q = Warranty::query()->where('plumber_id', $request->user()->id)->with('items.product')->latest();
        if ($status) {
            $q->where('status', $status);
        }
        $items = $q->limit(50)->get()->map(fn (Warranty $w) => $this->serialize($w));

        return $this->success(['items' => $items], 'الضمانات');
    }

    public function show(Request $request, Warranty $warranty): JsonResponse
    {
        if ((int) $warranty->plumber_id !== (int) $request->user()->id) {
            return $this->error('غير مصرح', 403);
        }

        return $this->success($this->serialize($warranty->load('items.product')), 'تفاصيل الضمان');
    }

    public function eligible(Request $request, WarrantyService $service): JsonResponse
    {
        return $this->success(['items' => $service->eligibleItems($request->user())], 'منتجات قابلة للضمان');
    }

    public function store(Request $request, WarrantyService $service): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:120',
            'customer_phone' => 'required|string|max:32',
            'work_description' => 'nullable|string|max:2000',
            'site_address' => 'nullable|string|max:255',
            'city_id' => 'nullable|integer|exists:cities,id',
            'customer_consent' => 'accepted',
            'items' => 'required|array|min:1',
            'items.*.distribution_id' => 'required|integer',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $warranty = $service->issue($request->user(), $data);

        return $this->created([
            'id' => $warranty->id,
            'code' => $warranty->code,
            'verify_url' => $warranty->verifyUrl(),
            'pdf_url' => $warranty->pdfUrl(),
            'card_url' => $warranty->cardUrl(),
            'whatsapp_status' => $warranty->whatsapp_sent_at ? 'sent' : 'pending',
        ], 'تم إصدار الضمان');
    }

    public function resend(Request $request, Warranty $warranty, WarrantyService $service): JsonResponse
    {
        if ((int) $warranty->plumber_id !== (int) $request->user()->id) {
            return $this->error('غير مصرح', 403);
        }
        $service->resend($warranty);

        return $this->success(['whatsapp_status' => 'sent'], 'تمت إعادة الإرسال');
    }

    public function pdf(Request $request, Warranty $warranty): StreamedResponse|JsonResponse
    {
        if ((int) $warranty->plumber_id !== (int) $request->user()->id) {
            return $this->error('غير مصرح', 403);
        }
        if (! $warranty->pdf_path || ! Storage::disk('public')->exists($warranty->pdf_path)) {
            return $this->error('الملف غير موجود', 404);
        }

        return Storage::disk('public')->download($warranty->pdf_path, $warranty->code.'.html');
    }

    protected function serialize(Warranty $w): array
    {
        return [
            'id' => $w->id,
            'code' => $w->code,
            'customer_name' => $w->customer_name,
            'customer_phone_masked' => $w->maskedPhone(),
            'status' => $w->status,
            'issued_at' => $w->issued_at?->toIso8601String(),
            'expires_at' => $w->expires_at?->toIso8601String(),
            'verify_url' => $w->verifyUrl(),
            'pdf_url' => $w->pdfUrl(),
            'items' => $w->relationLoaded('items') ? $w->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'name' => $i->product ? localized_name($i->product, 'name', 'منتج') : 'منتج',
                'quantity' => $i->quantity,
                'warranty_months' => $i->warranty_months,
            ])->values() : [],
        ];
    }
}
