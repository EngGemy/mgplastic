<?php

use App\Http\Controllers\Web\LandingController;
use App\Http\Controllers\Web\OpenAppController;
use App\Http\Controllers\Web\PortalController;
use App\Http\Controllers\Web\WebsiteRegistrationController;
use App\Http\Controllers\Web\WellKnownController;
use App\Support\AdminPanelPath;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'index'])->name('landing');

// Universal / App Links (WhatsApp captions → mobile screen)
Route::get('/open/{type}/{id?}', OpenAppController::class)
    ->where(['type' => '[A-Za-z]+', 'id' => '[A-Za-z0-9_-]+'])
    ->name('app.open');

Route::get('/.well-known/assetlinks.json', [WellKnownController::class, 'assetLinks'])
    ->name('well-known.assetlinks');
Route::get('/.well-known/apple-app-site-association', [WellKnownController::class, 'appleAppSiteAssociation'])
    ->name('well-known.aasa');
Route::get('/portal', [PortalController::class, 'index'])->name('portal');
Route::get('/contact', [LandingController::class, 'contact'])->name('contact');
Route::get('/terms', [LandingController::class, 'terms'])->name('terms');
Route::get('/privacy', [LandingController::class, 'privacy'])->name('privacy');
Route::get('/policy', [LandingController::class, 'policy'])->name('policy');
Route::post('/register', [WebsiteRegistrationController::class, 'store'])->name('website.register');
Route::get('/catalog/products', [\App\Http\Controllers\Web\CatalogController::class, 'products'])->name('website.catalog');

Route::middleware(['web', 'auth'])
    ->prefix(AdminPanelPath::segment().'/invoices')
    ->name('admin.invoices.')
    ->group(function () {
        Route::get('{invoice}/print', [\App\Http\Controllers\Admin\InvoiceDocumentController::class, 'print'])->name('print');
        Route::get('{invoice}/download', [\App\Http\Controllers\Admin\InvoiceDocumentController::class, 'download'])->name('download');
        Route::get('{invoice}/export', [\App\Http\Controllers\Admin\InvoiceDocumentController::class, 'exportJson'])->name('export');
    });

Route::middleware(['web', 'auth'])
    ->prefix(AdminPanelPath::segment().'/price-lists')
    ->name('admin.price-lists.')
    ->group(function () {
        Route::get('{priceList}/print', [\App\Http\Controllers\Admin\PriceListDocumentController::class, 'print'])->name('print');
    });

Route::get('/price-lists/{priceList}/share', [\App\Http\Controllers\Admin\PriceListDocumentController::class, 'share'])
    ->middleware('signed')
    ->name('price-lists.share');

// Signed shareable withdrawal receipt (opens without app token)
Route::get('/withdrawals/{withdrawal}/receipt', [\App\Http\Controllers\WithdrawalReceiptWebController::class, 'show'])
    ->middleware('signed')
    ->name('withdrawals.receipt');

Route::get('/orders/{order}/share', [\App\Http\Controllers\Web\OrderShareController::class, 'show'])
    ->middleware('signed')
    ->name('orders.share');

Route::get('/invoices/{invoice}/share', [\App\Http\Controllers\Web\InvoiceShareController::class, 'show'])
    ->middleware('signed')
    ->name('invoices.share');

if (AdminPanelPath::hidesLegacyAdminUrl()) {
    Route::any('admin/{path?}', fn () => abort(404))
        ->where('path', '.*')
        ->name('admin.legacy-decoy');
}
