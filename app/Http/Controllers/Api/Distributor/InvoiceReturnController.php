<?php

namespace App\Http\Controllers\Api\Distributor;

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
 * Wholesale distributor:
 *  - فواتيري: sales (outgoing) + purchases (incoming from factory/warehouse)
 *  - approve retail→wholesale returns; request returns on own purchases
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
            ->whereIn('tier', [1, 2])
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

    /**
     * فواتيري — مبيعات (صادر للقطاعي) + مشتريات (وارد من المصنع/المخزن).
     * ?scope=sales|purchases|all (default all)
     */
    public function invoices(Request $request): JsonResponse
    {
        $user = $request->user();
        $scope = $request->string('scope')->toString() ?: 'all';

        $sales = [];
        $purchases = [];

        if (in_array($scope, ['sales', 'all', ''], true)) {
            $sales = Invoice::query()
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
                ->get()
                ->map(function (Invoice $inv) use ($user) {
                    $row = $this->summarizeInvoiceReturnRow($inv, 'retail_trader', 'to', $user);
                    if (! $row) {
                        return null;
                    }
                    $row['bucket'] = 'sales';
                    $row['bucket_label'] = 'مبيعات — قطاعي';
                    // جملة لا يطلب مرتجع على مبيعاته؛ ينتظر طلب القطاعي
                    return $row;
                })
                ->filter()
                ->values()
                ->all();
        }

        if (in_array($scope, ['purchases', 'all', ''], true)) {
            $purchases = Invoice::query()
                ->where('invoice_type', 'wholesale_pos')
                ->where('invoice_flow', 'incoming')
                ->where('wholesale_distributor_id', $user->id)
                ->where('status', 'approved')
                ->with([
                    'distributions' => fn ($q) => $q
                        ->where('tier', 1)
                        ->where('to_user_id', $user->id)
                        ->whereIn('status', ['confirmed', 'points_awarded'])
                        ->with([
                            'fromUser:id,name,brand_name,phone',
                            'toUser:id,name,brand_name,phone',
                            'items.invoiceItem.product.translations',
                        ]),
                    'items.product.translations',
                ])
                ->latest()
                ->limit(100)
                ->get()
                ->map(function (Invoice $inv) use ($user) {
                    $dist = $inv->distributions->first();
                    if (! $dist) {
                        return [
                            'id' => (int) $inv->id,
                            'invoice_id' => (int) $inv->id,
                            'invoice_number' => $inv->number,
                            'bucket' => 'purchases',
                            'bucket_label' => 'مشتريات — مصنع/مخزن',
                            'source' => 'factory_or_warehouse',
                            'can_return' => false,
                            'block_reason' => 'لا يوجد توزيع مؤكّد مرتبط بهذه الفاتورة بعد',
                            'return_via' => 'distribution',
                        ];
                    }

                    $row = $this->summarizeDistributionReturnRow($dist, 'supplier', 'from', $user);
                    $row['id'] = (int) $inv->id;
                    $row['invoice_id'] = (int) $inv->id;
                    $row['invoice_number'] = $inv->number;
                    $row['distribution_id'] = (int) $dist->id;
                    $row['bucket'] = 'purchases';
                    $row['bucket_label'] = 'مشتريات — مصنع/مخزن';
                    $row['source'] = $dist->fromUser?->isCentralWarehouse() ? 'warehouse' : 'factory';
                    $row['return_via'] = 'distribution'; // POST …/distributions/{distribution}/returns

                    return $row;
                })
                ->values()
                ->all();
        }

        return $this->success([
            'section' => 'فواتيري',
            'scope' => $scope === '' ? 'all' : $scope,
            'sales' => $sales,
            'purchases' => $purchases,
            'items' => array_values(array_merge($purchases, $sales)),
        ], 'فواتيري');
    }

    public function preview(Request $request, Invoice $invoice): JsonResponse
    {
        if (! $this->actorOwnsInvoice($invoice, $request->user())) {
            return $this->error('غير مصرّح بعرض مرتجع هذه الفاتورة', 403);
        }

        $user = $request->user();
        $invoice->loadMissing([
            'sourceDistribution.items.invoiceItem.product',
            'distributions' => fn ($q) => $q
                ->where('to_user_id', $user->id)
                ->whereIn('status', ['confirmed', 'points_awarded'])
                ->with('items.invoiceItem.product'),
        ]);

        // Purchases (incoming): return via tier-1 distribution to factory/warehouse.
        if ($invoice->isIncoming()) {
            $dist = $invoice->distributions->firstWhere('tier', 1)
                ?? $invoice->distributions->first();
            if (! $dist) {
                return $this->error('لا يوجد توزيع مؤكّد لهذه الفاتورة الواردة', 422);
            }

            return $this->respondReturnPreview($dist, $user);
        }

        if (! $invoice->sourceDistribution) {
            return $this->error('لا يوجد توزيع مرتبط بهذه الفاتورة', 422);
        }

        if ((int) $invoice->sourceDistribution->tier !== 2) {
            return $this->error('مرتجع المبيعات متاح على فواتير جملة→قطاعي فقط', 422);
        }

        return $this->respondReturnPreview($invoice->sourceDistribution, $user);
    }

    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $user = $request->user();

        if (! $this->actorOwnsInvoice($invoice, $user)) {
            return $this->error('غير مصرّح بعمل مرتجع على هذه الفاتورة', 403);
        }

        $payload = $this->validatedReturnPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        // Purchases: wholesaler requests return to factory/warehouse.
        if ($invoice->isIncoming()) {
            $invoice->loadMissing([
                'distributions' => fn ($q) => $q
                    ->where('to_user_id', $user->id)
                    ->whereIn('status', ['confirmed', 'points_awarded']),
            ]);
            $dist = $invoice->distributions->firstWhere('tier', 1)
                ?? $invoice->distributions->first();
            if (! $dist) {
                return $this->error('لا يوجد توزيع مؤكّد لهذه الفاتورة الواردة', 422);
            }

            return $this->runReturn(
                fn () => $this->returns()->returnDistribution(
                    $dist,
                    $payload['lines'],
                    $user,
                    $payload['note'],
                    $invoice,
                ),
                'تم إرسال مرتجع المشتريات وبانتظار موافقة المصنع/المخزن',
            );
        }

        // Sales: wholesale must NOT open return for retail.
        return $this->error(
            'لا يمكن لتاجر الجملة إنشاء مرتجع على فاتورة القطاعي — الطلب من تاجر القطاعي فقط ثم تقبله من قائمة المرتجعات الواردة',
            403,
        );
    }

    /** Preview / request return on a purchase distribution (factory or warehouse supply). */
    public function previewDistribution(Request $request, InvoiceDistribution $distribution): JsonResponse
    {
        $user = $request->user();
        if ((int) $distribution->to_user_id !== (int) $user->id
            && ! in_array($user->role, ['super_admin', 'admin'], true)) {
            return $this->error('غير مصرّح بعرض مرتجع هذا التوزيع', 403);
        }

        $distribution->loadMissing(['items.invoiceItem.product', 'fromUser', 'toUser']);

        return $this->respondReturnPreview($distribution, $user);
    }

    public function storeDistribution(Request $request, InvoiceDistribution $distribution): JsonResponse
    {
        $user = $request->user();
        if ((int) $distribution->to_user_id !== (int) $user->id
            && ! in_array($user->role, ['super_admin', 'admin'], true)) {
            return $this->error('غير مصرّح بعمل مرتجع على هذا التوزيع', 403);
        }

        $payload = $this->validatedReturnPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        return $this->runReturn(
            fn () => $this->returns()->returnDistribution(
                $distribution,
                $payload['lines'],
                $user,
                $payload['note'],
            ),
            'تم إرسال مرتجع المشتريات وبانتظار موافقة المصنع/المخزن',
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
