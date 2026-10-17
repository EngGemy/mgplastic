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
        $scope = $request->string('scope')->toString();
        $status = $request->string('status')->toString();

        $returns = InvoiceReturn::query()
            ->where('tier', 2)
            ->when($scope === 'incoming' || $scope === 'needs_action', fn ($q) => $q
                ->where('to_user_id', $user->id)
                ->when($scope === 'needs_action', fn ($qq) => $qq->where('status', 'pending')))
            ->when($scope === 'outgoing' || $scope === 'placed', fn ($q) => $q->where('from_user_id', $user->id))
            ->when(! in_array($scope, ['incoming', 'needs_action', 'outgoing', 'placed'], true), fn ($q) => $q
                ->where(function ($qq) use ($user) {
                    $qq->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id);
                }))
            ->when($status !== '', fn ($q) => $q->where('status', $status === 'approved' ? 'approved' : $status))
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

    /** Outgoing wholesale→retail invoices — pick one to return. */
    public function invoices(Request $request): JsonResponse
    {
        $user = $request->user();

        $invoices = Invoice::query()
            ->where('invoice_type', 'wholesale_pos')
            ->where('invoice_flow', 'outgoing')
            ->where('wholesale_distributor_id', $user->id)
            ->with([
                'sourceDistribution.fromUser:id,name,brand_name,phone',
                'sourceDistribution.toUser:id,name,brand_name,phone',
                'sourceDistribution.items.invoiceItem.product.translations',
                'counterparty:id,name,brand_name,phone',
            ])
            ->latest()
            ->limit(100)
            ->get();

        $rows = $invoices
            ->map(fn (Invoice $inv) => $this->summarizeInvoiceReturnRow($inv, 'retail_trader', 'to'))
            ->filter()
            ->values()
            ->all();

        return $this->success(['items' => $rows], 'قائمة فواتير الصادر للجملة→قطاعي');
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
            'تم إرسال المرتجع وبانتظار موافقة الطرف المستلم',
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
