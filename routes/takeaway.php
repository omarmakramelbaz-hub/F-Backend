<?php

use App\Http\Controllers\Dashboard\TakeawayController;
use Illuminate\Support\Facades\Route;

Route::middleware(['lang', 'IsAdmin'])->prefix('admin/takeaway')->group(function () {
    Route::get('/', [TakeawayController::class, 'index'])->name('takeaway.index');
    Route::get('catalog', [TakeawayController::class, 'catalog'])->name('takeaway.catalog');
    Route::post('quote', [TakeawayController::class, 'quote'])->name('takeaway.quote');
    Route::post('checkout', [TakeawayController::class, 'checkout'])->name('takeaway.checkout');
    Route::get('receipts', [TakeawayController::class, 'receipts'])->name('takeaway.receipts');
    Route::get('receipts/{id}/details', [TakeawayController::class, 'details'])->whereNumber('id')->name('takeaway.details');
    Route::get('receipts/{id}/print', [TakeawayController::class, 'print'])->whereNumber('id')->name('takeaway.print');
    Route::get('till', [TakeawayController::class, 'till'])->name('takeaway.till');
    Route::post('till/movements', [TakeawayController::class, 'movement'])->name('takeaway.movements');
    Route::post('till/settings', [TakeawayController::class, 'settings'])->name('takeaway.settings');
});
