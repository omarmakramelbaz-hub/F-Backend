<?php

use App\Http\Controllers\Api\DesktopSyncController;
use Illuminate\Support\Facades\Route;

// GO ERP desktop app: one-time device setup, then a sync every 30 seconds per device.
Route::prefix('desktop-sync/v1')->name('desktop-sync.')->group(function () {
    Route::post('enroll', [DesktopSyncController::class, 'enroll'])->middleware('throttle:10,1')->name('enroll');
    Route::post('sync', [DesktopSyncController::class, 'sync'])->middleware('throttle:240,1')->name('sync');
    // The device gives up its setup, e.g. to be set up again with another account.
    Route::post('release', [DesktopSyncController::class, 'release'])->middleware('throttle:10,1')->name('release');
});
