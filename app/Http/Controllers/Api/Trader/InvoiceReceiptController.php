<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Services\DistributionService;
use App\Services\Points\PointsRateHintBuilder;
use App\Support\PointsVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retail trader confirms receipt of wholesale POS / outgoing invoices.
 * Seller creating the invoice is not enough — buyer must receive.
 */
class InvoiceReceiptController extends Controller
{
    use ApiResponds;

    public function __construct(
        protected DistributionService $distributions,
    ) {}

    /** GET /api/v1/mobile/trader/pending-receipts */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        $invoices = Invoice::query()
            ->where('invoice_type', 'wholesale_pos')
            ->where('invoice_flow', 'outgoing')
            ->where('counterparty_user_id', $user->id)
            ->where('status', 'pending_review')
            ->whereHas('sourceDistribution', fn ($q) => $q
                ->where('tier', 2)
                ->where('to_user_id', $user->id)
                ->where('status', 'draft'))
            ->with([
                'sourceDistribution.fromUser:id,name,brand_name,phone,network_code',
                'sourceDistribution.items.invoiceItem.product.translations',
            ])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Invoice $inv) => $this->mapPendingInvoice($inv))
            ->values();

        return $this->success([
            'items' => $invoices,
            'pending_count' => $invoices->count(),
        ], 'فواتير بانتظار تأكيد استلامك من الجملة');
    }

    /** POST /api/v1/mobile/trader/invoices/{invoice}/receive */
    public function receiveInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        try {
            $result = $this->distributions->receiveOutgoingInvoiceAsBuyer($request->user(), $invoice);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'invoice' => $this->mapPendingInvoice($result['invoice']->load([
                'sourceDistribution.fromUser:id,name,brand_name,phone,network_code',
                'sourceDistribution.items.invoiceItem.product.translations',
            ])),
            'distribution' => [
                'id' => $result['distribution']->id,
                'status' => $result['distribution']->status,
            ],
            'pending_receipt' => false,
        ], 'تم تأكيد الاستلام — الكميات دخلت مخزونك');
    }

    /** POST /api/v1/mobile/trader/distributions/{distribution}/receive */
    public function receiveDistribution(Request $request, InvoiceDistribution $distribution): JsonResponse
    {
        if ((int) $distribution->tier !== 2) {
            return $this->error('هذا المسار لتأكيد استلام فواتير الجملة فقط', 422);
        }

        try {
            $result = $this->distributions->receiveAsBuyer($request->user(), $distribution);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $outgoing = Invoice::query()
            ->where('source_distribution_id', $result['distribution']->id)
            ->first();

        return $this->success([
            'distribution' => [
                'id' => $result['distribution']->id,
                'status' => $result['distribution']->status,
            ],
            'invoice' => $outgoing ? $this->mapPendingInvoice($outgoing->load([
                'sourceDistribution.fromUser:id,name,brand_name,phone,network_code',
            ])) : null,
            'pending_receipt' => false,
        ], 'تم تأكيد الاستلام — الكميات دخلت مخزونك');
    }

    /** @return array<string, mixed> */
    protected function mapPendingInvoice(Invoice $inv): array
    {
        $dist = $inv->sourceDistribution;
        $hintBuilder = app(PointsRateHintBuilder::class);
        $hideRates = PointsVisibility::hidePerUnit();
        $items = $dist?->items?->map(function ($di) use ($hintBuilder, $hideRates) {
            $product = $di->invoiceItem?->product;
            $hint = $di->invoiceItem
                ? $hintBuilder->forMobile($hintBuilder->forInvoiceItem($di->invoiceItem, includeRates: true), $hideRates)
                : null;

            return [
                'product_id' => (int) ($di->invoiceItem?->product_id ?? 0),
                'name' => localized_name($product, 'name', 'منتج'),
                'quantity' => (int) $di->quantity,
                'points_value' => null,
                'points_rate_changed' => $hint !== null,
                'points_rate_hint' => $hint,
            ];
        })->values()->all() ?? [];

        return [
            'id' => (int) $inv->id,
            'number' => $inv->number,
            'status' => $inv->status,
            'pending_receipt' => $inv->status === 'pending_review'
                && $dist?->status === 'draft',
            'distribution_id' => $dist?->id,
            'distribution_status' => $dist?->status,
            'wholesaler' => $dist?->fromUser ? [
                'id' => $dist->fromUser->id,
                'name' => $dist->fromUser->name,
                'brand_name' => $dist->fromUser->brand_name,
                'phone' => $dist->fromUser->phone,
                'network_code' => $dist->fromUser->network_code,
            ] : null,
            'items' => $items,
            'items_count' => count($items),
            'total_quantity' => (int) collect($items)->sum('quantity'),
            'created_at' => $inv->created_at?->toIso8601String(),
            'available_actions' => ($inv->status === 'pending_review' && $dist?->status === 'draft')
                ? ['receive']
                : [],
        ];
    }
}
