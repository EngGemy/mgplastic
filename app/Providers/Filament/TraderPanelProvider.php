<?php

namespace App\Providers\Filament;

use Filament\FontProviders\GoogleFontProvider;
use Filament\Http\Middleware\Authenticate;
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

class TraderPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('trader')
            ->path('trader')
            ->brandName('MG Plastic')
            ->brandLogo(asset('images/logo-light.png'))
            ->brandLogoHeight('4rem')
            ->login()
            ->colors([
                'primary' => Color::hex('#059669'),
                'success' => Color::hex('#059669'),
                'warning' => Color::hex('#d97706'),
                'danger' => Color::hex('#dc2626'),
                'gray' => Color::Zinc,
            ])
            ->font('Cairo', provider: GoogleFontProvider::class)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('230px')
            ->maxContentWidth('full')
            ->databaseNotifications()
            ->resources([
                \App\Filament\Trader\Resources\TraderOrderResource::class,
                \App\Filament\Trader\Resources\TraderInvoiceResource::class,
                \App\Filament\Trader\Resources\TraderDistributionResource::class,
                \App\Filament\Trader\Resources\TraderMyStoreResource::class,
            ])
            ->pages([
                \App\Filament\Trader\Pages\TraderDashboard::class,
                \App\Filament\Trader\Pages\TraderPos::class,
            ])
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
                Authenticate::class,
                \App\Http\Middleware\EnsureRetailTraderAccess::class,
            ]);
    }
}
