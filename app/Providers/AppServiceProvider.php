<?php

namespace App\Providers;

use App\Ai\AdminToolRegistry;
use App\Models\InvoiceDistribution;
use App\Policies\InvoiceDistributionPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Validation\ArabicValidator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        require_once app_path('Helpers/ApiTextHelper.php');
        $this->app->singleton(AdminToolRegistry::class);
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

        // Hard stop: Sanctum must not authenticate deleted or inactive users.
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid) {
            if (! $isValid || ! $accessToken) {
                return false;
            }

            $tokenable = $accessToken->tokenable;

            if (! $tokenable instanceof \App\Models\User) {
                $accessToken->delete();

                return false;
            }

            if (! $tokenable->is_active
                || ! \App\Models\User::query()->whereKey($tokenable->id)->where('is_active', true)->exists()) {
                $accessToken->delete();

                return false;
            }

            return true;
        });

        Validator::resolver(fn ($translator, $data, $rules, $messages, $attributes) => new ArabicValidator($translator, $data, $rules, $messages, $attributes));

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

        RateLimiter::for('ai-gateway', function (Request $request) {
            $perMinute = max(1, (int) config('ai.rate_limit_per_minute', 60));

            return Limit::perMinute($perMinute)->by($request->ip() ?: 'ai-gateway');
        });

        \App\Models\Product::observe(\App\Observers\ProductObserver::class);
        \App\Models\User::observe(\App\Observers\UserObserver::class);
        \App\Models\Invoice::observe(\App\Observers\InvoiceObserver::class);
        \App\Models\InvoiceDistribution::observe(\App\Observers\InvoiceDistributionObserver::class);
        \App\Models\Order::observe(\App\Observers\OrderObserver::class);
        \App\Models\InvoiceReturn::observe(\App\Observers\InvoiceReturnObserver::class);
        \App\Models\WithdrawalRequest::observe(\App\Observers\WithdrawalRequestObserver::class);
    }
}
