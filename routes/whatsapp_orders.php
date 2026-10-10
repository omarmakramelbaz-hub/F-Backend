<?php

use App\Http\Controllers\Dashboard\WhatsAppOrderController;
use Illuminate\Support\Facades\Route;

// Loaded from the existing web routes, preserving session authentication and CSRF protection.
Route::group(['prefix' => 'admin/whatsapp', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::get('/orders/meta', [WhatsAppOrderController::class, 'meta'])->name('whatsapp-orders.meta');
    Route::get('/orders/catalog', [WhatsAppOrderController::class, 'catalog'])->name('whatsapp-orders.catalog');
    Route::get('/orders/customers', [WhatsAppOrderController::class, 'customers'])->name('whatsapp-orders.customers');
    Route::get('/orders/delivery-settings', [WhatsAppOrderController::class, 'deliverySettings'])->name('whatsapp-orders.delivery-settings');
    Route::get('/orders/address-suggestions', [WhatsAppOrderController::class, 'addressSuggestions'])->name('whatsapp-orders.address-suggestions');
    Route::post('/orders/delivery-quote', [WhatsAppOrderController::class, 'deliveryQuote'])->name('whatsapp-orders.delivery-quote');
    Route::get('/conversations/{conversation}/orders', [WhatsAppOrderController::class, 'state'])
        ->whereNumber('conversation')->name('whatsapp-orders.state');
    Route::post('/conversations/{conversation}/orders/analyze', [WhatsAppOrderController::class, 'analyze'])
        ->whereNumber('conversation')->name('whatsapp-orders.analyze');
    Route::post('/orders/{draft}/quote', [WhatsAppOrderController::class, 'quote'])->whereNumber('draft')->name('whatsapp-orders.quote');
    Route::post('/orders/{draft}/dispatch', [WhatsAppOrderController::class, 'submitOrder'])->whereNumber('draft')->name('whatsapp-orders.dispatch');
});
