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
        $scope = $request->string('scope')->toString();
        $status = $request->string('status')->toString();

        $returns = InvoiceReturn::query()
            ->whereIn('tier', [2, 3])
            ->when($scope === 'incoming' || $scope === 'needs_action', fn ($q) => $q
                ->where('to_user_id', $user->id)
                ->when($scope === 'needs_action', fn ($qq) => $qq->where('status', 'pending')))
            ->when($scope === 'outgoing' || $scope === 'placed', fn ($q) => $q->where('from_user_id', $user->id))
            ->when(! in_array($scope, ['incoming', 'needs_action', 'outgoing', 'placed'], true), fn ($q) => $q
                ->where(function ($qq) use ($user) {
                    $qq->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id);
                }))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->with(['items.product.translations', 'fromUser:id,name,brand_name', 'toUser:id,name,brand_name'])
            ->latest()
            ->limit(100)
            ->get();

        return $this->success(InvoiceReturnResource::collection($returns), 'قائمة المرتجعات');
    }

    public function approve(Request $request, InvoiceReturn $return): JsonResponse
    {
        if ($denied = $this->assertCanViewReturn($return, $request->user())) {
            return $denied;
        }

        return $this->runReturnDecision(
            fn () => $this->returns()->approve($return, $request->user(), $request->input('note')),
            'تم قبول المرتجع',
        );
    }

    public function reject(Request $request, InvoiceReturn $return): JsonResponse
    {
        if ($denied = $this->assertCanViewReturn($return, $request->user())) {
            return $denied;
        }

        return $this->runReturnDecision(
            fn () => $this->returns()->reject($return, $request->user(), $request->input('reason') ?? $request->input('note')),
            'تم رفض المرتجع',
        );
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
            ->map(fn (InvoiceDistribution $d) => $this->summarizeDistributionReturnRow($d, 'plumber', 'to', $user))
            ->values()
            ->all();

        // قطاعي: قائمة للمراجعة/القبول — طلب المرتجع من السباك فقط (can_return=false للقطاعي على tier 3)
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
            ->map(function (Invoice $inv) use ($user) {
                $row = $this->summarizeInvoiceReturnRow($inv, 'wholesale', 'from', $user);
                if (! $row) {
                    return null;
                }
                $row['bucket'] = 'purchases';
                $row['bucket_label'] = 'مشتريات — جملة';

                return $row;
            })
            ->filter()
            ->values()
            ->all();

        return $this->success([
            'section' => 'فواتيري',
            'purchases' => $rows,
            'items' => $rows,
        ], 'فواتيري — فواتير الشراء من الجملة');
    }

    /** Preview a plumber distribution (tier 3) — retail may view, but cannot request. */
    public function previewDistribution(Request $request, InvoiceDistribution $distribution): JsonResponse
    {
        if (! $this->actorOwnsDistributionSide($distribution, $request->user())) {
            return $this->error('غير مصرّح بعرض مرتجع هذا التوزيع', 403);
        }

        if ((int) $distribution->tier !== 3) {
            return $this->error('هذا المسار لمرتجع القطاعي→سباك فقط', 422);
        }

        $distribution->loadMissing(['items.invoiceItem.product', 'invoice']);

        // Always pass actor so can_return stays false for retail (upper party).
        return $this->respondReturnPreview($distribution, $request->user());
    }

    public function storeDistribution(Request $request, InvoiceDistribution $distribution): JsonResponse
    {
        // Tier 3: only the plumber (lower party) may request — retail must wait then approve.
        return $this->error(
            'لا يمكن لتاجر القطاعي إنشاء مرتجع على مبيعات السباك — الطلب من السباك فقط ثم تقبله من قائمة المرتجعات الواردة',
            403,
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
        $user = $request->user();

        if (! $this->actorOwnsInvoice($invoice, $user)) {
            return $this->error('غير مصرّح بعمل مرتجع على هذه الفاتورة', 403);
        }

        $invoice->loadMissing('sourceDistribution');
        $distribution = $invoice->sourceDistribution;
        if (! $distribution || (int) $distribution->tier !== 2) {
            return $this->error('مرتجع الشراء من الجملة يتطلب فاتورة صادر جملة→قطاعي', 422);
        }

        // Retail must be the buyer (to_user) on the tier-2 distribution.
        if (! $this->returns()->actorCanRequestReturn($distribution, $user)) {
            return $this->error(
                'طلب مرتجع الشراء من الجملة متاح لتاجر القطاعي المستلم فقط',
                403,
            );
        }

        $payload = $this->validatedReturnPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        return $this->runReturn(
            fn () => $this->returns()->returnOutgoingInvoice(
                $invoice,
                $payload['lines'],
                $user,
                $payload['note'],
            ),
            'تم إرسال المرتجع وبانتظار موافقة موزّع الجملة',
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
