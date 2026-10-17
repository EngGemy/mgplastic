<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WithdrawalRequest;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregated KPIs for decision-maker reports (admin panel).
 */
class DecisionReportsService
{
    /** @return array<string, mixed> */
    public function snapshot(?int $days = 30): array
    {
        $days = max(7, min(365, $days ?? 30));
        $from = now()->subDays($days)->startOfDay();

        return [
            'window_days' => $days,
            'generated_at' => now()->toIso8601String(),
            'executive' => $this->executiveCards($from),
            'network' => $this->networkFunnel(),
            'orders' => $this->ordersReport($from),
            'points' => $this->pointsFlow($from),
            'finance' => $this->financeReport($from),
            'inventory' => $this->inventoryHighlights(),
            'alerts' => $this->decisionAlerts($from),
            'trends' => $this->dailyTrends($from),
        ];
    }

    /** Compact payload for Gemini (token-friendly). */
    public function geminiContext(?int $days = 30): array
    {
        $snap = $this->snapshot($days);

        return [
            'system' => 'MG Plastic — شبكة مصنع → جملة → قطاعي → سباك في ليبيا',
            'window_days' => $snap['window_days'],
            'executive' => collect($snap['executive'])->mapWithKeys(
                fn ($c) => [$c['label'] => $c['value']]
            )->all(),
            'network' => $snap['network'],
            'orders_summary' => $snap['orders']['summary'] ?? [],
            'points_summary' => $snap['points']['summary'] ?? [],
            'finance_summary' => $snap['finance']['summary'] ?? [],
            'alerts' => $snap['alerts'],
        ];
    }

    /** @return list<array{label:string,value:int|float|string,color:string,hint:?string}> */
    protected function executiveCards(\DateTimeInterface $from): array
    {
        $network = app(WholesalerNetworkService::class)->networkTotals();

        return [
            [
                'label' => 'موزعو الجملة',
                'value' => User::query()->where('role', 'wholesale_distributor')->where('is_active', true)->count(),
                'color' => 'blue',
                'hint' => 'نشطون في الشبكة',
            ],
            [
                'label' => 'تجار قطاعي',
                'value' => User::query()->where('role', 'retail_trader')->where('is_approved', true)->count(),
                'color' => 'amber',
                'hint' => 'معتمدون',
            ],
            [
                'label' => 'سباكون',
                'value' => User::query()->where('role', 'plumber')->where('is_active', true)->count(),
                'color' => 'green',
                'hint' => 'رصيد نقاط: '.number_format((int) ($network['plumber_balances'] ?? 0)),
            ],
            [
                'label' => 'نقاط المصنع',
                'value' => (int) ($network['factory_points'] ?? 0),
                'color' => 'purple',
                'hint' => 'متبقي جملة: '.number_format((int) ($network['remaining_points'] ?? 0)),
            ],
            [
                'label' => 'طلبيات مفتوحة',
                'value' => Order::query()->whereIn('status', [
                    OrderStatus::PLACED,
                    OrderStatus::CONFIRMED,
                    OrderStatus::SHIPPING,
                ])->count(),
                'color' => 'teal',
                'hint' => 'آخر '.now()->diffInDays($from).' يوم: '.Order::query()->where('created_at', '>=', $from)->count().' طلب',
            ],
            [
                'label' => 'سحوبات معلّقة',
                'value' => WithdrawalRequest::query()->where('status', 'pending')->count(),
                'color' => 'amber',
                'hint' => 'تحتاج قرار مالي',
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function networkFunnel(): array
    {
        $network = app(WholesalerNetworkService::class)->networkTotals();
        $pivot = Schema::hasTable('wholesaler_retail_trader')
            ? (int) DB::table('wholesaler_retail_trader')->count()
            : 0;

        $linkedPlumbers = User::query()
            ->where('role', 'plumber')
            ->whereNotNull('parent_distributor_id')
            ->count();

        $unlinkedPlumbers = User::query()
            ->where('role', 'plumber')
            ->whereNull('parent_distributor_id')
            ->count();

        return [
            'steps' => [
                ['key' => 'factory', 'label' => 'نقاط المصنع', 'value' => (int) ($network['factory_points'] ?? 0), 'color' => 'purple'],
                ['key' => 'wholesale_remaining', 'label' => 'متبقي عند الجملة', 'value' => (int) ($network['remaining_points'] ?? 0), 'color' => 'blue'],
                ['key' => 'distributed', 'label' => 'موزَّع للقطاعي', 'value' => (int) ($network['distributed_points'] ?? 0), 'color' => 'amber'],
                ['key' => 'plumber_balances', 'label' => 'أرصدة السباكين', 'value' => (int) ($network['plumber_balances'] ?? 0), 'color' => 'green'],
            ],
            'links' => [
                'wholesaler_retail_links' => $pivot,
                'plumbers_linked' => $linkedPlumbers,
                'plumbers_unlinked' => $unlinkedPlumbers,
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function ordersReport(\DateTimeInterface $from): array
    {
        $base = Order::query()->where('created_at', '>=', $from);

        $byStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $byChannel = (clone $base)
            ->selectRaw('channel, COUNT(*) as total')
            ->groupBy('channel')
            ->pluck('total', 'channel')
            ->all();

        $delivered = (int) ($byStatus[OrderStatus::DELIVERED] ?? 0);
        $total = max(1, (int) array_sum($byStatus));
        $open = (int) (($byStatus[OrderStatus::PLACED] ?? 0)
            + ($byStatus[OrderStatus::CONFIRMED] ?? 0)
            + ($byStatus[OrderStatus::SHIPPING] ?? 0));

        $rows = Order::query()
            ->with(['requester:id,name,brand_name', 'supplier:id,name,brand_name'])
            ->where('created_at', '>=', $from)
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'channel' => OrderStatus::channelLabel($o->channel),
                'status' => OrderStatus::label($o->status),
                'status_color' => OrderStatus::color($o->status),
                'requester' => $o->requester?->brand_name ?: $o->requester?->name,
                'supplier' => $o->supplier?->brand_name ?: ($o->supplier?->name ?: 'المصنع'),
                'qty' => (int) $o->total_quantity,
                'points' => (int) $o->total_points,
                'created_at' => optional($o->created_at)->format('Y/m/d H:i'),
            ])
            ->all();

        return [
            'summary' => [
                'total' => (int) array_sum($byStatus),
                'open' => $open,
                'delivered' => $delivered,
                'delivery_rate' => round(($delivered / $total) * 100, 1),
                'cancelled' => (int) (($byStatus[OrderStatus::CANCELLED] ?? 0) + ($byStatus[OrderStatus::REJECTED] ?? 0)),
            ],
            'by_status' => collect($byStatus)->map(fn ($n, $s) => [
                'status' => $s,
                'label' => OrderStatus::label($s),
                'count' => (int) $n,
            ])->values()->all(),
            'by_channel' => collect($byChannel)->map(fn ($n, $c) => [
                'channel' => $c,
                'label' => OrderStatus::channelLabel($c),
                'count' => (int) $n,
            ])->values()->all(),
            'recent' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    protected function pointsFlow(\DateTimeInterface $from): array
    {
        $network = app(WholesalerNetworkService::class)->networkTotals();

        $tier3Awarded = (int) DB::table('invoice_distribution_items as idi')
            ->join('invoice_distributions as id', 'id.id', '=', 'idi.distribution_id')
            ->where('id.tier', 3)
            ->whereIn('id.status', ['confirmed', 'points_awarded'])
            ->where('id.created_at', '>=', $from)
            ->selectRaw('COALESCE(SUM(
                CASE WHEN idi.quantity > 0
                THEN CAST((idi.quantity - COALESCE(idi.returned_quantity,0)) * idi.points_value / idi.quantity AS SIGNED)
                ELSE 0 END
            ), 0) as total')
            ->value('total');

        $returnsPoints = (int) InvoiceReturn::query()
            ->where('status', 'confirmed')
            ->where('created_at', '>=', $from)
            ->sum('total_points');

        $productsWithPoints = Product::query()->where('points_per_unit', '>', 0)->count();

        return [
            'summary' => [
                'factory_points' => (int) ($network['factory_points'] ?? 0),
                'wholesale_remaining' => (int) ($network['remaining_points'] ?? 0),
                'distributed' => (int) ($network['distributed_points'] ?? 0),
                'plumber_balances' => (int) ($network['plumber_balances'] ?? 0),
                'awarded_in_window' => $tier3Awarded,
                'returned_points_in_window' => $returnsPoints,
                'products_with_points' => $productsWithPoints,
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function financeReport(\DateTimeInterface $from): array
    {
        $pending = WithdrawalRequest::query()->where('status', 'pending')->count();
        $paid = WithdrawalRequest::query()->where('status', 'paid')->where('updated_at', '>=', $from)->count();
        $rejected = WithdrawalRequest::query()->where('status', 'rejected')->where('updated_at', '>=', $from)->count();

        $pendingCents = (int) WithdrawalRequest::query()->where('status', 'pending')->sum('amount_cents');
        $paidCents = (int) WithdrawalRequest::query()->where('status', 'paid')->where('updated_at', '>=', $from)->sum('amount_cents');

        $walletPoints = (int) WalletAccount::query()->sum('balance_points');
        $walletCents = (int) WalletAccount::query()->sum('balance_cents');

        $pendingInvoices = Invoice::query()->where('status', 'pending_review')->count();

        $recentWithdrawals = WithdrawalRequest::query()
            ->with('plumber:id,name,phone')
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn (WithdrawalRequest $w) => [
                'id' => $w->id,
                'user' => $w->plumber?->name,
                'phone' => $w->plumber?->phone,
                'amount' => $w->formattedAmount(),
                'status' => $w->statusLabel(),
                'created_at' => optional($w->created_at)->format('Y/m/d'),
            ])
            ->all();

        return [
            'summary' => [
                'withdrawals_pending' => $pending,
                'withdrawals_paid' => $paid,
                'withdrawals_rejected' => $rejected,
                'pending_amount_lyd' => round($pendingCents / 100, 2),
                'paid_amount_lyd' => round($paidCents / 100, 2),
                'wallet_points_total' => $walletPoints,
                'wallet_cents_total' => $walletCents,
                'invoices_pending_review' => $pendingInvoices,
            ],
            'recent_withdrawals' => $recentWithdrawals,
        ];
    }

    /** @return array<string, mixed> */
    protected function inventoryHighlights(): array
    {
        // Top products by wholesale stock points (approx via open invoice items not fully distributed)
        $top = Product::query()
            ->with('translations')
            ->where('points_per_unit', '>', 0)
            ->orderByDesc('points_per_unit')
            ->limit(12)
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => localized_name($p, 'name', 'منتج #'.$p->id),
                'points_per_unit' => (float) $p->points_per_unit,
                'product_kind' => match ($p->productKind()) {
                    'mg_points' => 'MG بنقاط',
                    'mg_no_points' => 'MG بدون نقاط',
                    default => 'متجر',
                },
            ])
            ->all();

        return [
            'top_point_products' => $top,
            'catalog_total' => Product::query()->count(),
            'with_points' => Product::query()->where('points_per_unit', '>', 0)->count(),
            'without_points' => Product::query()->where(function ($q) {
                $q->whereNull('points_per_unit')->orWhere('points_per_unit', '<=', 0);
            })->count(),
        ];
    }

    /** @return list<array{level:string,title:string,body:string}> */
    protected function decisionAlerts(\DateTimeInterface $from): array
    {
        $alerts = [];

        $openOrders = Order::query()->where('status', OrderStatus::PLACED)->count();
        if ($openOrders > 0) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'طلبيات بانتظار التأكيد',
                'body' => "يوجد {$openOrders} طلبية بحالة «بانتظار التأكيد» — راجع الجملة/القطاعي.",
            ];
        }

        $pendingWd = WithdrawalRequest::query()->where('status', 'pending')->count();
        if ($pendingWd > 0) {
            $alerts[] = [
                'level' => 'danger',
                'title' => 'سحوبات تحتاج موافقة',
                'body' => "{$pendingWd} طلب سحب معلّق يؤثر على ثقة السباكين.",
            ];
        }

        $unlinked = User::query()->where('role', 'plumber')->whereNull('parent_distributor_id')->count();
        if ($unlinked > 0) {
            $alerts[] = [
                'level' => 'info',
                'title' => 'سباكون بدون محل',
                'body' => "{$unlinked} سباك غير مرتبط بتاجر قطاعي — فرصة نمو للشبكة.",
            ];
        }

        $pendingInv = Invoice::query()->where('status', 'pending_review')->count();
        if ($pendingInv > 0) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'فواتير قيد المراجعة',
                'body' => "{$pendingInv} فاتورة تنتظر اعتماد الإدارة.",
            ];
        }

        if ($alerts === []) {
            $alerts[] = [
                'level' => 'success',
                'title' => 'لا تنبيهات حرجة',
                'body' => 'المؤشرات ضمن النطاق الطبيعي للفترة الحالية.',
            ];
        }

        return $alerts;
    }

    /** @return array{labels:list<string>, orders:list<int>, deliveries:list<int>} */
    protected function dailyTrends(\DateTimeInterface $from): array
    {
        $labels = [];
        $orders = [];
        $deliveries = [];

        for ($d = \Illuminate\Support\Carbon::instance($from)->copy(); $d->lte(now()); $d->addDay()) {
            $day = $d->toDateString();
            $labels[] = $d->format('m/d');
            $orders[] = Order::query()->whereDate('created_at', $day)->count();
            $deliveries[] = Order::query()
                ->where('status', OrderStatus::DELIVERED)
                ->whereDate('delivered_at', $day)
                ->count();
        }

        // Cap to last 14 points for readable bars if window is long
        if (count($labels) > 14) {
            $labels = array_slice($labels, -14);
            $orders = array_slice($orders, -14);
            $deliveries = array_slice($deliveries, -14);
        }

        return compact('labels', 'orders', 'deliveries');
    }
}
