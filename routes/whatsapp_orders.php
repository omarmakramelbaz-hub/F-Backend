<?php

use App\Http\Controllers\Dashboard\WhatsAppOrderController;
use Illuminate\Support\Facades\Route;

// Loaded from the existing web routes, preserving session authentication and CSRF protection.
Route::group(['prefix' => 'admin/whatsapp', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::get('/orders/meta', [WhatsAppOrderController::class, 'meta'])->name('whatsapp-orders.meta');
    Route::get('/orders/catalog', [WhatsAppOrderController::class, 'catalog'])->name('whatsapp-orders.catalog');
    Route::get('/conversations/{conversation}/orders', [WhatsAppOrderController::class, 'state'])
        ->whereNumber('conversation')->name('whatsapp-orders.state');
    Route::post('/conversations/{conversation}/orders/analyze', [WhatsAppOrderController::class, 'analyze'])
        ->whereNumber('conversation')->name('whatsapp-orders.analyze');
    Route::post('/orders/{draft}/quote', [WhatsAppOrderController::class, 'quote'])->whereNumber('draft')->name('whatsapp-orders.quote');
    Route::post('/orders/{draft}/dispatch', [WhatsAppOrderController::class, 'submitOrder'])->whereNumber('draft')->name('whatsapp-orders.dispatch');
});
