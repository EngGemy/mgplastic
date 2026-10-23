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
use App\Http\Controllers\Api\Distributor\JoinRequestController as DistributorJoinRequestController;
use App\Http\Controllers\Api\Distributor\RetailTraderController;
use App\Http\Controllers\Api\Store\NetworkStoreController;
use App\Http\Controllers\Api\Ios\IosWalletVisibilityController;
use App\Http\Controllers\Api\Mobile\BiometricController;
use App\Http\Controllers\Api\Mobile\FeaturedWholesalerController;
use App\Http\Controllers\Api\Mobile\HomeHighlightsController;
use App\Http\Controllers\Api\Mobile\NotificationController;
use App\Http\Controllers\Api\Mobile\PushTokenController;
use App\Http\Controllers\Api\Mobile\PointsPanelController;
use App\Http\Controllers\Api\Mobile\ProductCollectionController;
use App\Http\Controllers\Api\Mobile\ProductPromotionController;
use App\Http\Controllers\Api\Mobile\PromoBannerController;
use App\Http\Controllers\Api\Mobile\ProfileController;
use App\Http\Controllers\Api\Mobile\SettingsController;
use App\Http\Controllers\Api\Mobile\SuggestController;
use App\Http\Controllers\Api\Mobile\Reputation\PlumberReputationController;
use App\Http\Controllers\Api\Mobile\Reputation\PlumberWarrantyController;
use App\Http\Controllers\Api\Mobile\InvoiceShareController;
use App\Http\Controllers\Api\Mobile\TestimonialController;
use App\Http\Controllers\Api\Plumber\DashboardController as PlumberDashboardController;
use App\Http\Controllers\Api\Plumber\InvoiceReturnController as PlumberInvoiceReturnController;
use App\Http\Controllers\Api\Plumber\DistributionReceiptController as PlumberDistributionReceiptController;
use App\Http\Controllers\Api\Plumber\OrderController as PlumberOrderController;
use App\Http\Controllers\Api\Plumber\ProductCatalogController as PlumberProductCatalogController;
use App\Http\Controllers\Api\Plumber\WalletApiController;
use App\Http\Controllers\Api\Plumber\WalletController;
use App\Http\Controllers\Api\Plumber\WithdrawalController;
use App\Http\Controllers\Api\Trader\DashboardController as TraderDashboardController;
use App\Http\Controllers\Api\Trader\InvoiceReceiptController as TraderInvoiceReceiptController;
use App\Http\Controllers\Api\Trader\InvoiceReturnController as TraderInvoiceReturnController;
use App\Http\Controllers\Api\Trader\OrderController as TraderOrderController;
use App\Http\Controllers\Api\Trader\PlumberController as TraderPlumberController;
use App\Http\Controllers\Api\Trader\PosController as TraderPosController;
use App\Http\Controllers\Api\Trader\WholesalerController as TraderWholesalerController;
use App\Http\Controllers\Api\Trader\WholesalerJoinRequestController as TraderWholesalerJoinRequestController;
use App\Http\Controllers\Api\Trader\ProductCatalogController as TraderProductCatalogController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/mobile')->group(function () {

    // ── Public app settings ─────────────────────────────────────
    Route::get('settings', [SettingsController::class, 'app']);

    // آراء العملاء عن منتجات MG (عامة — موقع + تطبيق)
    Route::get('testimonials', [TestimonialController::class, 'index'])->middleware('throttle:60,1');

    // أهم الموزعين + دعاية منتجات MG + مجموعات + بانرات (عامة للتطبيق)
    Route::get('featured-wholesalers', [FeaturedWholesalerController::class, 'index']);
    Route::get('product-promotions', [ProductPromotionController::class, 'index']);
    Route::get('product-promotions/{promotion}', [ProductPromotionController::class, 'show'])->whereNumber('promotion');
    Route::get('product-collections', [ProductCollectionController::class, 'index']);
    Route::get('product-collections/{collection}', [ProductCollectionController::class, 'show']);
    Route::get('promo-banners', [PromoBannerController::class, 'index']);

    // Wholesale home green-banner icons (المشاريع · الصرف · مياه الشرب)
    Route::get('home/highlights', [HomeHighlightsController::class, 'index']);

    // Wallet visibility — apps read this to hide/show the wallet UI
    Route::get('wallet-visibility', [IosWalletVisibilityController::class, 'show']);

    // Biometric unlock (public challenge + verify)
    Route::post('biometric/challenge', [BiometricController::class, 'challenge'])->middleware('throttle:20,1');
    Route::post('biometric/verify', [BiometricController::class, 'verify'])->middleware('throttle:20,1');

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

        // FCM push tokens (call DELETE before logout)
        Route::post('devices/push-token', [PushTokenController::class, 'store']);
        Route::delete('devices/push-token', [PushTokenController::class, 'destroy']);

        // Biometric device management
        Route::post('biometric/register', [BiometricController::class, 'register']);
        Route::get('biometric/devices', [BiometricController::class, 'devices']);
        Route::delete('biometric/devices/{deviceId}', [BiometricController::class, 'revoke']);
        Route::post('biometric/revoke-all', [BiometricController::class, 'revokeAll']);

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
            Route::get('preferences', [NotificationController::class, 'preferences']);
            Route::match(['put', 'patch'], 'preferences', [NotificationController::class, 'updatePreferences']);
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
            Route::get('preferences', [NotificationController::class, 'preferences']);
            Route::match(['put', 'patch'], 'preferences', [NotificationController::class, 'updatePreferences']);
            Route::get('{id}', [NotificationController::class, 'show']);
            Route::post('{id}/read', [NotificationController::class, 'markRead']);
            Route::post('{id}/unread', [NotificationController::class, 'markUnread']);
            Route::delete('{id}', [NotificationController::class, 'destroy']);
        });

        // Products catalog for placing orders to retail traders (full MG + shop stock)
        Route::get('products', [PlumberProductCatalogController::class, 'index']);
        Route::get('products/types', [PlumberProductCatalogController::class, 'types']);
        Route::get('products/categories', [PlumberProductCatalogController::class, 'categories']);

        // Orders to retail trader
        Route::get('orders', [PlumberOrderController::class, 'index']);
        Route::post('orders', [PlumberOrderController::class, 'store']);
        Route::get('orders/{order}', [PlumberOrderController::class, 'show']);
        Route::post('orders/{order}/receive', [PlumberOrderController::class, 'receive']);
        Route::post('orders/{order}/cancel', [PlumberOrderController::class, 'cancel']);

        // Pending POS/fulfill receipts — plumber must confirm before points settle
        Route::get('pending-receipts', [PlumberDistributionReceiptController::class, 'pending']);
        Route::post('distributions/{distribution}/receive', [PlumberDistributionReceiptController::class, 'receive'])
            ->whereNumber('distribution');

        // Invoice returns — retail→plumber distributions (buyer side)
        Route::get('distributions', [PlumberInvoiceReturnController::class, 'distributions']);
        Route::get('returns', [PlumberInvoiceReturnController::class, 'index']);
        Route::get('returns/{return}', [PlumberInvoiceReturnController::class, 'show']);
        Route::post('returns/{return}/approve', [PlumberInvoiceReturnController::class, 'approve']);
        Route::post('returns/{return}/reject', [PlumberInvoiceReturnController::class, 'reject']);
        Route::get('distributions/{distribution}/return-preview', [PlumberInvoiceReturnController::class, 'previewDistribution']);
        Route::post('distributions/{distribution}/returns', [PlumberInvoiceReturnController::class, 'storeDistribution']);
        Route::get('distributions/{distribution}/share', [InvoiceShareController::class, 'showDistribution'])->whereNumber('distribution');
        Route::post('distributions/{distribution}/share-whatsapp', [InvoiceShareController::class, 'shareDistributionWhatsApp'])->whereNumber('distribution');
        Route::get('reports', [\App\Http\Controllers\Api\Plumber\ReportController::class, 'index']);

        // Reputation / badges / streak / warranties / reminder prefs
        Route::get('reputation', [PlumberReputationController::class, 'reputation']);
        Route::get('badges', [PlumberReputationController::class, 'badges']);
        Route::get('streak', [PlumberReputationController::class, 'streak']);
        Route::get('reminder-preferences', [PlumberReputationController::class, 'reminderPreferences']);
        Route::put('reminder-preferences', [PlumberReputationController::class, 'updateReminderPreferences']);
        Route::get('warranties/eligible-items', [PlumberWarrantyController::class, 'eligible']);
        Route::get('warranties', [PlumberWarrantyController::class, 'index']);
        Route::post('warranties', [PlumberWarrantyController::class, 'store']);
        Route::get('warranties/{warranty}', [PlumberWarrantyController::class, 'show'])->whereNumber('warranty');
        Route::post('warranties/{warranty}/resend', [PlumberWarrantyController::class, 'resend'])->whereNumber('warranty');
        Route::get('warranties/{warranty}/pdf', [PlumberWarrantyController::class, 'pdf'])->whereNumber('warranty');
    });

    // ── Retail trader (تاجر التجزئة) ───────────────────────────
    Route::middleware(['auth:sanctum', 'role.api:retail_trader'])->prefix('trader')->group(function () {
        Route::get('dashboard', TraderDashboardController::class);
        Route::get('points-panel', PointsPanelController::class);

        Route::get('plumbers', [TraderPlumberController::class, 'index']);
        Route::post('plumbers/link', [TraderPlumberController::class, 'link']);
        Route::delete('plumbers/{plumber}', [TraderPlumberController::class, 'unlink'])->whereNumber('plumber');
        Route::get('plumbers/{plumber}/reputation', [PlumberReputationController::class, 'showPlumber'])->whereNumber('plumber');
        Route::get('plumbers/{plumber}', [TraderPlumberController::class, 'show']);
        Route::get('reminder-preferences', [PlumberReputationController::class, 'reminderPreferences']);
        Route::put('reminder-preferences', [PlumberReputationController::class, 'updateReminderPreferences']);

        Route::get('pos/stock', [TraderPosController::class, 'stock']);
        Route::post('pos/checkout', [TraderPosController::class, 'checkout']);

        // Multi wholesaler network (قطاعي ↔ أكثر من جملة)
        Route::get('wholesalers', [TraderWholesalerController::class, 'index']);
        Route::post('wholesalers/link', [TraderWholesalerController::class, 'link']); // sends join request
        Route::delete('wholesalers/{wholesaler}', [TraderWholesalerController::class, 'unlink'])->whereNumber('wholesaler');
        Route::get('wholesalers/{wholesaler}', [TraderWholesalerController::class, 'show'])->whereNumber('wholesaler');
        Route::get('wholesalers/{wholesaler}/stock', [TraderPosController::class, 'wholesalerStock'])
            ->whereNumber('wholesaler');

        // طلبات انضمام القطاعي → الجملة (بانتظار موافقة الجملة)
        Route::get('wholesaler-join-requests', [TraderWholesalerJoinRequestController::class, 'index']);
        Route::post('wholesaler-join-requests', [TraderWholesalerJoinRequestController::class, 'store']);
        Route::delete('wholesaler-join-requests/{joinRequest}', [TraderWholesalerJoinRequestController::class, 'destroy'])
            ->whereNumber('joinRequest');

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
            Route::get('preferences', [NotificationController::class, 'preferences']);
            Route::match(['put', 'patch'], 'preferences', [NotificationController::class, 'updatePreferences']);
            Route::get('{id}', [NotificationController::class, 'show']);
            Route::post('{id}/read', [NotificationController::class, 'markRead']);
            Route::post('{id}/unread', [NotificationController::class, 'markUnread']);
            Route::delete('{id}', [NotificationController::class, 'destroy']);
        });

        // MG catalog — order from wholesaler/warehouse
        Route::get('products', [TraderProductCatalogController::class, 'index']);
        Route::get('products/types', [TraderProductCatalogController::class, 'types']);
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

        // Pending wholesale POS invoices — retail must confirm receipt before stock settles
        Route::get('pending-receipts', [TraderInvoiceReceiptController::class, 'pending']);
        Route::post('invoices/{invoice}/receive', [TraderInvoiceReceiptController::class, 'receiveInvoice'])
            ->whereNumber('invoice');
        Route::post('distributions/{distribution}/receive', [TraderInvoiceReceiptController::class, 'receiveDistribution'])
            ->whereNumber('distribution');

        // Invoice returns — retail→plumber (distributions) + retail←wholesale (invoices)
        Route::get('returns', [TraderInvoiceReturnController::class, 'index']);
        Route::get('returns/{return}', [TraderInvoiceReturnController::class, 'show']);
        Route::post('returns/{return}/approve', [TraderInvoiceReturnController::class, 'approve']);
        Route::post('returns/{return}/reject', [TraderInvoiceReturnController::class, 'reject']);
        Route::get('distributions', [TraderInvoiceReturnController::class, 'distributions']);
        Route::get('distributions/{distribution}/return-preview', [TraderInvoiceReturnController::class, 'previewDistribution']);
        Route::post('distributions/{distribution}/returns', [TraderInvoiceReturnController::class, 'storeDistribution']);
        Route::get('invoices', [TraderInvoiceReturnController::class, 'invoices']);
        Route::get('my-invoices', [TraderInvoiceReturnController::class, 'invoices']); // alias فواتيري
        Route::get('invoices/{invoice}/share', [InvoiceShareController::class, 'showInvoice'])->whereNumber('invoice');
        Route::post('invoices/{invoice}/share-whatsapp', [InvoiceShareController::class, 'shareInvoiceWhatsApp'])->whereNumber('invoice');
        Route::get('invoices/{invoice}/return-preview', [TraderInvoiceReturnController::class, 'previewInvoice']);
        Route::post('invoices/{invoice}/returns', [TraderInvoiceReturnController::class, 'storeInvoice']);
        Route::get('distributions/{distribution}/share', [InvoiceShareController::class, 'showDistribution'])->whereNumber('distribution');
        Route::post('distributions/{distribution}/share-whatsapp', [InvoiceShareController::class, 'shareDistributionWhatsApp'])->whereNumber('distribution');
        Route::get('reports', [\App\Http\Controllers\Api\Trader\ReportController::class, 'index']);
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

        // طلبات انضمام القطاعيين لشبكة الجملة
        Route::get('join-requests', [DistributorJoinRequestController::class, 'index']);
        Route::post('join-requests/{joinRequest}/approve', [DistributorJoinRequestController::class, 'approve'])
            ->whereNumber('joinRequest');
        Route::post('join-requests/{joinRequest}/reject', [DistributorJoinRequestController::class, 'reject'])
            ->whereNumber('joinRequest');

        // Plumbers under linked retail shops (not direct wholesale↔plumber)
        Route::get('plumbers', [DistributorNetworkPlumberController::class, 'index']);
        Route::post('plumbers', [DistributorNetworkPlumberController::class, 'store']);
        Route::post('plumbers/link', [DistributorNetworkPlumberController::class, 'link']);
        Route::get('plumbers/{plumber}/reputation', [PlumberReputationController::class, 'showPlumber'])->whereNumber('plumber');
        Route::get('plumbers/{plumber}', [DistributorNetworkPlumberController::class, 'show']);
        Route::get('reminder-preferences', [PlumberReputationController::class, 'reminderPreferences']);
        Route::put('reminder-preferences', [PlumberReputationController::class, 'updateReminderPreferences']);

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
            Route::get('preferences', [NotificationController::class, 'preferences']);
            Route::match(['put', 'patch'], 'preferences', [NotificationController::class, 'updatePreferences']);
            Route::get('{id}', [NotificationController::class, 'show']);
            Route::post('{id}/read', [NotificationController::class, 'markRead']);
            Route::post('{id}/unread', [NotificationController::class, 'markUnread']);
            Route::delete('{id}', [NotificationController::class, 'destroy']);
        });

        // MG catalog — order from factory
        Route::get('products', [TraderProductCatalogController::class, 'index']);
        Route::get('products/types', [TraderProductCatalogController::class, 'types']);
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
        Route::post('orders/quote', [DistributorOrderController::class, 'quote']);
        Route::post('orders', [DistributorOrderController::class, 'store']);
        Route::get('orders/{order}', [DistributorOrderController::class, 'show']);
        Route::post('orders/{order}/confirm', [DistributorOrderController::class, 'confirm']);
        Route::post('orders/{order}/ship', [DistributorOrderController::class, 'ship']);
        Route::post('orders/{order}/deliver', [DistributorOrderController::class, 'deliver']);
        Route::post('orders/{order}/reject', [DistributorOrderController::class, 'reject']);
        Route::post('orders/{order}/receive', [DistributorOrderController::class, 'receive']);
        Route::post('orders/{order}/cancel', [DistributorOrderController::class, 'cancel']);

        // Order sources: factory + central warehouses
        Route::get('order-sources', [DistributorOrderController::class, 'sources']);
        Route::get('warehouses/{warehouse}/stock', [DistributorOrderController::class, 'warehouseStock'])->whereNumber('warehouse');

        // فواتيري + مرتجعات (مبيعات قطاعي + مشتريات مصنع/مخزن)
        Route::get('returns', [DistributorInvoiceReturnController::class, 'index']);
        Route::get('returns/{return}', [DistributorInvoiceReturnController::class, 'show']);
        Route::post('returns/{return}/approve', [DistributorInvoiceReturnController::class, 'approve']);
        Route::post('returns/{return}/reject', [DistributorInvoiceReturnController::class, 'reject']);
        Route::get('invoices', [DistributorInvoiceReturnController::class, 'invoices']);
        Route::get('my-invoices', [DistributorInvoiceReturnController::class, 'invoices']); // alias فواتيري
        Route::get('invoices/{invoice}/share', [InvoiceShareController::class, 'showInvoice'])->whereNumber('invoice');
        Route::post('invoices/{invoice}/share-whatsapp', [InvoiceShareController::class, 'shareInvoiceWhatsApp'])->whereNumber('invoice');
        Route::get('invoices/{invoice}/return-preview', [DistributorInvoiceReturnController::class, 'preview']);
        Route::post('invoices/{invoice}/returns', [DistributorInvoiceReturnController::class, 'store']);
        Route::get('distributions/{distribution}/return-preview', [DistributorInvoiceReturnController::class, 'previewDistribution'])->whereNumber('distribution');
        Route::post('distributions/{distribution}/returns', [DistributorInvoiceReturnController::class, 'storeDistribution'])->whereNumber('distribution');
        Route::get('distributions/{distribution}/share', [InvoiceShareController::class, 'showDistribution'])->whereNumber('distribution');
        Route::post('distributions/{distribution}/share-whatsapp', [InvoiceShareController::class, 'shareDistributionWhatsApp'])->whereNumber('distribution');

        // Period reports
        Route::get('reports', [\App\Http\Controllers\Api\Distributor\ReportController::class, 'index']);
    });
});
