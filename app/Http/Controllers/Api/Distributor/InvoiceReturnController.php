<?php

namespace App\Http\Controllers\Api\Distributor;

use App\Http\Controllers\Api\Concerns\HandlesInvoiceReturnApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceReturnResource;
use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Services\InvoiceReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wholesale distributor: return goods on outgoing wholesale→retail invoices (tier 2).
 */
class InvoiceReturnController extends Controller
{
    use HandlesInvoiceReturnApi;

    public function __construct(protected InvoiceReturnService $returnService) {}

    protected function returns(): InvoiceReturnService
    {
        return $this->returnService;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $returns = InvoiceReturn::query()
            ->where('tier', 2)
            ->where(function ($q) use ($user) {
                $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id);
            })
            ->with(['items.product.translations', 'fromUser:id,name,brand_name', 'toUser:id,name,brand_name'])
            ->latest()
            ->limit(100)
            ->get();

        return $this->success(InvoiceReturnResource::collection($returns));
    }

    public function preview(Request $request, Invoice $invoice): JsonResponse
    {
        if (! $this->actorOwnsInvoice($invoice, $request->user())) {
            return $this->error('غير مصرّح بعرض مرتجع هذه الفاتورة', 403);
        }

        $invoice->loadMissing('sourceDistribution.items.invoiceItem.product');

        if (! $invoice->sourceDistribution) {
            return $this->error('لا يوجد توزيع مرتبط بهذه الفاتورة', 422);
        }

        if ((int) $invoice->sourceDistribution->tier !== 2) {
            return $this->error('مرتجع الجملة متاح على فواتير جملة→قطاعي فقط', 422);
        }

        return $this->respondReturnPreview($invoice->sourceDistribution, $request->user());
    }

    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        if (! $this->actorOwnsInvoice($invoice, $request->user())) {
            return $this->error('غير مصرّح بعمل مرتجع على هذه الفاتورة', 403);
        }

        $payload = $this->validatedReturnPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        return $this->runReturn(
            fn () => $this->returns()->returnOutgoingInvoice(
                $invoice,
                $payload['lines'],
                $request->user(),
                $payload['note'],
            ),
            'تم تسجيل مرتجع الجملة→قطاعي بنجاح',
        );
    }

    public function show(Request $request, InvoiceReturn $return): JsonResponse
    {
        $user = $request->user();
        if ((int) $return->from_user_id !== (int) $user->id
            && (int) $return->to_user_id !== (int) $user->id
            && ! in_array($user->role, ['super_admin', 'admin'], true)) {
            return $this->error('غير مصرّح', 403);
        }

        return $this->success(new InvoiceReturnResource(
            $return->load(['items.product.translations', 'fromUser', 'toUser'])
        ));
    }
}
