<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Site\StorefrontSeoController;

// Editorial pages contain no branch inventory, prices, or customer location data.
Route::get('/products', [StorefrontSeoController::class, 'index'])->name('storefront.products');
Route::get('/products/{slug}', [StorefrontSeoController::class, 'category'])
    ->where('slug', 'feseekh|renga|sardines|bottarga')->name('storefront.product-category');
Route::get('/sitemap.xml', [StorefrontSeoController::class, 'sitemap'])->name('storefront.sitemap');
