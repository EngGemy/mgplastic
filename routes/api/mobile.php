<?php

/**
 * MG Plastic — Mobile API v1
 *
 * Organized endpoints for plumber, retail trader, wholesale distributor mobile apps.
 * Interactive docs: GET /docs/api (Scramble OpenAPI)
 */

use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Distributor\DashboardController as DistributorDashboardController;
use App\Http\Controllers\Api\Distributor\PosController as DistributorPosController;
use App\Http\Controllers\Api\Distributor\RetailTraderController;
use App\Http\Controllers\Api\Mobile\NotificationController;
use App\Http\Controllers\Api\Mobile\ProfileController;
use App\Http\Controllers\Api\Mobile\SettingsController;
use App\Http\Controllers\Api\Plumber\DashboardController as PlumberDashboardController;
use App\Http\Controllers\Api\Plumber\WalletApiController;
use App\Http\Controllers\Api\Plumber\WalletController;
use App\Http\Controllers\Api\Plumber\WithdrawalController;
use App\Http\Controllers\Api\Distributor\OrderController as DistributorOrderController;
use App\Http\Controllers\Api\Trader\DashboardController as TraderDashboardController;
use App\Http\Controllers\Api\Trader\OrderController as TraderOrderController;
use App\Http\Controllers\Api\Trader\PlumberController as TraderPlumberController;
use App\Http\Controllers\Api\Trader\PosController as TraderPosController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/mobile')->group(function () {

    // ── Public app settings ─────────────────────────────────────
    Route::get('settings', [SettingsController::class, 'app']);

    // ── Authenticated (any role) ────────────────────────────────
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('me', [SessionController::class, 'me']);
        Route::post('logout', [SessionController::class, 'logout']);
        Route::post('logout-all', [SessionController::class, 'logoutAll']);

        Route::get('profile', [ProfileController::class, 'show']);
        Route::match(['put', 'patch', 'post'], 'profile', [ProfileController::class, 'update']);

        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('read-all', [NotificationController::class, 'markAllRead']);
            Route::post('{id}/read', [NotificationController::class, 'markRead']);
        });
    });

    // ── Plumber ─────────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'role.api:plumber'])->prefix('plumber')->group(function () {
        Route::get('dashboard', PlumberDashboardController::class);

        Route::prefix('wallet')->group(function () {
            Route::get('/', [WalletApiController::class, 'show']);
            Route::get('transactions', [WalletApiController::class, 'transactions']);
            Route::get('conversion-rules', [WalletApiController::class, 'conversionRules']);
            Route::post('convert', [WalletController::class, 'convert']);
        });

        Route::get('withdrawals', [WithdrawalController::class, 'index']);
        Route::get('withdrawals/{withdrawal}', [WithdrawalController::class, 'show']);
        Route::post('withdrawals', [WalletController::class, 'requestWithdrawal']);
    });

    // ── Retail trader (تاجر التجزئة) ───────────────────────────
    Route::middleware(['auth:sanctum', 'role.api:retail_trader'])->prefix('trader')->group(function () {
        Route::get('dashboard', TraderDashboardController::class);

        Route::get('plumbers', [TraderPlumberController::class, 'index']);
        Route::get('plumbers/{plumber}', [TraderPlumberController::class, 'show']);

        Route::get('pos/stock', [TraderPosController::class, 'stock']);
        Route::post('pos/checkout', [TraderPosController::class, 'checkout']);

        // Orders placed to the wholesale distributor
        Route::get('orders', [TraderOrderController::class, 'index']);
        Route::post('orders', [TraderOrderController::class, 'store']);
        Route::get('orders/{order}', [TraderOrderController::class, 'show']);
        Route::post('orders/{order}/receive', [TraderOrderController::class, 'receive']);
        Route::post('orders/{order}/cancel', [TraderOrderController::class, 'cancel']);
    });

    // ── Wholesale distributor (موزع الجملة) ────────────────────
    Route::middleware(['auth:sanctum', 'role.api:wholesale_distributor'])->prefix('distributor')->group(function () {
        Route::get('dashboard', DistributorDashboardController::class);

        Route::get('retail-traders', [RetailTraderController::class, 'index']);
        Route::get('retail-traders/{retailTrader}', [RetailTraderController::class, 'show']);

        Route::get('pos/stock', [DistributorPosController::class, 'stock']);
        Route::post('pos/checkout', [DistributorPosController::class, 'checkout']);

        // Orders — place to factory + fulfil retail-trader orders
        Route::get('orders', [DistributorOrderController::class, 'index']);
        Route::post('orders', [DistributorOrderController::class, 'store']);
        Route::get('orders/{order}', [DistributorOrderController::class, 'show']);
        Route::post('orders/{order}/confirm', [DistributorOrderController::class, 'confirm']);
        Route::post('orders/{order}/ship', [DistributorOrderController::class, 'ship']);
        Route::post('orders/{order}/reject', [DistributorOrderController::class, 'reject']);
        Route::post('orders/{order}/receive', [DistributorOrderController::class, 'receive']);
        Route::post('orders/{order}/cancel', [DistributorOrderController::class, 'cancel']);
    });
});
