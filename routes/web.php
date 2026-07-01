<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\LandingController;
use App\Http\Controllers\Web\PortalController;
use App\Http\Controllers\Web\WebsiteRegistrationController;

Route::get('/', [LandingController::class, 'index'])->name('landing');
Route::get('/portal', [PortalController::class, 'index'])->name('portal');
Route::get('/terms', [LandingController::class, 'terms'])->name('terms');
Route::get('/privacy', [LandingController::class, 'privacy'])->name('privacy');
Route::post('/register', [WebsiteRegistrationController::class, 'store'])->name('website.register');

Route::middleware(['web', 'auth'])->prefix('admin/invoices')->name('admin.invoices.')->group(function () {
    Route::get('{invoice}/print', [\App\Http\Controllers\Admin\InvoiceDocumentController::class, 'print'])->name('print');
    Route::get('{invoice}/download', [\App\Http\Controllers\Admin\InvoiceDocumentController::class, 'download'])->name('download');
    Route::get('{invoice}/export', [\App\Http\Controllers\Admin\InvoiceDocumentController::class, 'exportJson'])->name('export');
});
