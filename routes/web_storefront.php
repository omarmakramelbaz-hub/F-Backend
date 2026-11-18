<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Site\StorefrontController;

// Included from web.php: session cookies and CSRF apply to every command.
Route::prefix('shop')->name('storefront.')->group(function () {
    Route::post('branches',[StorefrontController::class,'branches'])->middleware('throttle:20,1')->name('branches');
    Route::get('catalog',[StorefrontController::class,'catalog'])->middleware('throttle:90,1')->name('catalog');
    Route::post('addresses',[StorefrontController::class,'suggestions'])->middleware('throttle:20,1')->name('addresses');
    Route::post('quote',[StorefrontController::class,'quote'])->middleware('throttle:15,1')->name('quote');
    Route::post('checkout',[StorefrontController::class,'checkout'])->middleware('throttle:6,1')->name('checkout');
});
