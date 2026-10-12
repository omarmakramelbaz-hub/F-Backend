<?php

use App\Http\Controllers\Api\V1\MetaMessagingWebhookController;
use Illuminate\Support\Facades\Route;

// This API endpoint uses Meta signature authentication, independently of user login.
Route::withoutMiddleware([\App\Http\Middleware\EnsureGoSchema::class])->group(function () {
    Route::get('meta/messaging/webhook', [MetaMessagingWebhookController::class, 'verify'])
        ->name('meta-messaging.webhook.verify');
    Route::post('meta/messaging/webhook', [MetaMessagingWebhookController::class, 'receive'])
        ->name('meta-messaging.webhook.receive');
});
