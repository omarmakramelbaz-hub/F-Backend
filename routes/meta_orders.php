<?php

use App\Http\Controllers\Dashboard\MetaOrderController;
use Illuminate\Support\Facades\Route;

// Loaded from the existing web routes, preserving session authentication and CSRF protection.
Route::group(['prefix' => 'admin/meta', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::get('/orders/meta', [MetaOrderController::class, 'meta'])->name('meta-orders.meta');
    Route::get('/orders/catalog', [MetaOrderController::class, 'catalog'])->name('meta-orders.catalog');
    Route::get('/orders/customers', [MetaOrderController::class, 'customers'])->name('meta-orders.customers');
    Route::get('/orders/delivery-settings', [MetaOrderController::class, 'deliverySettings'])->name('meta-orders.delivery-settings');
    Route::get('/orders/address-suggestions', [MetaOrderController::class, 'addressSuggestions'])->name('meta-orders.address-suggestions');
    Route::post('/orders/delivery-quote', [MetaOrderController::class, 'deliveryQuote'])->name('meta-orders.delivery-quote');
    Route::get('/conversations/{conversation}/orders', [MetaOrderController::class, 'state'])
        ->whereNumber('conversation')->name('meta-orders.state');
    Route::post('/conversations/{conversation}/orders/analyze', [MetaOrderController::class, 'analyze'])
        ->whereNumber('conversation')->name('meta-orders.analyze');
    Route::post('/orders/{draft}/quote', [MetaOrderController::class, 'quote'])->whereNumber('draft')->name('meta-orders.quote');
    Route::post('/orders/{draft}/dispatch', [MetaOrderController::class, 'submitOrder'])->whereNumber('draft')->name('meta-orders.dispatch');
});
