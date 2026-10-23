<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Plumber\ReceivedDistributionResource;
use App\Models\InvoiceDistribution;
use App\Models\WalletAccount;
use App\Services\DistributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plumber confirms receipt of retail POS / fulfill distributions.
 * Points are awarded only after this call.
 */
class DistributionReceiptController extends Controller
{
    use ApiResponds;

    public function __construct(
        protected DistributionService $distributions,
    ) {}

    /** GET /api/v1/mobile/plumber/pending-receipts */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = InvoiceDistribution::query()
            ->where('to_user_id', $user->id)
            ->where('tier', 3)
            ->where('status', 'draft')
            ->with([
                'invoice:id,number,status,attachment_path,approved_at',
                'fromUser:id,name,brand_name,phone,network_code,profile_photo',
                'items.invoiceItem.product.translations',
            ])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (InvoiceDistribution $d) => $this->mapPending($d))
            ->values();

        return $this->success([
            'items' => $items,
            'pending_count' => $items->count(),
        ], 'فواتير بانتظار تأكيد استلامك من القطاعي');
    }

    /** POST /api/v1/mobile/plumber/distributions/{distribution}/receive */
    public function receive(Request $request, InvoiceDistribution $distribution): JsonResponse
    {
        if ((int) $distribution->tier !== 3) {
            return $this->error('هذا المسار لتأكيد استلام توزيعات السباك فقط', 422);
        }

        try {
            $result = $this->distributions->receiveAsBuyer($request->user(), $distribution);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $dist = $result['distribution']->load([
            'invoice:id,number,status,attachment_path,approved_at',
            'fromUser:id,name,brand_name,phone,network_code,profile_photo',
            'items.invoiceItem.product.translations',
        ]);

        $balancePoints = (int) (WalletAccount::query()
            ->where('owner_id', $request->user()->id)
            ->where('currency', 'LYD')
            ->value('balance_points') ?? 0);

        return $this->success([
            'distribution' => (new ReceivedDistributionResource($dist))->resolve(),
            'points_earned' => (int) $dist->items->sum('points_value'),
            'balance_points' => $balancePoints,
            'plumber_balance_points' => $balancePoints,
            'new_badges' => $result['new_badges'] ?? [],
            'score_delta' => (int) ($result['score_delta'] ?? 0),
            'level_changed' => (bool) ($result['level_changed'] ?? false),
            'pending_receipt' => false,
        ], 'تم تأكيد الاستلام وإضافة النقاط لمحفظتك');
    }

    /** @return array<string, mixed> */
    protected function mapPending(InvoiceDistribution $d): array
    {
        $items = $d->items->map(function ($di) {
            $product = $di->invoiceItem?->product;

            return [
                'product_id' => (int) ($di->invoiceItem?->product_id ?? 0),
                'name' => localized_name($product, 'name', 'منتج'),
                'quantity' => (int) $di->quantity,
                'points_value' => null,
            ];
        })->values()->all();

        return [
            'id' => (int) $d->id,
            'status' => $d->status,
            'pending_receipt' => true,
            'tier' => (int) $d->tier,
            'invoice_id' => $d->invoice_id ? (int) $d->invoice_id : null,
            'invoice_number' => $d->invoice?->number,
            'trader' => $d->fromUser ? [
                'id' => $d->fromUser->id,
                'name' => $d->fromUser->name,
                'brand_name' => $d->fromUser->brand_name,
                'display_name' => $d->fromUser->brand_name ?: $d->fromUser->name,
                'phone' => $d->fromUser->phone,
                'network_code' => $d->fromUser->network_code,
            ] : null,
            'items' => $items,
            'items_count' => count($items),
            'total_quantity' => (int) collect($items)->sum('quantity'),
            'created_at' => $d->created_at?->toIso8601String(),
            'available_actions' => ['receive'],
        ];
    }
}
