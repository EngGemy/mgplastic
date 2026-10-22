<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\NetworkInventoryService;
use App\Services\PlumberDistributionPosService;
use App\Services\RetailNetworkLinkService;
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
        $stock = $this->inventory->stockForRetailTrader($request->user());
        $items = $this->inventory
            ->sortStockByTag($stock->map(fn (array $row) => $this->mapOwnStockRow($row)), $sizeOrder)
            ->values();

        return $this->success([
            'items' => $items,
            'groups' => $this->inventory->groupStockByTag($items, $sizeOrder),
            'size_groups' => $this->inventory->groupStockBySize($items, $sizeOrder),
            'categories' => $this->inventory->groupStockByCategoryThenTag($items, $sizeOrder),
            'filters' => [
                'size_order' => $sizeOrder,
            ],
        ], 'مخزونك القطاعي — مقاسات مرتّبة (1→1.5→3…) · '.$sizeOrder);
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

        $items = $this->inventory
            ->sortStockByTag($stock->map(fn (array $row) => [
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
            ]), $sizeOrder)
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
        $slots = collect($row['slots'] ?? [])->map(function (array $slot) {
            unset($slot['points_per_unit']);

            return $slot + ['points_per_unit' => null];
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
            // Per-unit points hidden — apps show cart/order totals only.
            'points_per_unit' => null,
            'has_points' => $ppu > 0,
            'available_qty' => (int) ($row['available_qty'] ?? 0),
            'available_points' => null,
            'slots' => $slots,
        ];
    }

    public function checkout(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'plumber_id' => ['required', 'exists:users,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

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
                'total_points' => collect($distributions)->sum(fn ($d) => $d->total_points ?? (int) $d->items?->sum('points_value')),
                'plumber_id' => $plumber->id,
                'plumber_name' => $plumber->name,
                'plumber_balance_points' => $balancePoints,
                'balance_points' => $balancePoints,
                'new_badges' => $reputation['new_badges'] ?? [],
                'score_delta' => (int) ($reputation['score_delta'] ?? 0),
                'level_changed' => (bool) ($reputation['level_changed'] ?? false),
                // مشاركة واتساب لصاحب رقم السباك
                'whatsapp_share' => $share,
                'share_url' => $share['share_url'] ?? null,
                'whatsapp_url' => $share['whatsapp_url'] ?? null,
            ], 'تم توزيع النقاط للسبّاك');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
