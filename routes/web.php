<?php

use App\Support\AdminPanelPath;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\LandingController;
use App\Http\Controllers\Web\PortalController;
use App\Http\Controllers\Web\WebsiteRegistrationController;

Route::get('/', [LandingController::class, 'index'])->name('landing');
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

// Signed shareable withdrawal receipt (opens without app token)
Route::get('/withdrawals/{withdrawal}/receipt', [\App\Http\Controllers\WithdrawalReceiptWebController::class, 'show'])
    ->middleware('signed')
    ->name('withdrawals.receipt');

if (AdminPanelPath::hidesLegacyAdminUrl()) {
    Route::any('admin/{path?}', fn () => abort(404))
        ->where('path', '.*')
        ->name('admin.legacy-decoy');
}
