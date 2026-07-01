<?php

namespace App\Filament\Widgets;

use App\Models\Blog;
use App\Models\Event;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class KpiStats extends BaseWidget
{
    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin'], true);
    }

    // Localized heading
    protected function getHeading(): ?string
    {
        return __('Overview');
    }

    protected function getStats(): array
    {
        $usersTotal = User::count();
        $vendors    = User::where('role', User::ROLE_VENDOR)->count();
        $plumbers   = User::where('role', User::ROLE_PLUMBER)->count();
        $stores     = User::where('role', 'wholesale_distributor')->count();
        $products   = Product::count();
        $categories = ProductCategory::count();
        $blogs      = Blog::count();
        $events     = Event::count();

        return [
            Stat::make(__('Users'), number_format($usersTotal))
                ->description(__('Vendors :vendors • Plumbers :plumbers', [
                    'vendors'  => number_format($vendors),
                    'plumbers' => number_format($plumbers),
                ]))
                ->icon('heroicon-o-users'),

            Stat::make(__('Stores'), number_format($stores))
                ->icon('heroicon-o-building-storefront'),

            Stat::make(__('Products'), number_format($products))
                ->description(__('Categories :count', ['count' => number_format($categories)]))
                ->icon('heroicon-o-cube'),

            Stat::make(__('Blogs'), number_format($blogs))
                ->icon('heroicon-o-newspaper'),

            Stat::make(__('Events'), number_format($events))
                ->icon('heroicon-o-calendar-days'),
        ];
    }
}
