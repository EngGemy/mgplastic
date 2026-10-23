<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPointsRateChange;
use App\Services\ProductPointsRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductPointsRateController extends Controller
{
    use ApiResponds;

    public function __construct(
        protected ProductPointsRateService $rates,
    ) {}

    /**
     * Preview impact of a catalog points rate change (no write).
     * GET/POST /api/v1/admin/products/{product}/points-rate/preview
     */
    public function preview(Request $request, Product $product): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return $this->error('غير مصرّح', 403);
        }

        $validated = $request->validate([
            'new_points_per_unit' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $impact = $this->rates->estimateImpact($product, (float) $validated['new_points_per_unit']);

        return $this->success([
            'product' => [
                'id' => $product->id,
                'name' => localized_name($product, 'name', 'منتج #'.$product->id),
                'current_points_per_unit' => (float) ($product->points_per_unit ?? 0),
            ],
            'impact' => $impact,
            'policy' => [
                'new_sales_use' => 'catalog_current_rate',
                'historical_invoices' => 'unchanged',
                'stock_fulfillment' => 'may_use_old_slots_but_priced_at_catalog',
            ],
        ], 'تقدير أثر تغيير سعر النقاط');
    }

    /**
     * Apply catalog points rate change + history + admin notify.
     * PUT/POST /api/v1/admin/products/{product}/points-rate
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        if (! $this->canMutate($request)) {
            return $this->error('غير مصرّح', 403);
        }

        $validated = $request->validate([
            'new_points_per_unit' => ['required', 'numeric', 'min:0.0001'],
            'note' => ['nullable', 'string', 'max:500'],
            'notify_admins' => ['sometimes', 'boolean'],
        ]);

        try {
            $change = $this->rates->applyChange(
                $product,
                (float) $validated['new_points_per_unit'],
                $request->user(),
                $validated['note'] ?? null,
                (bool) ($validated['notify_admins'] ?? true),
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->serializeChange($change), 'تم تحديث سعر نقاط المنتج');
    }

    /**
     * History of rate changes (global or per product).
     * GET /api/v1/admin/products/points-rate/history
     * GET /api/v1/admin/products/{product}/points-rate/history
     */
    public function history(Request $request, ?Product $product = null): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return $this->error('غير مصرّح', 403);
        }

        $query = ProductPointsRateChange::query()
            ->with(['product.translations', 'author:id,name,role'])
            ->latest();

        if ($product) {
            $query->where('product_id', $product->id);
        } elseif ($request->filled('product_id')) {
            $query->where('product_id', (int) $request->integer('product_id'));
        }

        $perPage = min(100, max(1, (int) $request->integer('per_page', 20)));
        $paginator = $query->paginate($perPage);

        return $this->success([
            'items' => collect($paginator->items())->map(fn (ProductPointsRateChange $c) => $this->serializeChange($c))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 'سجل أسعار النقاط');
    }

    protected function serializeChange(ProductPointsRateChange $change): array
    {
        $change->loadMissing(['product.translations', 'author:id,name,role']);

        return [
            'id' => $change->id,
            'product_id' => $change->product_id,
            'product_name' => localized_name($change->product, 'name', 'منتج #'.$change->product_id),
            'old_points_per_unit' => (float) $change->old_points_per_unit,
            'new_points_per_unit' => (float) $change->new_points_per_unit,
            'delta_points_per_unit' => (float) $change->delta_points_per_unit,
            'change_percent' => $change->change_percent !== null ? (float) $change->change_percent : null,
            'direction' => $change->direction(),
            'wholesale_remaining_qty' => (int) $change->wholesale_remaining_qty,
            'retail_remaining_qty' => (int) $change->retail_remaining_qty,
            'wholesale_holders' => (int) $change->wholesale_holders,
            'retail_holders' => (int) $change->retail_holders,
            'estimated_points_delta' => (int) $change->estimated_points_delta,
            'note' => $change->note,
            'impact_snapshot' => $change->impact_snapshot,
            'changed_by' => $change->author ? [
                'id' => $change->author->id,
                'name' => $change->author->name,
                'role' => $change->author->role,
            ] : null,
            'created_at' => optional($change->created_at)?->toIso8601String(),
        ];
    }

    protected function isAdmin(Request $request): bool
    {
        return in_array($request->user()?->role, ['super_admin', 'admin', 'monitor'], true);
    }

    protected function canMutate(Request $request): bool
    {
        return in_array($request->user()?->role, ['super_admin', 'admin'], true);
    }
}
