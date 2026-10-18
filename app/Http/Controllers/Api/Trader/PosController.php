<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\NetworkInventoryService;
use App\Services\PlumberDistributionPosService;
use App\Services\RetailNetworkLinkService;
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

    /** Own retail stock (for POS / plumber fulfillment). */
    public function stock(Request $request): JsonResponse
    {
        $stock = $this->inventory->stockForRetailTrader($request->user());

        return $this->success($stock->values()->map(fn (array $row) => $this->mapOwnStockRow($row))->values(), 'مخزونك القطاعي');
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

        $stock = $this->inventory->stockForWholesaler($wholesaler);

        $items = $stock->map(fn (array $row) => [
            'product_id' => (int) $row['product_id'],
            'name' => $row['name'],
            'image' => $row['image'] ?? null,
            'image_url' => $row['image'] ?? null,
            'category_id' => $row['category_id'] ?? null,
            'points_per_unit' => null,
            'has_points' => ((float) ($row['points_per_unit'] ?? 0)) > 0,
            'available_qty' => (int) ($row['available_qty'] ?? 0),
            'available_quantity' => (int) ($row['available_qty'] ?? 0),
        ])->values();

        return $this->success([
            'wholesaler' => [
                'id' => $wholesaler->id,
                'name' => $wholesaler->brand_name ?: $wholesaler->name,
                'phone' => $wholesaler->phone,
            ],
            'items' => $items,
        ], 'مخزون الجملة المتاح');
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
            $distributions = $this->pos->issueToPlumber(
                $request->user(),
                $plumber,
                $request->input('lines'),
                $request->user(),
            );

            $plumber->refresh();
            $balancePoints = (int) ($plumber->wallet('LYD')->balance_points ?? 0);

            return $this->created([
                'distributions' => $distributions,
                'total_points' => collect($distributions)->sum(fn ($d) => $d->total_points),
                'plumber_id' => $plumber->id,
                'plumber_name' => $plumber->name,
                'plumber_balance_points' => $balancePoints,
                'balance_points' => $balancePoints,
            ], 'تم توزيع النقاط للسبّاك');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
