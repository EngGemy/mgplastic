<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\HandlesInvoiceReturnApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceReturnResource;
use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceReturn;
use App\Services\InvoiceReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retail trader returns:
 *  - tier 3: return plumber sales (distributions they issued)
 *  - tier 2: return on their wholesale purchase invoices (as buyer party)
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
            ->whereIn('tier', [2, 3])
            ->where(function ($q) use ($user) {
                $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id);
            })
            ->with(['items.product.translations', 'fromUser:id,name,brand_name', 'toUser:id,name,brand_name'])
            ->latest()
            ->limit(100)
            ->get();

        return $this->success(InvoiceReturnResource::collection($returns), 'قائمة المرتجعات');
    }

    /** Tier-3 sales to plumbers — pick a distribution to return. */
    public function distributions(Request $request): JsonResponse
    {
        $user = $request->user();

        $rows = InvoiceDistribution::query()
            ->where('tier', 3)
            ->where('from_user_id', $user->id)
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->with([
                'toUser:id,name,brand_name,phone',
                'fromUser:id,name,brand_name,phone',
                'items.invoiceItem.product.translations',
            ])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (InvoiceDistribution $d) => $this->summarizeDistributionReturnRow($d, 'plumber', 'to'))
            ->values()
            ->all();

        return $this->success(['items' => $rows], 'قائمة توزيعات القطاعي→سباك');
    }

    /** Tier-2 purchases from wholesale — pick an invoice to return. */
    public function invoices(Request $request): JsonResponse
    {
        $user = $request->user();

        $invoices = Invoice::query()
            ->where('invoice_type', 'wholesale_pos')
            ->where('invoice_flow', 'outgoing')
            ->where(function ($q) use ($user) {
                $q->where('counterparty_user_id', $user->id)
                    ->orWhereHas('sourceDistribution', fn ($d) => $d
                        ->where('tier', 2)
                        ->where('to_user_id', $user->id));
            })
            ->with([
                'sourceDistribution.fromUser:id,name,brand_name,phone',
                'sourceDistribution.toUser:id,name,brand_name,phone',
                'sourceDistribution.items.invoiceItem.product.translations',
            ])
            ->latest()
            ->limit(100)
            ->get();

        $rows = $invoices
            ->map(fn (Invoice $inv) => $this->summarizeInvoiceReturnRow($inv, 'wholesale', 'from'))
            ->filter()
            ->values()
            ->all();

        return $this->success(['items' => $rows], 'قائمة فواتير الشراء من الجملة');
    }

    /** Preview / return a plumber distribution (tier 3). */
    public function previewDistribution(Request $request, InvoiceDistribution $distribution): JsonResponse
    {
        if (! $this->actorOwnsDistributionSide($distribution, $request->user())) {
            return $this->error('غير مصرّح بعرض مرتجع هذا التوزيع', 403);
        }

        if ((int) $distribution->tier !== 3) {
            return $this->error('هذا المسار لمرتجع القطاعي→سباك فقط', 422);
        }

        $distribution->loadMissing(['items.invoiceItem.product', 'invoice']);

        return $this->respondReturnPreview($distribution, $request->user());
    }

    public function storeDistribution(Request $request, InvoiceDistribution $distribution): JsonResponse
    {
        if (! $this->actorOwnsDistributionSide($distribution, $request->user())) {
            return $this->error('غير مصرّح بعمل مرتجع على هذا التوزيع', 403);
        }

        $payload = $this->validatedReturnPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        return $this->runReturn(
            fn () => $this->returns()->returnPlumberDistribution(
                $distribution,
                $payload['lines'],
                $request->user(),
                $payload['note'],
            ),
            'تم تسجيل مرتجع القطاعي→سباك بنجاح',
        );
    }

    /** Preview / return wholesale→retail purchase invoice (tier 2) as retail party. */
    public function previewInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        if (! $this->actorOwnsInvoice($invoice, $request->user())) {
            return $this->error('غير مصرّح بعرض مرتجع هذه الفاتورة', 403);
        }

        $invoice->loadMissing('sourceDistribution.items.invoiceItem.product');

        if (! $invoice->sourceDistribution || (int) $invoice->sourceDistribution->tier !== 2) {
            return $this->error('مرتجع الشراء من الجملة يتطلب فاتورة صادر جملة→قطاعي', 422);
        }

        return $this->respondReturnPreview($invoice->sourceDistribution, $request->user());
    }

    public function storeInvoice(Request $request, Invoice $invoice): JsonResponse
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
            'تم تسجيل مرتجع القطاعي←الجملة بنجاح',
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

        return $this->success(
            new InvoiceReturnResource($return->load(['items.product.translations', 'fromUser', 'toUser'])),
            'تفاصيل المرتجع',
        );
    }
}
