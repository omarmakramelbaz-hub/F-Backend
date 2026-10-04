<?php

use App\Http\Controllers\Dashboard\DineInController;
use App\Http\Controllers\Dashboard\PhoneOrdersController;
use Illuminate\Support\Facades\Route;

Route::middleware(['lang', 'IsAdmin'])->group(function () {
    Route::get('admin/branch-orders', [\App\Http\Controllers\Dashboard\BranchOrdersController::class, 'index'])->name('branch-orders.index');
    foreach (['dining' => DineInController::class, 'phone-orders' => PhoneOrdersController::class] as $name => $controller) {
        Route::prefix('admin/'.$name)->name($name.'.')->group(function () use ($controller) {
            Route::get('/', [$controller, 'index'])->name('index');
            Route::get('catalog', [$controller, 'catalog'])->name('catalog');
            Route::post('quote', [$controller, 'quote'])->name('quote');
            Route::get('tickets', [$controller, 'tickets'])->name('tickets');
            Route::post('tickets/save', [$controller, 'save'])->name('save');
            Route::get('recover', [$controller, 'recover'])->name('recover');
            Route::get('tickets/{id}', [$controller, 'show'])->whereNumber('id')->name('show');
            Route::post('tickets/{id}/action', [$controller, 'action'])->whereNumber('id')->name('action');
            Route::post('tickets/{id}/settle', [$controller, 'settle'])->whereNumber('id')->name('settle');
            Route::get('tickets/{id}/details', [$controller, 'details'])->whereNumber('id')->name('details');
            Route::get('tickets/{id}/print', [$controller, 'print'])->whereNumber('id')->name('print');
            Route::get('kitchen/{id}/print', [$controller, 'kitchen'])->whereNumber('id')->name('kitchen');
        });
    }
    Route::prefix('admin/dining')->name('dining.')->group(function () {
        Route::get('tables', [DineInController::class, 'tables'])->name('tables');
        Route::post('tables', [DineInController::class, 'tableSave'])->name('table-save');
        Route::post('settings', [DineInController::class, 'settings'])->name('settings');
    });
    Route::get('admin/phone-orders/delivery-settings',[PhoneOrdersController::class,'deliverySettings'])->name('phone-orders.delivery-settings');
    Route::post('admin/phone-orders/delivery-quote',[PhoneOrdersController::class,'deliveryQuote'])->name('phone-orders.delivery-quote');
    Route::get('admin/phone-orders/customers', [PhoneOrdersController::class, 'customers'])->name('phone-orders.customers');
    Route::get('admin/phone-orders/print-jobs', [PhoneOrdersController::class, 'printJobs'])->name('phone-orders.print-jobs');
    Route::post('admin/phone-orders/print-jobs/claim', [PhoneOrdersController::class, 'printClaim'])->name('phone-orders.print-claim');
    Route::post('admin/phone-orders/print-jobs/complete', [PhoneOrdersController::class, 'printComplete'])->name('phone-orders.print-complete');
});

require __DIR__.'/branch_expenses.php';

require __DIR__.'/branch_operations.php';

require __DIR__.'/branch_shifts.php';
