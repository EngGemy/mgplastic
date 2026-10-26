<?php

namespace App\Http\Controllers\Api\Distributor;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\NetworkInventoryService;
use App\Services\RetailDistributionPosService;
use App\Support\ProductSizeSorter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PosController extends Controller
{
    use ApiResponds;

    public function __construct(
        protected NetworkInventoryService $inventory,
        protected RetailDistributionPosService $pos,
    ) {}

    public function stock(Request $request): JsonResponse
    {
        $sizeOrder = ProductSizeSorter::normalizeOrder($request->query('size_order', $request->query('sort')));
        $userId = (int) $request->user()->id;
        $cacheKey = "pos_stock:distributor:{$userId}:{$sizeOrder}";

        $payload = \Illuminate\Support\Facades\Cache::remember($cacheKey, 60, function () use ($request, $sizeOrder) {
            $items = $this->inventory
                ->sortStockByTag($this->inventory->stockForWholesaler($request->user()), $sizeOrder)
                ->values();

            return [
                'items' => $items,
                'groups' => $this->inventory->groupStockByTag($items, $sizeOrder),
                'size_groups' => $this->inventory->groupStockBySize($items, $sizeOrder),
                'categories' => $this->inventory->groupStockByCategoryThenTag($items, $sizeOrder),
                'filters' => [
                    'size_order' => $sizeOrder,
                ],
            ];
        });

        return $this->success($payload, 'مخزون الجملة — مقاسات مرتّبة · '.$sizeOrder);
    }

    public function checkout(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'retail_trader_id' => ['required', 'exists:users,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.boxes' => ['nullable', 'integer', 'min:1'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $v->after(function ($validator) use ($request) {
            foreach ($request->input('lines', []) as $i => $line) {
                $boxes = $line['boxes'] ?? null;
                $qty = $line['quantity'] ?? null;
                if (($boxes === null || (int) $boxes < 1) && ($qty === null || (int) $qty < 1)) {
                    $validator->errors()->add("lines.$i", 'أرسل عدد الصناديق (boxes) أو الكمية بالقطع (quantity).');
                }
            }
        });

        if ($v->fails()) {
            return $this->error('بيانات غير صالحة', 422, $v->errors());
        }

        $trader = User::findOrFail($request->integer('retail_trader_id'));

        try {
            $invoices = $this->pos->issueToRetailTrader(
                $request->user(),
                $trader,
                $request->input('lines'),
                $request->user(),
            );

            $share = null;
            $first = collect($invoices)->first();
            if ($first instanceof \App\Models\Invoice) {
                try {
                    $share = app(\App\Services\InvoiceWhatsAppShareService::class)
                        ->forInvoice($first, $request->user());
                } catch (\Throwable) {
                    $share = null;
                }
            }

            return $this->created([
                'invoices' => $invoices,
                'pending_receipt' => true,
                'whatsapp_share' => $share,
                'share_url' => $share['share_url'] ?? null,
                'whatsapp_url' => $share['whatsapp_url'] ?? null,
            ], 'تم إصدار الفاتورة — بانتظار تأكيد استلام القطاعي');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
