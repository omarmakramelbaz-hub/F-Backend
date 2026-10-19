<?php

use App\Http\Controllers\Api\V1\User\OrderLiveActivityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['CheckLang', 'auth:api', 'app.scope', 'custom.jwt'])->group(function () {
    Route::post('/user/order/{id}/live-activity', [OrderLiveActivityController::class, 'store'])->whereNumber('id');
    Route::delete('/user/order/{id}/live-activity', [OrderLiveActivityController::class, 'destroy'])->whereNumber('id');
});
