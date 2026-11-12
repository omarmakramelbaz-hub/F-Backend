<?php

use App\Http\Controllers\Api\V1\User\OrderLiveActivityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['CheckLang', 'auth:api', 'app.scope', 'custom.jwt'])->group(function () {
    Route::post('/user/order/{id}/live-activity', [OrderLiveActivityController::class, 'store'])->whereNumber('id');
    Route::delete('/user/order/{id}/live-activity', [OrderLiveActivityController::class, 'destroy'])->whereNumber('id');
    // The addresses the customer app calls (its order and field names in the body).
    Route::post('/user/order-activity-subscriptions', [OrderLiveActivityController::class, 'subscribe'])->middleware('throttle:30,1');
    Route::post('/user/order-activity-subscriptions/unregister', [OrderLiveActivityController::class, 'unsubscribe'])->middleware('throttle:30,1');
});
