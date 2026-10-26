<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\NetworkInventoryService;
use App\Services\PlumberDistributionPosService;
use App\Services\Points\PointsRateHintBuilder;
use App\Services\RetailNetworkLinkService;
use App\Support\PointsVisibility;
use App\Support\ProductSizeSorter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PosController extends Controller
{
    use ApiResponds;

    public function __construct(
        protected NetworkInventoryService $inventory,
        protected PlumberDistributionPosService $pos,
        protected RetailNetworkLinkService $links,
    ) {}

    /** Own retail stock — categories → tags → products (ملحقات), size-ordered. */
    public function stock(Request $request): JsonResponse
    {
        $sizeOrder = ProductSizeSorter::normalizeOrder($request->query('size_order', $request->query('sort')));
        $userId = (int) $request->user()->id;
        $cacheKey = "pos_stock:trader:{$userId}:{$sizeOrder}";

        $payload = \Illuminate\Support\Facades\Cache::remember($cacheKey, 60, function () use ($request, $sizeOrder) {
            $stock = $this->inventory->stockForRetailTrader($request->user());
            $items = $this->inventory
                ->sortStockByTag($stock->map(fn (array $row) => $this->mapOwnStockRow($row)), $sizeOrder)
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

        return $this->success($payload, 'مخزونك القطاعي — مقاسات مرتّبة (1→1.5→3…) · '.$sizeOrder);
    }

    /**
     * Linked wholesaler remaining stock — for placing supply orders.
     * Catalog-style: points_per_unit is null; use has_points + available_qty.
     */
    public function wholesalerStock(Request $request, User $wholesaler): JsonResponse
    {
        if (! $wholesaler->isWholesaleDistributor()) {
            return $this->error('هذا الحساب ليس موزّع جملة', 422);
        }

        if (! $this->links->isLinked($wholesaler, $request->user())) {
            return $this->error('هذا الموزّع غير مرتبط بشبكتك', 403);
        }

        $sizeOrder = ProductSizeSorter::normalizeOrder($request->query('size_order', $request->query('sort')));
        $stock = $this->inventory->stockForWholesaler($wholesaler);

        $hintBuilder = app(PointsRateHintBuilder::class);
        $hideRates = PointsVisibility::hidePerUnit($request->user());

        $items = $this->inventory
            ->sortStockByTag($stock->map(function (array $row) use ($hintBuilder, $hideRates) {
                $hint = $hintBuilder->forMobile($row['points_rate_hint'] ?? null, $hideRates);

                return [
                    'product_id' => (int) $row['product_id'],
                    'name' => $row['name'],
                    'image' => $row['image'] ?? null,
                    'image_url' => $row['image'] ?? null,
                    'category_id' => $row['category_id'] ?? null,
                    'category_name' => $row['category_name'] ?? null,
                    'is_accessories' => (bool) ($row['is_accessories'] ?? false),
                    'tags' => $row['tags'] ?? [],
                    'tag' => $row['tag'] ?? null,
                    'group_key' => $row['group_key'] ?? null,
                    'group_name' => $row['group_name'] ?? null,
                    'product_type' => $row['product_type'] ?? null,
                    'product_type_name' => $row['product_type_name'] ?? null,
                    'size_value' => $row['size_value'] ?? null,
                    'size_label' => $row['size_label'] ?? null,
                    'size_unit' => $row['size_unit'] ?? null,
                    'size_sort' => $row['size_sort'] ?? null,
                    'points_per_unit' => null,
                    'has_points' => ((float) ($row['points_per_unit'] ?? 0)) > 0,
                    'available_qty' => (int) ($row['available_qty'] ?? 0),
                    'available_quantity' => (int) ($row['available_qty'] ?? 0),
                    'points_rate_changed' => $hint !== null,
                    'points_rate_hint' => $hint,
                ];
            }), $sizeOrder)
            ->values();

        return $this->success([
            'wholesaler' => [
                'id' => $wholesaler->id,
                'name' => $wholesaler->brand_name ?: $wholesaler->name,
                'phone' => $wholesaler->phone,
            ],
            'items' => $items,
            'groups' => $this->inventory->groupStockByTag($items, $sizeOrder),
            'size_groups' => $this->inventory->groupStockBySize($items, $sizeOrder),
            'categories' => $this->inventory->groupStockByCategoryThenTag($items, $sizeOrder),
            'filters' => [
                'size_order' => $sizeOrder,
            ],
        ], 'مخزون الجملة — مقاسات مرتّبة · '.$sizeOrder);
    }

    /** @param  array<string, mixed>  $row */
    protected function mapOwnStockRow(array $row): array
    {
        $ppu = (float) ($row['points_per_unit'] ?? 0);
        $hintBuilder = app(PointsRateHintBuilder::class);
        $hideRates = PointsVisibility::hidePerUnit();
        $productHint = $hintBuilder->forMobile($row['points_rate_hint'] ?? null, $hideRates);

        $slots = collect($row['slots'] ?? [])->map(function (array $slot) use ($hintBuilder, $hideRates) {
            $slotHint = $hintBuilder->forMobile($slot['points_rate_hint'] ?? null, $hideRates);
            unset($slot['points_per_unit'], $slot['receive_points_per_unit']);

            return $slot + [
                'points_per_unit' => null,
                'points_rate_changed' => $slotHint !== null,
                'points_rate_hint' => $slotHint,
            ];
        })->values()->all();

        return [
            'product_id' => (int) $row['product_id'],
            'name' => $row['name'],
            'image' => $row['image'] ?? null,
            'image_url' => $row['image'] ?? null,
            'category_id' => $row['category_id'] ?? null,
            'category_name' => $row['category_name'] ?? null,
            'is_accessories' => (bool) ($row['is_accessories'] ?? false),
            'tags' => $row['tags'] ?? [],
            'tag' => $row['tag'] ?? null,
            'group_key' => $row['group_key'] ?? null,
            'group_name' => $row['group_name'] ?? null,
            'product_type' => $row['product_type'] ?? null,
            'product_type_name' => $row['product_type_name'] ?? null,
            'size_value' => isset($row['size_value']) ? (float) $row['size_value'] : null,
            'size_label' => $row['size_label'] ?? null,
            'size_unit' => $row['size_unit'] ?? null,
            'size_sort' => isset($row['size_sort']) ? (float) $row['size_sort'] : null,
            'pieces_per_box' => isset($row['pieces_per_box']) && (int) $row['pieces_per_box'] > 0
                ? (int) $row['pieces_per_box']
                : null,
            'order_unit' => ($row['order_unit'] ?? null)
                ?? ((isset($row['pieces_per_box']) && (int) $row['pieces_per_box'] > 0) ? 'box' : 'piece'),
            // Per-unit points hidden — apps show cart/order totals only.
            'points_per_unit' => null,
            'has_points' => $ppu > 0,
            'available_qty' => (int) ($row['available_qty'] ?? 0),
            'available_points' => null,
            'points_rate_changed' => $productHint !== null,
            'points_rate_hint' => $productHint,
            'slots' => $slots,
        ];
    }

    public function checkout(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'plumber_id' => ['required', 'exists:users,id'],
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

        $plumber = User::findOrFail($request->integer('plumber_id'));

        try {
            $pack = $this->pos->issueToPlumber(
                $request->user(),
                $plumber,
                $request->input('lines'),
                $request->user(),
            );
            $distributions = $pack['distributions'] ?? $pack;
            $reputation = $pack['reputation'] ?? ['new_badges' => [], 'score_delta' => 0, 'level_changed' => false];

            $plumber->refresh();
            $balancePoints = (int) ($plumber->wallet('LYD')->balance_points ?? 0);

            $share = null;
            $firstDist = collect($distributions)->first();
            if ($firstDist) {
                try {
                    $share = app(\App\Services\InvoiceWhatsAppShareService::class)
                        ->forDistribution($firstDist, $request->user());
                } catch (\Throwable) {
                    $share = null;
                }
            }

            return $this->created([
                'distributions' => $distributions,
                'pending_receipt' => true,
                'total_points' => collect($distributions)->sum(fn ($d) => $d->total_points ?? (int) $d->items?->sum('points_value')),
                'plumber_id' => $plumber->id,
                'plumber_name' => $plumber->name,
                // Points settle only after plumber confirms receipt.
                'plumber_balance_points' => $balancePoints,
                'balance_points' => $balancePoints,
                'new_badges' => $reputation['new_badges'] ?? [],
                'score_delta' => (int) ($reputation['score_delta'] ?? 0),
                'level_changed' => (bool) ($reputation['level_changed'] ?? false),
                'whatsapp_share' => $share,
                'share_url' => $share['share_url'] ?? null,
                'whatsapp_url' => $share['whatsapp_url'] ?? null,
            ], 'تم إصدار الفاتورة — بانتظار تأكيد استلام السباك');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
