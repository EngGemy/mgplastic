<?php

namespace App\Providers;

use App\Models\InvoiceDistribution;
use App\Policies\InvoiceDistributionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        require_once app_path('Helpers/ApiTextHelper.php');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            config(['app.debug' => false]);
        }

        Gate::policy(InvoiceDistribution::class, InvoiceDistributionPolicy::class);

        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
            $switch
                ->locales(['ar', 'en', 'fr'])
                ->displayLocale('ar')
                ->userPreferredLocale('ar');
        });

        // Filament sidebar follows locale direction (ar = RTL / right side).
        \Filament\Facades\Filament::serving(function () {
            $preferred = session('locale')
                ?? request()->cookie('filament_language_switch_locale');

            if (! is_string($preferred) || ! in_array($preferred, ['ar', 'en', 'fr'], true)) {
                app()->setLocale('ar');
                session(['locale' => 'ar']);
            }
        });
        Schema::defaultStringLength(191);

        \App\Models\Product::observe(\App\Observers\ProductObserver::class);
        \App\Models\User::observe(\App\Observers\UserObserver::class);
    }
}
