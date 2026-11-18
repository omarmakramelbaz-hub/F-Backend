<?php

use App\Http\Controllers\Dashboard\OrderBoardController;
use App\Http\Controllers\Dashboard\OrderBoardMenuController;
use Illuminate\Support\Facades\Route;

Route::middleware(['lang', 'IsAdmin'])->prefix('admin/order-board')
    ->where(['source'=>'legacy|store|service|partner_service|website', 'id'=>'[0-9]+'])->group(function () {
    Route::get('menu', [OrderBoardMenuController::class, 'index'])->name('order-board.menu');
    Route::post('menu/{kind}/{branchId}/products/{product}/availability', [OrderBoardMenuController::class, 'availability'])
        ->where(['kind'=>'f|gs', 'branchId'=>'[0-9]+', 'product'=>'[0-9]+'])->name('order-board.menu.availability');
    Route::get('{source}/{id}', [OrderBoardController::class, 'details'])->name('order-board.details');
    Route::get('{source}/{id}/print', [OrderBoardController::class, 'print'])->name('order-board.print');
    Route::post('{source}/{id}/print-remote', [OrderBoardController::class, 'printRemote'])->name('order-board.print-remote');
    Route::post('{source}/{id}/action', [OrderBoardController::class, 'action'])->name('order-board.action');
    Route::get('{source}/{id}/items', [OrderBoardController::class, 'items'])->name('order-board.items');
    Route::post('{source}/{id}/items', [OrderBoardController::class, 'saveItems'])->name('order-board.items.save');
});

// A store's app orders print on the device of the branch that prepares them (Shubra), which takes them from here.
Route::middleware(['lang', 'IsAdmin'])->prefix('admin/app-order-print')->name('app-order-print.')->group(function () {
    Route::get('jobs', [OrderBoardController::class, 'branchPrintJobs'])->name('jobs');
    Route::post('claim', [OrderBoardController::class, 'branchPrintClaim'])->name('claim');
    Route::post('complete', [OrderBoardController::class, 'branchPrintComplete'])->name('complete');
    Route::get('{id}/receipt', [OrderBoardController::class, 'branchPrintReceipt'])->whereNumber('id')->name('receipt');
});
