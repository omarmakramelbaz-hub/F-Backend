<?php

use App\Http\Controllers\Dashboard\OrderBoardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['lang', 'IsAdmin'])->prefix('admin/order-board')
    ->where(['source'=>'legacy|store|service|partner_service', 'id'=>'[0-9]+'])->group(function () {
    Route::get('{source}/{id}', [OrderBoardController::class, 'details'])->name('order-board.details');
    Route::get('{source}/{id}/print', [OrderBoardController::class, 'print'])->name('order-board.print');
    Route::post('{source}/{id}/action', [OrderBoardController::class, 'action'])->name('order-board.action');
});
