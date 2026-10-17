<?php

/**
 * MG Plastic — Mobile API v1
 *
 * Organized endpoints for plumber, retail trader, wholesale distributor mobile apps.
 * Interactive docs: GET /docs/api (Scramble OpenAPI)
 */

use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Distributor\DashboardController as DistributorDashboardController;
use App\Http\Controllers\Api\Distributor\InvoiceReturnController as DistributorInvoiceReturnController;
use App\Http\Controllers\Api\Distributor\NetworkPlumberController as DistributorNetworkPlumberController;
use App\Http\Controllers\Api\Distributor\OrderController as DistributorOrderController;
use App\Http\Controllers\Api\Distributor\PosController as DistributorPosController;
use App\Http\Controllers\Api\Distributor\RetailTraderController;
use App\Http\Controllers\Api\Store\NetworkStoreController;
use App\Http\Controllers\Api\Ios\IosWalletVisibilityController;
use App\Http\Controllers\Api\Mobile\NotificationController;
use App\Http\Controllers\Api\Mobile\PointsPanelController;
use App\Http\Controllers\Api\Mobile\ProfileController;
use App\Http\Controllers\Api\Mobile\SettingsController;
use App\Http\Controllers\Api\Mobile\SuggestController;
use App\Http\Controllers\Api\Plumber\DashboardController as PlumberDashboardController;
use App\Http\Controllers\Api\Plumber\InvoiceReturnController as PlumberInvoiceReturnController;
use App\Http\Controllers\Api\Plumber\OrderController as PlumberOrderController;
use App\Http\Controllers\Api\Plumber\ProductCatalogController as PlumberProductCatalogController;
use App\Http\Controllers\Api\Plumber\WalletApiController;
use App\Http\Controllers\Api\Plumber\WalletController;
use App\Http\Controllers\Api\Plumber\WithdrawalController;
use App\Http\Controllers\Api\Trader\DashboardController as TraderDashboardController;
use App\Http\Controllers\Api\Trader\InvoiceReturnController as TraderInvoiceReturnController;
use App\Http\Controllers\Api\Trader\OrderController as TraderOrderController;
use App\Http\Controllers\Api\Trader\PlumberController as TraderPlumberController;
use App\Http\Controllers\Api\Trader\PosController as TraderPosController;
use App\Http\Controllers\Api\Trader\ProductCatalogController as TraderProductCatalogController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/mobile')->group(function () {

    // ── Public app settings ─────────────────────────────────────
    Route::get('settings', [SettingsController::class, 'app']);

    // Wallet visibility — apps read this to hide/show the wallet UI
    Route::get('wallet-visibility', [IosWalletVisibilityController::class, 'show']);

    // Admin-only toggle (Bearer token of super_admin / admin)
    Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
        Route::match(['put', 'post'], 'wallet-visibility', [IosWalletVisibilityController::class, 'update']);
        Route::post('wallet-visibility/toggle', [IosWalletVisibilityController::class, 'toggle']);
    });

    // ── Authenticated (any role) ────────────────────────────────
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('me', [SessionController::class, 'me']);
        Route::post('logout', [SessionController::class, 'logout']);
        Route::post('logout-all', [SessionController::class, 'logoutAll']);

        Route::get('profile', [ProfileController::class, 'show']);
        Route::match(['put', 'patch', 'post'], 'profile', [ProfileController::class, 'update']);

        // Fast typeahead suggestions (link plumbers / retail / stores)
        Route::get('suggest', SuggestController::class);

        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('summary', [NotificationController::class, 'summary']);
            Route::get('unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('read-all', [NotificationController::class, 'markAllRead']);
            Route::post('read-many', [NotificationController::class, 'markManyRead']);
            Route::delete('read', [NotificationController::class, 'destroyRead']);
            Route::delete('/', [NotificationController::class, 'destroyAll']);
            Route::post('delete-many', [NotificationController::class, 'destroyMany']);
            Route::get('{id}', [NotificationController::class, 'show']);
            Route::post('{id}/read', [NotificationController::class, 'markRead']);
            Route::post('{id}/unread', [NotificationController::class, 'markUnread']);
            Route::delete('{id}', [NotificationController::class, 'destroy']);
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
        Route::get('withdrawals/{withdrawal}/receipt', [\App\Http\Controllers\Api\Plumber\WithdrawalReceiptController::class, 'show']);
        Route::get('withdrawals/{withdrawal}/receipt/download', [\App\Http\Controllers\Api\Plumber\WithdrawalReceiptController::class, 'download']);
        Route::post('withdrawals', [WalletController::class, 'requestWithdrawal']);

        // Notifications inbox (same DB notifications table)
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('summary', [NotificationController::class, 'summary']);
            Route::get('unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('read-all', [NotificationController::class, 'markAllRead']);
            Route::post('read-many', [NotificationController::class, 'markManyRead']);
            Route::delete('read', [NotificationController::class, 'destroyRead']);
            Route::delete('/', [NotificationController::class, 'destroyAll']);
            Route::post('delete-many', [NotificationController::class, 'destroyMany']);
            Route::get('{id}', [NotificationController::class, 'show']);
            Route::post('{id}/read', [NotificationController::class, 'markRead']);
            Route::post('{id}/unread', [NotificationController::class, 'markUnread']);
            Route::delete('{id}', [NotificationController::class, 'destroy']);
        });

        // Products catalog for placing orders to retail traders
        Route::get('products', [PlumberProductCatalogController::class, 'index']);

        // Orders to retail trader
        Route::get('orders', [PlumberOrderController::class, 'index']);
        Route::post('orders', [PlumberOrderController::class, 'store']);
        Route::get('orders/{order}', [PlumberOrderController::class, 'show']);
        Route::post('orders/{order}/receive', [PlumberOrderController::class, 'receive']);
        Route::post('orders/{order}/cancel', [PlumberOrderController::class, 'cancel']);

        // Invoice returns — retail→plumber distributions (buyer side)
        Route::get('distributions', [PlumberInvoiceReturnController::class, 'distributions']);
        Route::get('returns', [PlumberInvoiceReturnController::class, 'index']);
        Route::get('returns/{return}', [PlumberInvoiceReturnController::class, 'show']);
        Route::get('distributions/{distribution}/return-preview', [PlumberInvoiceReturnController::class, 'previewDistribution']);
        Route::post('distributions/{distribution}/returns', [PlumberInvoiceReturnController::class, 'storeDistribution']);
    });

    // ── Retail trader (تاجر التجزئة) ───────────────────────────
    Route::middleware(['auth:sanctum', 'role.api:retail_trader'])->prefix('trader')->group(function () {
        Route::get('dashboard', TraderDashboardController::class);
        Route::get('points-panel', PointsPanelController::class);

        Route::get('plumbers', [TraderPlumberController::class, 'index']);
        Route::post('plumbers/link', [TraderPlumberController::class, 'link']);
        Route::get('plumbers/{plumber}', [TraderPlumberController::class, 'show']);

        Route::get('pos/stock', [TraderPosController::class, 'stock']);
        Route::post('pos/checkout', [TraderPosController::class, 'checkout']);

        // Linked wholesaler remaining stock (for supply orders)
        Route::get('wholesalers/{wholesaler}/stock', [TraderPosController::class, 'wholesalerStock'])
            ->whereNumber('wholesaler');

        // Notifications inbox (same DB notifications table)
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('summary', [NotificationController::class, 'summary']);
            Route::get('unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('read-all', [NotificationController::class, 'markAllRead']);
            Route::post('read-many', [NotificationController::class, 'markManyRead']);
            Route::delete('read', [NotificationController::class, 'destroyRead']);
            Route::delete('/', [NotificationController::class, 'destroyAll']);
            Route::post('delete-many', [NotificationController::class, 'destroyMany']);
            Route::get('{id}', [NotificationController::class, 'show']);
            Route::post('{id}/read', [NotificationController::class, 'markRead']);
            Route::post('{id}/unread', [NotificationController::class, 'markUnread']);
            Route::delete('{id}', [NotificationController::class, 'destroy']);
        });

        // MG catalog — order from wholesaler/warehouse
        Route::get('products', [TraderProductCatalogController::class, 'index']);
        Route::get('products/categories', [TraderProductCatalogController::class, 'categories']);

        // Shop display products (non-MG / no points) + animation
        Route::get('my-products', [NetworkStoreController::class, 'listMyProducts']);
        Route::post('my-products', [NetworkStoreController::class, 'uploadMyProduct']);
        Route::match(['put', 'patch', 'post'], 'my-products/{mediaId}', [NetworkStoreController::class, 'updateMyProduct'])->whereNumber('mediaId');
        Route::delete('my-products/{mediaId}', [NetworkStoreController::class, 'deleteMyProduct'])->whereNumber('mediaId');

        // Storefront visuals: slider (carousel) + gallery (casual / معرض أعمال)
        Route::get('slider', [NetworkStoreController::class, 'listSlider']);
        Route::post('slider', [NetworkStoreController::class, 'uploadSlider']);
        Route::delete('slider/{mediaId}', [NetworkStoreController::class, 'deleteSlider'])->whereNumber('mediaId');
        Route::put('slider/order', [NetworkStoreController::class, 'reorderSlider']);
        Route::get('gallery', [NetworkStoreController::class, 'listGallery']);
        Route::post('gallery', [NetworkStoreController::class, 'uploadGallery']);
        Route::delete('gallery/{mediaId}', [NetworkStoreController::class, 'deleteGallery'])->whereNumber('mediaId');
        Route::get('media', [NetworkStoreController::class, 'listStoreMedia']);
        Route::post('media', [NetworkStoreController::class, 'uploadStoreMedia']);
        Route::delete('media/{mediaId}', [NetworkStoreController::class, 'deleteStoreMedia'])->whereNumber('mediaId');

        // Orders — place to wholesaler + fulfil plumber orders
        Route::get('orders', [TraderOrderController::class, 'index']);
        Route::post('orders', [TraderOrderController::class, 'store']);
        Route::get('orders/{order}', [TraderOrderController::class, 'show']);
        Route::put('orders/{order}/items', [TraderOrderController::class, 'updateItems']);
        Route::get('orders/{order}/stock', [TraderOrderController::class, 'stock']);
        Route::post('orders/{order}/apply-stock', [TraderOrderController::class, 'applyStock']);
        Route::post('orders/{order}/fulfill', [TraderOrderController::class, 'fulfill']);
        Route::post('orders/{order}/confirm', [TraderOrderController::class, 'confirm']);
        Route::post('orders/{order}/ship', [TraderOrderController::class, 'ship']);
        Route::post('orders/{order}/reject', [TraderOrderController::class, 'reject']);
        Route::post('orders/{order}/receive', [TraderOrderController::class, 'receive']);
        Route::post('orders/{order}/cancel', [TraderOrderController::class, 'cancel']);

        // Invoice returns — retail→plumber (distributions) + retail←wholesale (invoices)
        Route::get('returns', [TraderInvoiceReturnController::class, 'index']);
        Route::get('returns/{return}', [TraderInvoiceReturnController::class, 'show']);
        Route::get('distributions', [TraderInvoiceReturnController::class, 'distributions']);
        Route::get('distributions/{distribution}/return-preview', [TraderInvoiceReturnController::class, 'previewDistribution']);
        Route::post('distributions/{distribution}/returns', [TraderInvoiceReturnController::class, 'storeDistribution']);
        Route::get('invoices', [TraderInvoiceReturnController::class, 'invoices']);
        Route::get('invoices/{invoice}/return-preview', [TraderInvoiceReturnController::class, 'previewInvoice']);
        Route::post('invoices/{invoice}/returns', [TraderInvoiceReturnController::class, 'storeInvoice']);
    });

    // ── Wholesale distributor (موزع الجملة) ────────────────────
    Route::middleware(['auth:sanctum', 'role.api:wholesale_distributor'])->prefix('distributor')->group(function () {
        Route::get('dashboard', DistributorDashboardController::class);
        Route::get('points-panel', PointsPanelController::class);

        Route::get('retail-traders', [RetailTraderController::class, 'index']);
        Route::post('retail-traders', [RetailTraderController::class, 'store']);
        Route::post('retail-traders/link', [RetailTraderController::class, 'link']);
        Route::get('retail-traders/{retailTrader}', [RetailTraderController::class, 'show']);
        Route::delete('retail-traders/{retailTrader}', [RetailTraderController::class, 'unlink']);

        // Plumbers under linked retail shops (not direct wholesale↔plumber)
        Route::get('plumbers', [DistributorNetworkPlumberController::class, 'index']);
        Route::post('plumbers', [DistributorNetworkPlumberController::class, 'store']);
        Route::post('plumbers/link', [DistributorNetworkPlumberController::class, 'link']);
        Route::get('plumbers/{plumber}', [DistributorNetworkPlumberController::class, 'show']);

        Route::get('pos/stock', [DistributorPosController::class, 'stock']);
        Route::post('pos/checkout', [DistributorPosController::class, 'checkout']);

        // Notifications inbox (same shared controller)
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('summary', [NotificationController::class, 'summary']);
            Route::get('unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('read-all', [NotificationController::class, 'markAllRead']);
            Route::post('read-many', [NotificationController::class, 'markManyRead']);
            Route::delete('read', [NotificationController::class, 'destroyRead']);
            Route::delete('/', [NotificationController::class, 'destroyAll']);
            Route::post('delete-many', [NotificationController::class, 'destroyMany']);
            Route::get('{id}', [NotificationController::class, 'show']);
            Route::post('{id}/read', [NotificationController::class, 'markRead']);
            Route::post('{id}/unread', [NotificationController::class, 'markUnread']);
            Route::delete('{id}', [NotificationController::class, 'destroy']);
        });

        // MG catalog — order from factory
        Route::get('products', [TraderProductCatalogController::class, 'index']);
        Route::get('products/categories', [TraderProductCatalogController::class, 'categories']);

        // Shop display products (non-MG / no points) + animation
        Route::get('my-products', [NetworkStoreController::class, 'listMyProducts']);
        Route::post('my-products', [NetworkStoreController::class, 'uploadMyProduct']);
        Route::match(['put', 'patch', 'post'], 'my-products/{mediaId}', [NetworkStoreController::class, 'updateMyProduct'])->whereNumber('mediaId');
        Route::delete('my-products/{mediaId}', [NetworkStoreController::class, 'deleteMyProduct'])->whereNumber('mediaId');

        // Storefront visuals: slider + gallery
        Route::get('slider', [NetworkStoreController::class, 'listSlider']);
        Route::post('slider', [NetworkStoreController::class, 'uploadSlider']);
        Route::delete('slider/{mediaId}', [NetworkStoreController::class, 'deleteSlider'])->whereNumber('mediaId');
        Route::put('slider/order', [NetworkStoreController::class, 'reorderSlider']);
        Route::get('gallery', [NetworkStoreController::class, 'listGallery']);
        Route::post('gallery', [NetworkStoreController::class, 'uploadGallery']);
        Route::delete('gallery/{mediaId}', [NetworkStoreController::class, 'deleteGallery'])->whereNumber('mediaId');
        Route::get('media', [NetworkStoreController::class, 'listStoreMedia']);
        Route::post('media', [NetworkStoreController::class, 'uploadStoreMedia']);
        Route::delete('media/{mediaId}', [NetworkStoreController::class, 'deleteStoreMedia'])->whereNumber('mediaId');

        // Orders — place to factory + fulfil retail-trader orders
        Route::get('orders', [DistributorOrderController::class, 'index']);
        Route::post('orders', [DistributorOrderController::class, 'store']);
        Route::get('orders/{order}', [DistributorOrderController::class, 'show']);
        Route::post('orders/{order}/confirm', [DistributorOrderController::class, 'confirm']);
        Route::post('orders/{order}/ship', [DistributorOrderController::class, 'ship']);
        Route::post('orders/{order}/deliver', [DistributorOrderController::class, 'deliver']);
        Route::post('orders/{order}/reject', [DistributorOrderController::class, 'reject']);
        Route::post('orders/{order}/receive', [DistributorOrderController::class, 'receive']);
        Route::post('orders/{order}/cancel', [DistributorOrderController::class, 'cancel']);

        // Invoice returns — wholesale→retail outgoing invoices
        Route::get('returns', [DistributorInvoiceReturnController::class, 'index']);
        Route::get('returns/{return}', [DistributorInvoiceReturnController::class, 'show']);
        Route::get('invoices', [DistributorInvoiceReturnController::class, 'invoices']);
        Route::get('invoices/{invoice}/return-preview', [DistributorInvoiceReturnController::class, 'preview']);
        Route::post('invoices/{invoice}/returns', [DistributorInvoiceReturnController::class, 'store']);
    });
});
