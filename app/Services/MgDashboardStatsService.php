<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletAccount;
use App\Services\RetailTraderNetworkService;
use App\Services\WholesalerNetworkService;

class MgDashboardStatsService
{
    /** @return array{welcomeTitle: string, welcomeSubtitle: string, accent: string, cards: array<int, array<string, mixed>>} */
    public function forPanel(string $panelId, ?User $user): array
    {
        return match ($panelId) {
            'distributor' => $this->distributor($user),
            'trader' => $this->trader($user),
            default => $this->admin($user),
        };
    }

    /** @return array{welcomeTitle: string, welcomeSubtitle: string, accent: string, cards: array<int, array<string, mixed>>} */
    private function admin(?User $user): array
    {
        $name = $user?->name ?? 'مدير';

        $usersTotal = User::count();
        $usersThisMonth = User::where('created_at', '>=', now()->startOfMonth())->count();
        $usersLastMonth = User::whereBetween('created_at', [
            now()->subMonth()->startOfMonth(),
            now()->subMonth()->endOfMonth(),
        ])->count();

        $distributors = User::where('role', 'wholesale_distributor')->count();
        $retailTraders = User::where('role', 'retail_trader')->count();
        $plumbers = User::where('role', 'plumber')->count();

        $pendingInvoices = Invoice::where('status', 'pending_review')->count();
        $pendingDistributions = InvoiceDistribution::where('status', 'draft')->count();

        $totalPoints = (int) WalletAccount::sum('balance_points');
        $pointsThisMonth = (int) InvoiceDistribution::query()
            ->where('invoice_distributions.status', 'points_awarded')
            ->where('invoice_distributions.created_at', '>=', now()->startOfMonth())
            ->join('invoice_distribution_items as idi', 'idi.distribution_id', '=', 'invoice_distributions.id')
            ->sum('idi.points_value');

        $products = Product::count();
        $stores = User::where('role', 'wholesale_distributor')->count();

        return [
            'welcomeTitle' => "مرحباً {$name} 👋",
            'welcomeSubtitle' => 'لوحة إدارة MG Plastic — نظرة شاملة على الشبكة والنقاط والفواتير',
            'accent' => 'blue',
            'cards' => [
                $this->card('إجمالي المستخدمين', $usersTotal, 'heroicon-o-users', 'blue', $this->trend($usersThisMonth, $usersLastMonth), "{$retailTraders} قطاعي • {$plumbers} سباك"),
                $this->card('موزعو الجملة', $distributors, 'heroicon-o-building-storefront', 'indigo', null, 'متاجر الشبكة الرئيسية'),
                $this->card('فواتير قيد المراجعة', $pendingInvoices, 'heroicon-o-document-check', 'amber', null, $pendingDistributions.' توزيع مسودة'),
                $this->card('إجمالي النقاط', $totalPoints, 'heroicon-o-star', 'green', ['text' => number_format($pointsThisMonth).' نقطة هذا الشهر', 'positive' => true], 'رصيد السباكين والشبكة'),
                $this->card('المنتجات', $products, 'heroicon-o-cube', 'purple', null, $stores.' موزع جملة'),
            ],
        ];
    }

    /** @return array{welcomeTitle: string, welcomeSubtitle: string, accent: string, cards: array<int, array<string, mixed>>} */
    private function distributor(?User $user): array
    {
        $name = $user?->brand_name ?: ($user?->name ?? 'موزع');
        $summary = app(WholesalerNetworkService::class)->getSummary($user);
        $pending = $this->scopedPendingCount((int) $user?->id);
        $confirmedToday = $this->scopedConfirmedTodayCount((int) $user?->id);
        $remaining = max(0, ($summary['factory_points'] ?? 0) - ($summary['distributed_points'] ?? 0));

        return [
            'welcomeTitle' => "مرحباً {$name} 👋",
            'welcomeSubtitle' => 'لوحة موزع الجملة — مخزونك، نقاطك، وتجار القطاعي',
            'accent' => 'blue',
            'cards' => [
                $this->card('رصيد النقاط', (int) $summary['balance_points'], 'heroicon-o-wallet', 'green', null, 'الرصيد الحالي في محفظتك'),
                $this->card('نقاط من المصنع', (int) $summary['factory_points'], 'heroicon-o-building-office-2', 'blue', null, number_format($remaining).' متبقية للتوزيع'),
                $this->card('موزَّع للقطاعي', (int) $summary['distributed_points'], 'heroicon-o-arrow-trending-up', 'amber', null, 'نقاط مخصومة عند البيع'),
                $this->card('وحدات المخزون', (int) $summary['product_units'], 'heroicon-o-cube', 'teal', null, ($summary['product_types'] ?? 0).' صنف متاح'),
                $this->card('تجار قطاعي', (int) $summary['retail_traders_count'], 'heroicon-o-building-office', 'indigo', null, 'تحت شبكتك'),
                $this->card('توزيعات معلّقة', $pending, 'heroicon-o-clock', $pending > 0 ? 'amber' : 'gray', null, 'مسودات لم تُؤكد'),
                $this->card('مؤكدة اليوم', $confirmedToday, 'heroicon-o-check-circle', 'green', null, 'خلال اليوم الحالي'),
            ],
        ];
    }

    /** @return array{welcomeTitle: string, welcomeSubtitle: string, accent: string, cards: array<int, array<string, mixed>>} */
    private function trader(?User $user): array
    {
        $name = $user?->brand_name ?: ($user?->name ?? 'تاجر');
        $summary = app(RetailTraderNetworkService::class)->getSummary($user);
        $pending = $this->scopedPendingCount((int) $user?->id);
        $confirmedToday = $this->scopedConfirmedTodayCount((int) $user?->id);
        $remaining = max(0, ($summary['received_points'] ?? 0) - ($summary['distributed_points'] ?? 0));

        return [
            'welcomeTitle' => "مرحباً {$name} 👋",
            'welcomeSubtitle' => 'لوحة التاجر القطاعي — مخزونك، سبّاكوك، وتوزيع النقاط',
            'accent' => 'green',
            'cards' => [
                $this->card('رصيد النقاط', (int) $summary['balance_points'], 'heroicon-o-wallet', 'green', null, 'الرصيد الحالي في محفظتك'),
                $this->card('نقاط من الجملة', (int) $summary['received_points'], 'heroicon-o-building-storefront', 'blue', null, number_format($remaining).' لم تُوزَّع بعد'),
                $this->card('موزَّع للسباكين', (int) $summary['distributed_points'], 'heroicon-o-wrench-screwdriver', 'amber', null, 'نقاط وصلت للسباكين'),
                $this->card('وحدات المخزون', (int) $summary['product_units'], 'heroicon-o-cube', 'teal', null, 'متاحة للبيع للسباك'),
                $this->card('السبّاكون', (int) $summary['plumbers_count'], 'heroicon-o-user-group', 'indigo', null, 'تحت شبكتك'),
                $this->card('توزيعات معلّقة', $pending, 'heroicon-o-clock', $pending > 0 ? 'amber' : 'gray', null, 'مسودات لم تُؤكد'),
                $this->card('مؤكدة اليوم', $confirmedToday, 'heroicon-o-check-circle', 'green', null, 'خلال اليوم الحالي'),
            ],
        ];
    }

    /** @return array{label: string, value: int|string, icon: string, color: string, trend: ?array{text: string, positive: bool}, sub: string} */
    private function card(string $label, int|string $value, string $icon, string $color, ?array $trend, string $sub): array
    {
        return compact('label', 'value', 'icon', 'color', 'trend', 'sub');
    }

    /** @return array{text: string, positive: bool} */
    private function trend(int $current, int $previous): array
    {
        if ($previous === 0) {
            return [
                'text' => $current > 0 ? "+{$current} جديد هذا الشهر" : 'بدون تغيير',
                'positive' => $current >= $previous,
            ];
        }

        $pct = (int) round((($current - $previous) / $previous) * 100);

        return [
            'text' => ($pct >= 0 ? '+' : '')."{$pct}% عن الشهر الماضي",
            'positive' => $pct >= 0,
        ];
    }

    private function scopedPendingCount(int $userId): int
    {
        if ($userId === 0) {
            return 0;
        }

        return InvoiceDistribution::query()
            ->where('status', 'draft')
            ->where(fn ($q) => $q
                ->where('from_user_id', $userId)
                ->orWhere('to_user_id', $userId)
            )
            ->count();
    }

    private function scopedConfirmedTodayCount(int $userId): int
    {
        if ($userId === 0) {
            return 0;
        }

        return InvoiceDistribution::query()
            ->where('status', 'confirmed')
            ->whereDate('confirmed_at', today())
            ->where(fn ($q) => $q
                ->where('from_user_id', $userId)
                ->orWhere('to_user_id', $userId)
            )
            ->count();
    }
}
