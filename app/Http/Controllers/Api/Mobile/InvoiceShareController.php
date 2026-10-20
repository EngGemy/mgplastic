<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Services\InvoiceWhatsAppShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * مشاركة فاتورة / توزيع على واتساب (رابط موقّع + wa.me لصاحب الرقم).
 */
class InvoiceShareController extends Controller
{
    use ApiResponds;

    public function __construct(protected InvoiceWhatsAppShareService $share) {}

    public function showInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        try {
            return $this->success(
                $this->share->forInvoice($invoice, $request->user()),
                'بيانات مشاركة الفاتورة'
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 403);
        }
    }

    public function shareInvoiceWhatsApp(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'nullable|string|max:32',
            'send_alert' => 'sometimes|boolean',
        ]);

        try {
            $payload = $this->share->forInvoice(
                $invoice,
                $request->user(),
                $data['phone'] ?? null,
            );

            if (! empty($data['send_alert'])) {
                $this->share->resendInvoiceAlert($invoice, $request->user());
                $payload['alert_queued'] = true;
            } else {
                $payload['alert_queued'] = false;
            }

            if (! $payload['whatsapp_url']) {
                return $this->error('لا يوجد رقم هاتف للمشاركة — أرسل phone في الطلب', 422, [
                    'phone' => ['أدخل رقم واتساب المستلم'],
                ]);
            }

            return $this->success($payload, 'جاهز للمشاركة على واتساب — افتح whatsapp_url');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 403);
        }
    }

    public function showDistribution(Request $request, InvoiceDistribution $distribution): JsonResponse
    {
        try {
            return $this->success(
                $this->share->forDistribution($distribution, $request->user()),
                'بيانات مشاركة التوريد'
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 403);
        }
    }

    public function shareDistributionWhatsApp(Request $request, InvoiceDistribution $distribution): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'nullable|string|max:32',
        ]);

        try {
            $payload = $this->share->forDistribution(
                $distribution,
                $request->user(),
                $data['phone'] ?? null,
            );

            if (! $payload['whatsapp_url']) {
                return $this->error('لا يوجد رقم هاتف للمشاركة — أرسل phone في الطلب', 422, [
                    'phone' => ['أدخل رقم واتساب المستلم'],
                ]);
            }

            return $this->success($payload, 'جاهز للمشاركة على واتساب — افتح whatsapp_url');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 403);
        }
    }
}
