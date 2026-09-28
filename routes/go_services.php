<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\GoServiceMarketplaceController as Services;
Route::get('go-services/capabilities',[Services::class,'capabilities']);
Route::get('go-services/photos/{job}/{index}',[Services::class,'photo'])->middleware('signed')->whereNumber('job')->whereNumber('index')->name('go-services.photo');
Route::post('go-services/paymob/webhook',[Services::class,'webhook'])->withoutMiddleware('throttle:api')->middleware('throttle:300,1')->name('go-services.paymob-webhook');
Route::get('go-services/payment-return',[Services::class,'paymentReturn'])->name('go-services.payment-return');
Route::middleware(['CheckLang','auth:api','app.scope','custom.jwt'])->prefix('go-services')->group(function(){
 Route::get('wallet-status',[Services::class,'walletStatus']);
 Route::get('jobs',[Services::class,'index']);Route::post('jobs',[Services::class,'store'])->middleware('throttle:10,1');
 Route::get('jobs/{job}',[Services::class,'show'])->whereNumber('job');
 Route::post('jobs/{job}/offers',[Services::class,'quote'])->whereNumber('job');
 Route::post('jobs/{job}/offers/{offer}/accept',[Services::class,'accept'])->whereNumber('job')->whereNumber('offer');
 Route::post('jobs/{job}/offers/{offer}/reject',[Services::class,'reject'])->whereNumber('job')->whereNumber('offer');
 Route::post('jobs/{job}/skip',[Services::class,'skip'])->whereNumber('job');
 Route::post('jobs/{job}/status',[Services::class,'status'])->whereNumber('job');
 Route::post('jobs/{job}/checkout',[Services::class,'checkout'])->whereNumber('job')->middleware('throttle:10,1');
});

// GO order payments use the same merchant account with verified server callbacks.
Route::post('go-orders/paymob/webhook',[\App\Http\Controllers\Api\V1\GoOrderPaymentController::class,'webhook'])->withoutMiddleware('throttle:api')->middleware('throttle:300,1')->name('go-orders.paymob-webhook');
Route::get('go-orders/payment-return',[\App\Http\Controllers\Api\V1\GoOrderPaymentController::class,'paymentReturn'])->name('go-orders.payment-return');
Route::post('go-orders/{order}/checkout',[\App\Http\Controllers\Api\V1\GoOrderPaymentController::class,'checkout'])->whereNumber('order')->middleware(['CheckLang','auth:api','app.scope','custom.jwt','throttle:10,1']);
Route::get('go-orders/{order}/payment-status',[\App\Http\Controllers\Api\V1\GoOrderPaymentController::class,'status'])->whereNumber('order')->middleware(['CheckLang','auth:api','app.scope','custom.jwt']);
Route::get('go-orders/capabilities',[\App\Http\Controllers\Api\V1\GoOrderPaymentController::class,'capabilities']);
