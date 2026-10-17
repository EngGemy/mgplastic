<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceReturn;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\InvoiceReturnStatus;
use App\Support\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NetworkReportService
{
    /**
     * @return array{
     *   role:string,
     *   from:string,
     *   to:string,
     *   totals:array<string,int|float>,
     *   daily:list<array<string,mixed>>,
     *   top_customers:list<array<string,mixed>>,
     *   top_products:list<array<string,mixed>>
     * }
     */
    public function forUser(User $user, ?string $from = null, ?string $to = null): array
    {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : now()->subDays(29)->startOfDay();
        $toDate = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        if ($fromDate->gt($toDate)) {
            [$fromDate, $toDate] = [$toDate->copy()->startOfDay(), $fromDate->copy()->endOfDay()];
        }

        if ($user->isWholesaleDistributor()) {
            return $this->wholesaleReport($user, $fromDate, $toDate);
        }

        if ($user->isRetailTrader()) {
            return $this->retailReport($user, $fromDate, $toDate);
        }

        if ($user->isPlumber()) {
            return $this->plumberReport($user, $fromDate, $toDate);
        }

        return [
            'role' => $user->role ?? 'unknown',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'totals' => [],
            'daily' => [],
            'top_customers' => [],
            'top_products' => [],
        ];
    }

    protected function wholesaleReport(User $user, Carbon $from, Carbon $to): array
    {
        $ordersPlaced = Order::query()
            ->where('requester_id', $user->id)
            ->whereBetween('created_at', [$from, $to]);

        $ordersIncoming = Order::query()
            ->where('supplier_id', $user->id)
            ->whereBetween('created_at', [$from, $to]);

        $outgoingIds = InvoiceDistribution::query()
            ->where('from_user_id', $user->id)
            ->where('tier', 2)
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->whereBetween('confirmed_at', [$from, $to])
            ->pluck('id');

        $returnsPending = InvoiceReturn::query()
            ->where('to_user_id', $user->id)
            ->where('status', InvoiceReturnStatus::PENDING)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $returnsApproved = InvoiceReturn::query()
            ->where(function ($q) use ($user) {
                $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id);
            })
            ->whereIn('status', InvoiceReturnStatus::settled())
            ->whereBetween('confirmed_at', [$from, $to])
            ->count();

        $salesAgg = $outgoingIds->isEmpty()
            ? (object) ['qty' => 0, 'points' => 0]
            : DB::table('invoice_distribution_items')
                ->whereIn('distribution_id', $outgoingIds)
                ->selectRaw('COALESCE(SUM(quantity),0) as qty, COALESCE(SUM(points_value),0) as points')
                ->first();

        $totals = [
            'orders_placed' => (clone $ordersPlaced)->count(),
            'orders_incoming' => (clone $ordersIncoming)->count(),
            'orders_delivered' => (clone $ordersIncoming)->where('status', OrderStatus::DELIVERED)->count()
                + (clone $ordersPlaced)->where('status', OrderStatus::DELIVERED)->count(),
            'sales_qty' => (int) ($salesAgg->qty ?? 0),
            'sales_points' => (int) ($salesAgg->points ?? 0),
            'returns_pending' => $returnsPending,
            'returns_approved' => $returnsApproved,
        ];

        $daily = $this->dailyBuckets($from, $to, function (Carbon $day) use ($user) {
            $start = $day->copy()->startOfDay();
            $end = $day->copy()->endOfDay();
            $ids = InvoiceDistribution::query()
                ->where('from_user_id', $user->id)
                ->where('tier', 2)
                ->whereIn('status', ['confirmed', 'points_awarded'])
                ->whereBetween('confirmed_at', [$start, $end])
                ->pluck('id');

            return [
                'orders_placed' => Order::query()
                    ->where('requester_id', $user->id)
                    ->whereBetween('created_at', [$start, $end])
                    ->count(),
                'orders_incoming' => Order::query()
                    ->where('supplier_id', $user->id)
                    ->whereBetween('created_at', [$start, $end])
                    ->count(),
                'sales_points' => $ids->isEmpty()
                    ? 0
                    : (int) DB::table('invoice_distribution_items')->whereIn('distribution_id', $ids)->sum('points_value'),
            ];
        });

        $topCustomers = InvoiceDistribution::query()
            ->select('to_user_id', DB::raw('COUNT(*) as orders_count'))
            ->where('from_user_id', $user->id)
            ->where('tier', 2)
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->whereBetween('confirmed_at', [$from, $to])
            ->groupBy('to_user_id')
            ->orderByDesc('orders_count')
            ->limit(10)
            ->with('toUser:id,name,brand_name')
            ->get()
            ->map(function ($row) use ($from, $to, $user) {
                $ids = InvoiceDistribution::query()
                    ->where('from_user_id', $user->id)
                    ->where('to_user_id', $row->to_user_id)
                    ->where('tier', 2)
                    ->whereIn('status', ['confirmed', 'points_awarded'])
                    ->whereBetween('confirmed_at', [$from, $to])
                    ->pluck('id');

                return [
                    'id' => (int) $row->to_user_id,
                    'name' => $row->toUser?->brand_name ?: $row->toUser?->name,
                    'orders_count' => (int) $row->orders_count,
                    'points' => $ids->isEmpty()
                        ? 0
                        : (int) DB::table('invoice_distribution_items')->whereIn('distribution_id', $ids)->sum('points_value'),
                ];
            })
            ->values()
            ->all();

        $topProducts = $this->topProductsFromDistributions($user->id, 'from_user_id', 2, $from, $to);

        return [
            'role' => $user->isCentralWarehouse() ? 'warehouse' : 'distributor',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $totals,
            'daily' => $daily,
            'top_customers' => $topCustomers,
            'top_products' => $topProducts,
        ];
    }

    protected function retailReport(User $user, Carbon $from, Carbon $to): array
    {
        $ordersToWholesale = Order::query()
            ->where('requester_id', $user->id)
            ->where('channel', OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL)
            ->whereBetween('created_at', [$from, $to]);

        $ordersFromPlumbers = Order::query()
            ->where('supplier_id', $user->id)
            ->where('channel', OrderStatus::CHANNEL_RETAIL_TO_PLUMBER)
            ->whereBetween('created_at', [$from, $to]);

        $saleIds = InvoiceDistribution::query()
            ->where('from_user_id', $user->id)
            ->where('tier', 3)
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->whereBetween('confirmed_at', [$from, $to])
            ->pluck('id');

        $totals = [
            'orders_to_wholesale' => (clone $ordersToWholesale)->count(),
            'orders_from_plumbers' => (clone $ordersFromPlumbers)->count(),
            'sales_points' => $saleIds->isEmpty()
                ? 0
                : (int) DB::table('invoice_distribution_items')->whereIn('distribution_id', $saleIds)->sum('points_value'),
            'returns_pending' => InvoiceReturn::query()
                ->where('to_user_id', $user->id)
                ->where('status', InvoiceReturnStatus::PENDING)
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            'returns_approved' => InvoiceReturn::query()
                ->where(function ($q) use ($user) {
                    $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id);
                })
                ->whereIn('status', InvoiceReturnStatus::settled())
                ->whereBetween('confirmed_at', [$from, $to])
                ->count(),
        ];

        $daily = $this->dailyBuckets($from, $to, function (Carbon $day) use ($user) {
            $start = $day->copy()->startOfDay();
            $end = $day->copy()->endOfDay();
            $ids = InvoiceDistribution::query()
                ->where('from_user_id', $user->id)
                ->where('tier', 3)
                ->whereIn('status', ['confirmed', 'points_awarded'])
                ->whereBetween('confirmed_at', [$start, $end])
                ->pluck('id');

            return [
                'orders_from_plumbers' => Order::query()
                    ->where('supplier_id', $user->id)
                    ->where('channel', OrderStatus::CHANNEL_RETAIL_TO_PLUMBER)
                    ->whereBetween('created_at', [$start, $end])
                    ->count(),
                'sales_points' => $ids->isEmpty()
                    ? 0
                    : (int) DB::table('invoice_distribution_items')->whereIn('distribution_id', $ids)->sum('points_value'),
            ];
        });

        $topCustomers = InvoiceDistribution::query()
            ->select('to_user_id', DB::raw('COUNT(*) as orders_count'))
            ->where('from_user_id', $user->id)
            ->where('tier', 3)
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->whereBetween('confirmed_at', [$from, $to])
            ->groupBy('to_user_id')
            ->orderByDesc('orders_count')
            ->limit(10)
            ->with('toUser:id,name,brand_name')
            ->get()
            ->map(function ($row) use ($from, $to, $user) {
                $ids = InvoiceDistribution::query()
                    ->where('from_user_id', $user->id)
                    ->where('to_user_id', $row->to_user_id)
                    ->where('tier', 3)
                    ->whereIn('status', ['confirmed', 'points_awarded'])
                    ->whereBetween('confirmed_at', [$from, $to])
                    ->pluck('id');

                return [
                    'id' => (int) $row->to_user_id,
                    'name' => $row->toUser?->brand_name ?: $row->toUser?->name,
                    'orders_count' => (int) $row->orders_count,
                    'points' => $ids->isEmpty()
                        ? 0
                        : (int) DB::table('invoice_distribution_items')->whereIn('distribution_id', $ids)->sum('points_value'),
                ];
            })
            ->values()
            ->all();

        return [
            'role' => 'trader',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $totals,
            'daily' => $daily,
            'top_customers' => $topCustomers,
            'top_products' => $this->topProductsFromDistributions($user->id, 'from_user_id', 3, $from, $to),
        ];
    }

    protected function plumberReport(User $user, Carbon $from, Carbon $to): array
    {
        $orders = Order::query()
            ->where('requester_id', $user->id)
            ->where('channel', OrderStatus::CHANNEL_RETAIL_TO_PLUMBER)
            ->whereBetween('created_at', [$from, $to]);

        $receivedIds = InvoiceDistribution::query()
            ->where('to_user_id', $user->id)
            ->where('tier', 3)
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->whereBetween('confirmed_at', [$from, $to])
            ->pluck('id');

        $totals = [
            'orders_placed' => (clone $orders)->count(),
            'orders_delivered' => (clone $orders)->where('status', OrderStatus::DELIVERED)->count(),
            'points_received' => $receivedIds->isEmpty()
                ? 0
                : (int) DB::table('invoice_distribution_items')->whereIn('distribution_id', $receivedIds)->sum('points_value'),
            'returns_placed' => InvoiceReturn::query()
                ->where('from_user_id', $user->id)
                ->whereBetween('created_at', [$from, $to])
                ->count(),
        ];

        $daily = $this->dailyBuckets($from, $to, function (Carbon $day) use ($user) {
            $start = $day->copy()->startOfDay();
            $end = $day->copy()->endOfDay();
            $ids = InvoiceDistribution::query()
                ->where('to_user_id', $user->id)
                ->where('tier', 3)
                ->whereIn('status', ['confirmed', 'points_awarded'])
                ->whereBetween('confirmed_at', [$start, $end])
                ->pluck('id');

            return [
                'orders_placed' => Order::query()
                    ->where('requester_id', $user->id)
                    ->whereBetween('created_at', [$start, $end])
                    ->count(),
                'points_received' => $ids->isEmpty()
                    ? 0
                    : (int) DB::table('invoice_distribution_items')->whereIn('distribution_id', $ids)->sum('points_value'),
            ];
        });

        return [
            'role' => 'plumber',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $totals,
            'daily' => $daily,
            'top_customers' => [],
            'top_products' => $this->topProductsFromDistributions($user->id, 'to_user_id', 3, $from, $to),
        ];
    }

    /**
     * @param  callable(Carbon): array<string, int|float>  $metricsForDay
     * @return list<array<string, mixed>>
     */
    protected function dailyBuckets(Carbon $from, Carbon $to, callable $metricsForDay): array
    {
        $days = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $days[] = array_merge([
                'date' => $cursor->toDateString(),
            ], $metricsForDay($cursor->copy()));
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * @return list<array{product_id:int, name:string, quantity:int, points:int}>
     */
    protected function topProductsFromDistributions(
        int $userId,
        string $userColumn,
        int $tier,
        Carbon $from,
        Carbon $to,
    ): array {
        $distributionIds = InvoiceDistribution::query()
            ->where($userColumn, $userId)
            ->where('tier', $tier)
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->whereBetween('confirmed_at', [$from, $to])
            ->pluck('id');

        if ($distributionIds->isEmpty()) {
            return [];
        }

        return DB::table('invoice_distribution_items as di')
            ->join('invoice_items as ii', 'ii.id', '=', 'di.invoice_item_id')
            ->leftJoin('products as p', 'p.id', '=', 'ii.product_id')
            ->whereIn('di.distribution_id', $distributionIds)
            ->groupBy('ii.product_id', 'p.id')
            ->orderByDesc(DB::raw('SUM(di.quantity)'))
            ->limit(10)
            ->get([
                'ii.product_id',
                DB::raw('SUM(di.quantity) as quantity'),
                DB::raw('SUM(di.points_value) as points'),
            ])
            ->map(function ($row) {
                $product = $row->product_id
                    ? \App\Models\Product::with('translations')->find($row->product_id)
                    : null;

                return [
                    'product_id' => (int) $row->product_id,
                    'name' => $product ? localized_name($product, 'name', 'منتج #'.$row->product_id) : 'منتج',
                    'quantity' => (int) $row->quantity,
                    'points' => (int) $row->points,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Per-warehouse summaries for the central invitation warehouse overview.
     *
     * @return list<array{
     *   id:int,
     *   name:string,
     *   network_code:?string,
     *   retail_count:int,
     *   orders_incoming:int,
     *   orders_placed:int,
     *   sales_qty:int,
     *   sales_points:int,
     *   returns_pending:int
     * }>
     */
    public function networkWholesalerSummaries(User $central, ?string $from = null, ?string $to = null): array
    {
        if (! $central->isCentralWarehouse()) {
            return [];
        }

        $fromDate = $from ? Carbon::parse($from)->startOfDay() : now()->subDays(29)->startOfDay();
        $toDate = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        if ($fromDate->gt($toDate)) {
            [$fromDate, $toDate] = [$toDate->copy()->startOfDay(), $fromDate->copy()->endOfDay()];
        }

        $warehouses = User::query()
            ->where('role', 'wholesale_distributor')
            ->where(function ($q) {
                $q->where('is_central_warehouse', false)->orWhereNull('is_central_warehouse');
            })
            ->whereKeyNot($central->id)
            ->orderBy('brand_name')
            ->withCount(['linkedRetailTraders as retail_count'])
            ->get(['id', 'name', 'brand_name', 'network_code']);

        return $warehouses->map(function (User $w) use ($fromDate, $toDate) {
            $outgoingIds = InvoiceDistribution::query()
                ->where('from_user_id', $w->id)
                ->where('tier', 2)
                ->whereIn('status', ['confirmed', 'points_awarded'])
                ->whereBetween('confirmed_at', [$fromDate, $toDate])
                ->pluck('id');

            $salesAgg = $outgoingIds->isEmpty()
                ? (object) ['qty' => 0, 'points' => 0]
                : DB::table('invoice_distribution_items')
                    ->whereIn('distribution_id', $outgoingIds)
                    ->selectRaw('COALESCE(SUM(quantity),0) as qty, COALESCE(SUM(points_value),0) as points')
                    ->first();

            return [
                'id' => (int) $w->id,
                'name' => $w->brand_name ?: $w->name,
                'network_code' => $w->network_code,
                'retail_count' => (int) ($w->retail_count ?? 0),
                'orders_incoming' => Order::query()
                    ->where('supplier_id', $w->id)
                    ->whereBetween('created_at', [$fromDate, $toDate])
                    ->count(),
                'orders_placed' => Order::query()
                    ->where('requester_id', $w->id)
                    ->whereBetween('created_at', [$fromDate, $toDate])
                    ->count(),
                'sales_qty' => (int) ($salesAgg->qty ?? 0),
                'sales_points' => (int) ($salesAgg->points ?? 0),
                'returns_pending' => InvoiceReturn::query()
                    ->where('to_user_id', $w->id)
                    ->where('status', InvoiceReturnStatus::PENDING)
                    ->count(),
            ];
        })->values()->all();
    }
}
