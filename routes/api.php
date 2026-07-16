<?php

use App\Http\Controllers\Api\Blog\BlogController;
use App\Http\Controllers\Api\Event\EventController;
use App\Http\Controllers\Api\Plumber\PlumberProfileController;
use App\Http\Controllers\Api\Product\ProductController;
use App\Http\Controllers\Api\Static\SliderController;
use App\Http\Controllers\Api\Store\PlumberStoreController;
use App\Http\Controllers\Api\Store\PlumberStoreMediaController;
use App\Http\Controllers\Api\Store\NetworkStoreController;
use App\Http\Controllers\Api\Plumber\InvoiceController as PlumberInvoiceController;
use App\Http\Controllers\Api\Plumber\WalletController;


use Illuminate\Support\Facades\Route;

// Auth Controllers
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\PlumberRegisterController;
use App\Http\Controllers\Api\Auth\StoreRegisterController;
use App\Http\Controllers\Api\Auth\VerifyOtpController;
use App\Http\Controllers\Api\Auth\PasswordResetByPhoneController;


// Static Controllers
use App\Http\Controllers\Api\Static\TermsController;
use App\Http\Controllers\Api\Static\PrivacyController;
use App\Http\Controllers\Api\Static\SocialMediaController;

// Claim Controller
use App\Http\Controllers\Api\Static\ClaimController;

use App\Http\Controllers\Api\Location\CountryController;
use App\Http\Controllers\Api\Location\CityController;

use App\Http\Controllers\Api\Plumber\PlumberPublicController;

use App\Http\Controllers\Vendor\VendorStoreSliderController;

use App\Http\Controllers\Api\Ios\IosWalletVisibilityController;
use App\Http\Controllers\Api\Distribution\DistributionController;
use App\Models\Invoice;
use App\Services\DistributionService;



// =========================
// AUTH ROUTES
// =========================
Route::middleware('api')->prefix('v1/auth')->group(function () {
    // Normal User Register & Login
    Route::post('register', [RegisterController::class, 'register']);
    Route::post('login', [LoginController::class, 'login']);

    // Plumber Register (separate logic)
    Route::post('register-plumber', [PlumberRegisterController::class, 'register']);
    Route::post('login-plumber', [LoginController::class, 'login']); // can reuse login if same logic

    // Wholesale store self-registration (موزع الجملة) — pending admin approval
    Route::post('register-store', [StoreRegisterController::class, 'register']);

    // Retail trader self-registration (تاجر التجزئة / القطاعي) — use this from the mobile store app
    Route::post('register-retail', [StoreRegisterController::class, 'registerRetail']);
    Route::post('register-trader', [StoreRegisterController::class, 'registerRetail']); // alias
    Route::post('verify-otp', [VerifyOtpController::class, 'verify'])->middleware('throttle:6,1');
    Route::post('resend-otp', [VerifyOtpController::class, 'resend'])->middleware('throttle:3,1');



    // Forgot by phone
    Route::post('forgot-password/send-otp', [PasswordResetByPhoneController::class, 'sendOtp'])->middleware('throttle:3,1');
    Route::post('forgot-password/verify-otp', [PasswordResetByPhoneController::class, 'verifyOtp'])->middleware('throttle:6,1'); // optional
    Route::post('forgot-password/reset', [PasswordResetByPhoneController::class, 'reset']);

});

// =========================
// STATIC CONTENT ROUTES
// =========================
Route::middleware('api')->prefix('v1')->group(function () {
    Route::get('terms', [TermsController::class, 'show']);
    Route::get('privacy', [PrivacyController::class, 'show']);
    Route::get('social-media', [SocialMediaController::class, 'index']);
});

// =========================
// CLAIM ROUTES
// =========================
Route::middleware('api')->prefix('v1')->group(function () {
    Route::post('claims', [ClaimController::class, 'store']); // Submit new claim
    Route::get('claims', [ClaimController::class, 'index']);  // View all claims
});


Route::middleware('api')->prefix('v1')->group(function () {
    // General sliders (optional filter ?type=home|store)
    Route::get('sliders', [SliderController::class, 'index']);

    // Specific endpoints
    Route::get('sliders/home', [SliderController::class, 'home']);
    Route::get('sliders/store', [SliderController::class, 'storeOnly']);

    // Add new slider (admin)
    Route::post('sliders', [SliderController::class, 'store']);
});



Route::prefix('v1/plumber-stores')->group(function () {
    // Public
    Route::get('/', [PlumberStoreController::class, 'index']);
    Route::get('/{id}', [PlumberStoreController::class, 'show'])->whereNumber('id');
    Route::get('/related/{id}', [PlumberStoreController::class, 'related'])->whereNumber('id');

    // Authenticated vendor-only
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/my/list', [PlumberStoreController::class, 'myStores']);
        Route::post('/store', [PlumberStoreController::class, 'store']);
        Route::match(['put', 'patch', 'post'], '/{id}', [PlumberStoreController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [PlumberStoreController::class, 'destroy'])->whereNumber('id');

        // slider
        Route::post('/stores/{id}/slider', [PlumberStoreController::class, 'uploadSlider'])->whereNumber('id');
        Route::delete('/stores/{id}/slider/{imageId}', [PlumberStoreController::class, 'deleteSliderImage'])->whereNumber('id')->whereNumber('imageId');
        Route::put('/stores/{id}/slider/order', [PlumberStoreController::class, 'reorderSlider'])->whereNumber('id');
        Route::post('/stores/{id}/slider/replace', [PlumberStoreController::class, 'replaceSlider'])->whereNumber('id');

        // media (videos, banners, product images, social)
        Route::get('/{id}/media', [PlumberStoreMediaController::class, 'listStoreMedia'])->whereNumber('id');
        Route::post('/{id}/media', [PlumberStoreMediaController::class, 'uploadStoreMedia'])->whereNumber('id');
        Route::delete('/{id}/media/{mediaId}', [PlumberStoreMediaController::class, 'deleteStoreMedia'])->whereNumber('id')->whereNumber('mediaId');
        Route::post('/{id}/social-links', [PlumberStoreMediaController::class, 'upsertSocialLinks'])->whereNumber('id');
        Route::delete('/{id}/social-links/{linkId}', [PlumberStoreMediaController::class, 'deleteSocialLink'])->whereNumber('id');
    });
});

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('my-store', [NetworkStoreController::class, 'show']);
    Route::match(['put', 'patch'], 'my-store', [NetworkStoreController::class, 'update']);
    Route::get('my-store/media', [NetworkStoreController::class, 'listStoreMedia']);
    Route::post('my-store/media', [NetworkStoreController::class, 'uploadStoreMedia']);
    Route::delete('my-store/media/{mediaId}', [NetworkStoreController::class, 'deleteStoreMedia'])->whereNumber('mediaId');
    Route::post('my-store/social-links', [NetworkStoreController::class, 'upsertSocialLinks']);
    Route::delete('my-store/social-links/{linkId}', [NetworkStoreController::class, 'deleteSocialLink']);

    // Explicit slider aliases (same as kind=banner media)
    Route::get('my-store/slider', [NetworkStoreController::class, 'listSlider']);
    Route::post('my-store/slider', [NetworkStoreController::class, 'uploadSlider']);
    Route::delete('my-store/slider/{mediaId}', [NetworkStoreController::class, 'deleteSlider'])->whereNumber('mediaId');
    Route::put('my-store/slider/order', [NetworkStoreController::class, 'reorderSlider']);

    // منتجاتي — معرض صور المتجر (بدون نقاط)
    Route::get('my-store/my-products', [NetworkStoreController::class, 'listMyProducts']);
    Route::post('my-store/my-products', [NetworkStoreController::class, 'uploadMyProduct']);
    Route::match(['put', 'patch', 'post'], 'my-store/my-products/{mediaId}', [NetworkStoreController::class, 'updateMyProduct'])->whereNumber('mediaId');
    Route::delete('my-store/my-products/{mediaId}', [NetworkStoreController::class, 'deleteMyProduct'])->whereNumber('mediaId');
});

// Public store profile: numeric user id OR network_code (e.g. MG-W-000092)
Route::get('v1/network-stores/{store}', [NetworkStoreController::class, 'publicShow'])
    ->where('store', '[A-Za-z0-9\-]+');


Route::middleware('api')->prefix('v1/events')->group(function () {
    Route::get('/', [EventController::class, 'index']);
    Route::get('/{id}', [EventController::class, 'show']);
    Route::post('/', [EventController::class, 'store']);
});

Route::middleware(['api', 'auth:sanctum'])->prefix('v1/plumber')->group(function () {
    Route::post('/update-profile', [PlumberProfileController::class, 'updateProfile']);
    Route::post('/work-photos', [PlumberProfileController::class, 'addWorkMedia']);
    Route::get('/work-photos', [PlumberProfileController::class, 'listWorkPhotos']);
    Route::delete('/work-photos/{id}', [PlumberProfileController::class, 'deleteWorkPhoto']);

    Route::get('/social-links', [PlumberProfileController::class, 'listSocialLinks']);
    Route::post('/social-links', [PlumberProfileController::class, 'upsertSocialLinks']);
    Route::delete('/social-links/{linkId}', [PlumberProfileController::class, 'deleteSocialLink']);
});


Route::prefix('v1/blogs')->group(function () {
    Route::post('/', [BlogController::class, 'store']);
    Route::post('/{id}/approve', [BlogController::class, 'approve']);
    Route::get('/', [BlogController::class, 'index']);
    Route::get('/{id}', [BlogController::class, 'show']);
    Route::post('/{id}/like', [BlogController::class, 'like'])->middleware('auth:sanctum');
    Route::post('/{id}/comment', [BlogController::class, 'comment'])->middleware('auth:sanctum');
});



//Route::prefix('v1/products')->group(function () {
//    // READ (public)
//    Route::get('/categories', [ProductController::class, 'categories']);
//    Route::get('/category/{id}', [ProductController::class, 'productsByCategory'])->whereNumber('id');
//    Route::get('/{id}', [ProductController::class, 'show'])->whereNumber('id');
//
//    // WRITE (admin only via auth:sanctum in controller)
//    Route::post('/', [ProductController::class, 'store']);
//    Route::put('/{id}', [ProductController::class, 'update'])->whereNumber('id');
//    Route::delete('/{id}', [ProductController::class, 'destroy'])->whereNumber('id');
//
//    // Gallery helpers
//    Route::post('/{id}/images', [ProductController::class, 'addImages'])->whereNumber('id');
//    Route::delete('/{id}/images/{imageId}', [ProductController::class, 'deleteImage'])->whereNumber('id')->whereNumber('imageId');
//});

Route::prefix('v1/products')->group(function () {
    // READ (public)
    Route::get('/categories', [ProductController::class, 'categories']);
    Route::get('/category/{id}', [ProductController::class, 'productsByCategory'])->whereNumber('id');
    Route::get('/{id}', [ProductController::class, 'show'])->whereNumber('id');

    // WRITE (admin only via auth:sanctum middleware inside controller)
    Route::post('/', [ProductController::class, 'store']);
    Route::put('/{id}', [ProductController::class, 'update'])->whereNumber('id');
    Route::delete('/{id}', [ProductController::class, 'destroy'])->whereNumber('id');

    // Gallery helpers
    Route::post('/{id}/images', [ProductController::class, 'addImages'])->whereNumber('id');
    Route::delete('/{id}/images/{imageId}', [ProductController::class, 'deleteImage'])
        ->whereNumber('id')->whereNumber('imageId');
});



Route::middleware('api')->prefix('v1')->group(function () {
    // Countries
    Route::get('countries', [CountryController::class, 'index']);
    Route::get('countries/{id}', [CountryController::class, 'show']);
    Route::get('countries/{id}/cities', [CountryController::class, 'cities']);

    // Cities
    Route::get('cities', [CityController::class, 'index']);           // ?country_id=1 optional filter
    Route::get('cities/{id}', [CityController::class, 'show']);
});




Route::prefix('v1/plumbers')->group(function () {
    Route::get('/', [PlumberPublicController::class, 'index']);   // list
    Route::get('/{id}', [PlumberPublicController::class, 'show']); // show
});




Route::prefix('v1')->group(function () {

    Route::middleware(['auth:sanctum'])->group(function () {

        Route::prefix('distributions')->group(function () {
            Route::get('/', [DistributionController::class, 'index']);
            Route::post('/', [DistributionController::class, 'store']);
            Route::get('/{distribution}', [DistributionController::class, 'show']);
            Route::post('/{distribution}/confirm', [DistributionController::class, 'confirm']);
        });

        Route::get('/invoices/{invoice}/items', function (Invoice $invoice) {
            return response()->json([
                'status' => 200,
                'data' => $invoice->items()->with('product')->get(),
            ]);
        });

        Route::get('/invoices/{invoice}/distribution-summary', function (
            Invoice $invoice,
            DistributionService $service
        ) {
            return response()->json([
                'status' => 200,
                'data' => $service->getDistributionSummary($invoice),
            ]);
        });

        Route::prefix('plumber')
            ->group(function () {

                /** Invoices — uploaded by plumber */
                Route::get('invoices/received', [PlumberInvoiceController::class, 'received']);
                Route::get('invoices/{distribution}/detail', [PlumberInvoiceController::class, 'distributionDetail'])
                    ->whereNumber('distribution');

                Route::get('invoices', [PlumberInvoiceController::class, 'index']);
                Route::post('invoices', [PlumberInvoiceController::class, 'store']);
                Route::get('invoices/{invoice}', [PlumberInvoiceController::class, 'show'])
                    ->whereNumber('invoice');

                /** Wallet */
                Route::get('wallet', [PlumberInvoiceController::class, 'wallet']);
                Route::post('wallet/convert', [WalletController::class, 'convert']);
                Route::post('wallet/withdrawals', [WalletController::class, 'requestWithdrawal']);

                /** Work media (images/videos) */
                Route::post('work-photos', [PlumberProfileController::class, 'addWorkMedia']);   // POST /api/v1/plumber/work-photos
                Route::get('work-photos',  [PlumberProfileController::class, 'listWorkPhotos']); // GET  /api/v1/plumber/work-photos
                Route::delete('work-photos/{id}', [PlumberProfileController::class, 'deleteWorkPhoto']); // DELETE /api/v1/plumber/work-photos/{id}

                /** Social links */
                Route::get('social-links', [PlumberProfileController::class, 'listSocialLinks']);
                Route::post('social-links', [PlumberProfileController::class, 'upsertSocialLinks']);
                Route::delete('social-links/{linkId}', [PlumberProfileController::class, 'deleteSocialLink']);
            });
    });

});



Route::middleware(['auth:sanctum'])->prefix('vendor')->group(function () {
    // Slider images CRUD for a vendor-owned store
    Route::get   ('/stores/{store}/slider-images',                   [VendorStoreSliderController::class, 'index']);
    Route::post  ('/stores/{store}/slider-images',                   [VendorStoreSliderController::class, 'store']);        // multiple upload
    Route::patch ('/stores/{store}/slider-images/reorder',           [VendorStoreSliderController::class, 'reorder']);      // bulk reorder
    Route::patch ('/stores/{store}/slider-images/{image}',           [VendorStoreSliderController::class, 'update']);       // update one
    Route::patch ('/stores/{store}/slider-images/{image}/primary',   [VendorStoreSliderController::class, 'setPrimary']);   // set primary
    Route::delete('/stores/{store}/slider-images/{image}',           [VendorStoreSliderController::class, 'destroy']);      // delete
});


// Public wallet visibility (apps read this to hide/show the wallet tab)
Route::prefix('ios')->group(function () {
    Route::get('wallet-visibility', [IosWalletVisibilityController::class, 'show'])
        ->name('ios.wallet.visibility.show');
});

// Admin toggle — auth + admin/super_admin role enforced in controller
Route::middleware(['auth:sanctum'])->prefix('admin/ios')->group(function () {
    Route::match(['put', 'post'], 'wallet-visibility', [IosWalletVisibilityController::class, 'update'])
        ->name('ios.wallet.visibility.update');
    Route::post('wallet-visibility/toggle', [IosWalletVisibilityController::class, 'toggle'])
        ->name('ios.wallet.visibility.toggle');
});

// ── Mobile API v1 (organized for iOS/Android) ─────────────────
require __DIR__.'/api/mobile.php';