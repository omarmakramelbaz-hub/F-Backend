<?php

use App\Http\Controllers\Api\V1\MetaMessagingWebhookController;
use App\Http\Controllers\Dashboard\MetaReplyController;
use Illuminate\Support\Facades\Route;

// This API endpoint uses Meta signature authentication, independently of user login.
Route::withoutMiddleware([\App\Http\Middleware\EnsureGoSchema::class])->group(function () {
    Route::get('meta/messaging/webhook', [MetaMessagingWebhookController::class, 'verify'])
        ->name('meta-messaging.webhook.verify');
    Route::post('meta/messaging/webhook', [MetaMessagingWebhookController::class, 'receive'])
        ->name('meta-messaging.webhook.receive');
    Route::get('meta/messaging/media/{ticket}', [MetaReplyController::class, 'temporaryMedia'])
        ->where('ticket', '[0-9a-f]{48}\.[1-9][0-9]{9}\.[0-9a-f]{64}')
        ->name('meta-replies.temporary-media');
});
