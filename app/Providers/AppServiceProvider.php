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
        //
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
                ->locales(['ar','en','fr']); // also accepts a closure
        });
        Schema::defaultStringLength(191);

        \App\Models\Product::observe(\App\Observers\ProductObserver::class);
    }
}
