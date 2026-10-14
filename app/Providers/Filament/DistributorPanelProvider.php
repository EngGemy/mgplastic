<?php

namespace App\Providers\Filament;

use Filament\FontProviders\GoogleFontProvider;
use App\Http\Middleware\AuthenticatePanel;
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

class DistributorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('distributor')
            ->path('distributor')
            ->brandName(fn () => auth()->user()?->brand_name ?: auth()->user()?->name ?: 'MG Plastic')
            ->brandLogo(fn () => auth()->user()?->storeBrandImageUrl() ?: asset('images/logo-light.png'))
            ->brandLogoHeight('3.5rem')
            ->login(\App\Filament\Auth\Login::class)
            ->colors([
                'primary' => Color::hex('#1a56db'),
                'success' => Color::hex('#059669'),
                'warning' => Color::hex('#d97706'),
                'danger' => Color::hex('#dc2626'),
                'info' => Color::hex('#0891b2'),
                'gray' => Color::Zinc,
            ])
            ->font('Cairo', provider: GoogleFontProvider::class)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('230px')
            ->maxContentWidth('full')
            ->databaseNotifications()
            ->resources([
                \App\Filament\Distributor\Resources\DistributorOrderResource::class,
                \App\Filament\Distributor\Resources\DistributorInvoiceResource::class,
                \App\Filament\Distributor\Resources\DistributorDistributionResource::class,
                \App\Filament\Distributor\Resources\DistributorRetailTraderResource::class,
                \App\Filament\Distributor\Resources\DistributorMyStoreResource::class,
            ])
            ->pages([
                \App\Filament\Distributor\Pages\DistributorDashboard::class,
                \App\Filament\Distributor\Pages\DistributorPos::class,
                \App\Filament\Distributor\Pages\MyProducts::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Distributor/Widgets'), for: 'App\\Filament\\Distributor\\Widgets')
            ->renderHook('panels::head.end', fn () => view('filament.hooks.admin-styles'))
            ->renderHook('panels::topbar.start', fn () => view('filament.hooks.distributor-brand-bar'))
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
                \App\Http\Middleware\EnsureWholesaleDistributorAccess::class,
            ]);
    }
}
