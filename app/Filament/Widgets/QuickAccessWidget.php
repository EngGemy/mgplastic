<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ProductsReport;
use App\Filament\Resources\BlogCategoryResource;
use App\Filament\Resources\BlogResource;
use App\Filament\Resources\CityResource;
use App\Filament\Resources\ClaimResource;
use App\Filament\Resources\ConversionRuleResource;
use App\Filament\Resources\CountryResource;
use App\Filament\Resources\EventCategoryResource;
use App\Filament\Resources\EventResource;
use App\Filament\Resources\InvoiceDistributionResource;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Distributor\Resources\DistributorMyStoreResource;
use App\Filament\Support\NetworkPanelUrls;
use App\Filament\Trader\Resources\TraderMyStoreResource;
use App\Filament\Resources\PrivacyResource;
use App\Filament\Resources\ProductCategoryResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductVariantResource;
use App\Filament\Resources\SizeSystemResource;
use App\Filament\Resources\SliderResource;
use App\Filament\Resources\SocialMediaResource;
use App\Filament\Resources\StoreResource;
use App\Filament\Resources\SystemLabelResource;
use App\Filament\Resources\TermsConditionResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\WithdrawalRequestResource;
use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\WalletAccount;
use App\Models\WithdrawalRequest;
use App\Services\WholesalerNetworkService;
use Filament\Widgets\Widget;

class QuickAccessWidget extends Widget
{
    protected static string $view = 'filament.widgets.quick-access';

    protected static bool $isDiscovered = false;

    protected static ?int $sort = -100;

    protected int|string|array $columnSpan = 'full';

    public function getViewData(): array
    {
        $user = auth()->user();
        $wallet = null;
        $recentDistributions = collect();

        if ($user && in_array($user->role, ['wholesale_distributor', 'retail_trader'], true)) {
            $wallet = WalletAccount::where('owner_id', $user->id)
                ->where('currency', 'LYD')
                ->first();

            $recentDistributions = InvoiceDistribution::query()
                ->where('to_user_id', $user->id)
                ->whereIn('status', ['confirmed', 'points_awarded'])
                ->with(['invoice:id,number', 'items'])
                ->latest('confirmed_at')
                ->take(5)
                ->get()
                ->map(fn ($d) => [
                    'invoice_number' => $d->invoice->number ?? '—',
                    'points' => $d->items->sum('points_value'),
                    'date' => $d->confirmed_at?->format('d/m'),
                    'status' => $d->status,
                ]);
        }

        $isNetworkUser = $user && in_array($user->role, ['wholesale_distributor', 'retail_trader'], true);
        $wholesalerSummary = null;

        if ($user?->isWholesaleDistributor()) {
            $wholesalerSummary = app(WholesalerNetworkService::class)->getSummary($user);
        }

        $pointsBanner = null;
        if ($user?->role === 'wholesale_distributor' && $wholesalerSummary) {
            $remaining = ($wholesalerSummary['factory_points'] ?? 0) - ($wholesalerSummary['distributed_points'] ?? 0);
            $pointsBanner = [
                'type' => 'wholesaler',
                'total' => $wholesalerSummary['factory_points'] ?? 0,
                'distributed' => $wholesalerSummary['distributed_points'] ?? 0,
                'remaining' => max(0, $remaining),
                'percent' => ($wholesalerSummary['factory_points'] ?? 0) > 0
                    ? round(($wholesalerSummary['distributed_points'] / $wholesalerSummary['factory_points']) * 100)
                    : 0,
            ];
        } elseif ($user?->role === 'retail_trader') {
            $received = (int) InvoiceDistribution::where('to_user_id', $user->id)
                ->where('tier', 2)->whereIn('status', ['confirmed', 'points_awarded'])
                ->join('invoice_distribution_items as idi', 'idi.distribution_id', '=', 'invoice_distributions.id')
                ->sum('idi.points_value');
            $sentToPlumbers = (int) InvoiceDistribution::where('from_user_id', $user->id)
                ->where('tier', 3)->whereIn('status', ['confirmed', 'points_awarded'])
                ->join('invoice_distribution_items as idi', 'idi.distribution_id', '=', 'invoice_distributions.id')
                ->sum('idi.points_value');
            $pointsBanner = [
                'type' => 'trader',
                'received' => $received,
                'sent_to_plumbers' => $sentToPlumbers,
                'remaining' => max(0, $received - $sentToPlumbers),
                'percent' => $received > 0 ? round(($sentToPlumbers / $received) * 100) : 0,
            ];
        }

        return [
            'title' => slabel('quick_access_title', 'الوصول السريع'),
            'subtitle' => $isNetworkUser
                ? 'الاختصارات المتاحة لحسابك فقط'
                : 'اضغط على أي مربع للانتقال مباشرة — خط كبير لسهولة القراءة',
            'sections' => $this->getSections($wholesalerSummary),
            'wallet' => $wallet,
            'recentDistributions' => $recentDistributions,
            'user' => $user,
            'wholesalerSummary' => $wholesalerSummary,
            'pointsBanner' => $pointsBanner,
        ];
    }

    private function link(
        string $label,
        string $sub,
        string $icon,
        string $color,
        string $url,
        ?int $badge = null,
        string $badgeColor = 'primary',
    ): array {
        return compact('label', 'sub', 'icon', 'color', 'url', 'badge', 'badgeColor');
    }

    /** @param  array<int, array|null>  $links */
    private function section(string $title, array $links): ?array
    {
        $visible = array_values(array_filter($links));

        if ($visible === []) {
            return null;
        }

        return [
            'title' => $title,
            'links' => $visible,
        ];
    }

    /** @return array{invoices: int, distributions: int, withdrawals: int} */
    private function badgeCounts(): array
    {
        $user = auth()->user();

        if (! $user) {
            return ['invoices' => 0, 'distributions' => 0, 'withdrawals' => 0];
        }

        if (in_array($user->role, ['super_admin', 'admin'], true)) {
            return [
                'invoices' => Invoice::where('status', 'pending_review')->count(),
                'distributions' => InvoiceDistribution::where('status', 'draft')->count(),
                'withdrawals' => WithdrawalRequest::pendingCount(),
            ];
        }

        $scopedDistributions = InvoiceDistribution::query()
            ->where('status', 'draft')
            ->where(fn ($q) => $q
                ->where('from_user_id', $user->id)
                ->orWhere('to_user_id', $user->id)
            )
            ->count();

        return [
            'invoices' => 0,
            'distributions' => $scopedDistributions,
            'withdrawals' => 0,
        ];
    }

    private function isAdmin(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin'], true);
    }

    private function myStoreQuickLink(): ?array
    {
        $panelId = filament()->getCurrentPanel()?->getId();

        $url = match ($panelId) {
            'distributor' => DistributorMyStoreResource::canViewAny()
                ? DistributorMyStoreResource::getUrl('index')
                : null,
            'trader' => TraderMyStoreResource::canViewAny()
                ? TraderMyStoreResource::getUrl('index')
                : null,
            default => null,
        };

        if (! $url) {
            return null;
        }

        return $this->link(
            'متجري والكتالوج',
            'الصور، العنوان، السوشيال ميديا',
            'heroicon-o-photo',
            'purple',
            $url,
        );
    }

    private function isAdminPanel(): bool
    {
        return NetworkPanelUrls::panelId() === 'admin';
    }

    private function adminUrl(string $resourceClass, string $name = 'index', array $parameters = []): string
    {
        return NetworkPanelUrls::adminResourceUrl($resourceClass, $name, $parameters);
    }

    private function getSections(?array $wholesalerSummary = null): array
    {
        $badges = $this->badgeCounts();
        $invoiceSub = $this->isAdmin() ? 'مراجعة واعتماد' : 'فواتيري والتوزيع';
        $invoiceBadge = $this->isAdmin()
            ? ($badges['invoices'] ?: null)
            : ($wholesalerSummary['product_units'] ?? null);

        $sections = [
            $this->section('⭐ نظام النقاط والفواتير', [
                InvoiceResource::canViewAny()
                    ? $this->link(
                        'الفواتير',
                        $invoiceSub,
                        'heroicon-o-document-check',
                        'blue',
                        NetworkPanelUrls::invoiceIndex(),
                        $invoiceBadge,
                        $this->isAdmin() ? 'danger' : 'info',
                    )
                    : null,

                ($url = NetworkPanelUrls::posWholesale())
                    ? $this->link(
                        'فاتورة وارد — مصنع',
                        'إصدار فاتورة وارد لموزع جملة',
                        'heroicon-o-arrow-down-circle',
                        'emerald',
                        $url,
                    )
                    : null,

                ($url = NetworkPanelUrls::posRetail())
                    ? $this->link(
                        'بيع للقطاعي',
                        'فاتورة صادر من مخزونك',
                        'heroicon-o-arrow-up-circle',
                        'teal',
                        $url,
                    )
                    : null,

                ($url = NetworkPanelUrls::posPlumber())
                    ? $this->link(
                        'بيع للسباك',
                        'توزيع نقاط من مخزونك',
                        'heroicon-o-wrench-screwdriver',
                        'amber',
                        $url,
                    )
                    : null,

                InvoiceDistributionResource::canViewAny()
                    ? $this->link(
                        'التوزيعات',
                        'سلسلة توزيع النقاط',
                        'heroicon-o-arrows-pointing-out',
                        'green',
                        NetworkPanelUrls::distributionIndex(),
                        $badges['distributions'] ?: null,
                        'warning',
                    )
                    : null,

                WithdrawalRequestResource::canViewAny()
                    ? $this->link(
                        'طلبات السحب',
                        'موافقة وصرف',
                        'heroicon-o-banknotes',
                        'purple',
                        WithdrawalRequestResource::getUrl('index'),
                        $badges['withdrawals'] ?: null,
                        'warning',
                    )
                    : null,

                ProductsReport::canAccess()
                    ? $this->link(
                        'تقرير النقاط',
                        'كل منتج وتحويله',
                        'heroicon-o-presentation-chart-bar',
                        'amber',
                        ProductsReport::getUrl(),
                    )
                    : null,

                ConversionRuleResource::canViewAny()
                    ? $this->link(
                        'إعدادات الصرف',
                        'فترة الصرف والإشعارات',
                        'heroicon-o-bell-alert',
                        'indigo',
                        ConversionRuleResource::getUrl('index'),
                    )
                    : null,
            ]),

            $this->section('🏪 متجري والكتالوج', [
                $this->myStoreQuickLink(),
            ]),

            $this->section('🏪 تجار القطاعي', [
                ($url = NetworkPanelUrls::retailTraderIndex())
                    ? $this->link(
                        'تجار القطاعي',
                        'قائمة التجار التابعين لك',
                        'heroicon-o-building-office',
                        'blue',
                        $url,
                        $wholesalerSummary['retail_traders_count'] ?? null,
                        'primary',
                    )
                    : null,
                ($url = NetworkPanelUrls::retailTraderCreate())
                    ? $this->link(
                        'إضافة تاجر قطاعي',
                        'تسجيل تاجر جديد تحت متجرك',
                        'heroicon-o-user-plus',
                        'teal',
                        $url,
                    )
                    : null,
            ]),
        ];

        if (! $this->isAdminPanel()) {
            return array_values(array_filter($sections));
        }

        $sections = array_merge($sections, [
            $this->section('🏪 المتاجر والشبكة', [
                StoreResource::canViewAny()
                    ? $this->link('المتاجر', 'موزعو الجملة', 'heroicon-o-building-storefront', 'teal', $this->adminUrl(StoreResource::class))
                    : null,
                StoreResource::canCreate()
                    ? $this->link('إضافة متجر', 'متجر جملة جديد', 'heroicon-o-plus-circle', 'teal', $this->adminUrl(StoreResource::class, 'create'))
                    : null,
            ]),

            $this->section('📦 المنتجات', [
                ProductResource::canViewAny()
                    ? $this->link('المنتجات', 'إدارة الكتالوج', 'heroicon-o-cube', 'amber', $this->adminUrl(ProductResource::class))
                    : null,
                ProductResource::canCreate()
                    ? $this->link('إضافة منتج', 'منتج جديد + نقاط', 'heroicon-o-plus', 'amber', $this->adminUrl(ProductResource::class, 'create'))
                    : null,
                ProductCategoryResource::canViewAny()
                    ? $this->link('فئات المنتجات', 'التصنيفات', 'heroicon-o-tag', 'pink', $this->adminUrl(ProductCategoryResource::class))
                    : null,
                ProductVariantResource::canViewAny()
                    ? $this->link('متغيرات المنتج', 'الألوان والمقاسات', 'heroicon-o-squares-2x2', 'pink', $this->adminUrl(ProductVariantResource::class))
                    : null,
                SizeSystemResource::canViewAny()
                    ? $this->link('أنظمة المقاسات', 'US / EU ...', 'heroicon-o-adjustments-horizontal', 'pink', $this->adminUrl(SizeSystemResource::class))
                    : null,
            ]),

            $this->section('👥 المستخدمون والدعم', [
                UserResource::canViewAny()
                    ? $this->link('المستخدمون', 'سباكون وموزعون', 'heroicon-o-users', 'indigo', $this->adminUrl(UserResource::class))
                    : null,
                ClaimResource::canViewAny()
                    ? $this->link('الشكاوى', 'رسائل التواصل', 'heroicon-o-chat-bubble-left-right', 'red', $this->adminUrl(ClaimResource::class))
                    : null,
            ]),

            $this->section('📰 المحتوى', [
                BlogResource::canViewAny()
                    ? $this->link('المقالات', 'المدونة', 'heroicon-o-newspaper', 'green', $this->adminUrl(BlogResource::class))
                    : null,
                BlogCategoryResource::canViewAny()
                    ? $this->link('تصنيفات المقالات', 'أقسام المدونة', 'heroicon-o-folder', 'green', $this->adminUrl(BlogCategoryResource::class))
                    : null,
                EventResource::canViewAny()
                    ? $this->link('الفعاليات', 'أحداث ومعارض', 'heroicon-o-calendar-days', 'purple', $this->adminUrl(EventResource::class))
                    : null,
                EventCategoryResource::canViewAny()
                    ? $this->link('تصنيفات الفعاليات', 'أنواع الفعاليات', 'heroicon-o-folder-open', 'purple', $this->adminUrl(EventCategoryResource::class))
                    : null,
                SliderResource::canViewAny()
                    ? $this->link('الشرائح', 'Sliders الرئيسية', 'heroicon-o-photo', 'blue', $this->adminUrl(SliderResource::class))
                    : null,
            ]),

            $this->section('⚙️ الإعدادات', [
                SystemLabelResource::canViewAny()
                    ? $this->link('المسميات', 'تخصيص النظام', 'heroicon-o-language', 'red', $this->adminUrl(SystemLabelResource::class))
                    : null,
                CountryResource::canViewAny()
                    ? $this->link('الدول', 'قائمة الدول', 'heroicon-o-globe-alt', 'gray', $this->adminUrl(CountryResource::class))
                    : null,
                CityResource::canViewAny()
                    ? $this->link('المدن', 'قائمة المدن', 'heroicon-o-map-pin', 'gray', $this->adminUrl(CityResource::class))
                    : null,
                TermsConditionResource::canViewAny()
                    ? $this->link('الشروط والأحكام', 'نصوص قانونية', 'heroicon-o-document-text', 'gray', $this->adminUrl(TermsConditionResource::class))
                    : null,
                PrivacyResource::canViewAny()
                    ? $this->link('سياسة الخصوصية', 'Privacy', 'heroicon-o-shield-check', 'gray', $this->adminUrl(PrivacyResource::class))
                    : null,
                SocialMediaResource::canViewAny()
                    ? $this->link('وسائل التواصل', 'روابط Social', 'heroicon-o-share', 'gray', $this->adminUrl(SocialMediaResource::class))
                    : null,
            ]),
        ]);

        return array_values(array_filter($sections));
    }
}
