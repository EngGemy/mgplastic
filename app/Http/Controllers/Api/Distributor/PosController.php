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
        $items = $this->inventory
            ->sortStockByTag($this->inventory->stockForWholesaler($request->user()), $sizeOrder)
            ->values();

        return $this->success([
            'items' => $items,
            'groups' => $this->inventory->groupStockByTag($items, $sizeOrder),
            'categories' => $this->inventory->groupStockByCategoryThenTag($items, $sizeOrder),
            'filters' => [
                'size_order' => $sizeOrder,
            ],
        ], 'مخزون الجملة — تصنيف ثم تاج · ترتيب المقاس '.$sizeOrder);
    }

    public function checkout(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'retail_trader_id' => ['required', 'exists:users,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

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
                'whatsapp_share' => $share,
                'share_url' => $share['share_url'] ?? null,
                'whatsapp_url' => $share['whatsapp_url'] ?? null,
            ], 'تم البيع للتاجر القطاعي');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
