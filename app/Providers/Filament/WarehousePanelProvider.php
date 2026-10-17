<?php

namespace App\Providers\Filament;

use App\Http\Middleware\AuthenticatePanel;
use App\Http\Middleware\EnsureCentralWarehouseAccess;
use Filament\FontProviders\GoogleFontProvider;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Central invitation warehouse panel — مخزن الدعوة
 * Role: wholesale_distributor + is_central_warehouse
 */
class WarehousePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('warehouse')
            ->path('warehouse')
            ->brandName(fn () => auth()->user()?->brand_name ?: 'مخزن الدعوة')
            ->brandLogo(fn () => auth()->user()?->storeBrandImageUrl() ?: asset('images/logo-light.png'))
            ->brandLogoHeight('3.5rem')
            ->login(\App\Filament\Auth\Login::class)
            ->colors([
                'primary' => Color::hex('#0f766e'),
                'success' => Color::hex('#059669'),
                'warning' => Color::hex('#d97706'),
                'danger' => Color::hex('#dc2626'),
                'info' => Color::hex('#0891b2'),
                'gray' => Color::Zinc,
            ])
            ->font('Cairo', provider: GoogleFontProvider::class)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('240px')
            ->maxContentWidth('full')
            ->databaseNotifications()
            ->navigationGroups([
                'لوحة المخزن',
                'الطلبيات والمصنع',
                'المخزون والنقاط',
                'الشبكة',
                'واجهة المحل',
            ])
            ->resources([
                \App\Filament\Distributor\Resources\DistributorOrderResource::class,
                \App\Filament\Distributor\Resources\DistributorInvoiceResource::class,
                \App\Filament\Distributor\Resources\DistributorDistributionResource::class,
                \App\Filament\Warehouse\Resources\WarehouseWholesalerResource::class,
                \App\Filament\Distributor\Resources\DistributorRetailTraderResource::class,
                \App\Filament\Warehouse\Resources\WarehouseReturnResource::class,
                \App\Filament\Distributor\Resources\DistributorMyStoreResource::class,
            ])
            ->pages([
                \App\Filament\Warehouse\Pages\WarehouseDashboard::class,
                \App\Filament\Warehouse\Pages\WarehouseReports::class,
                \App\Filament\Distributor\Pages\DistributorPos::class,
                // MyProducts (non-MG shop media) intentionally omitted — warehouse is MG-only.
            ])
            ->renderHook('panels::head.end', fn () => view('filament.hooks.admin-styles'))
            ->renderHook('panels::topbar.start', fn () => view('filament.hooks.warehouse-brand-bar'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                AuthenticatePanel::class,
                EnsureCentralWarehouseAccess::class,
            ]);
    }
}
