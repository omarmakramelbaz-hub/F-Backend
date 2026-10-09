<?php

use App\Http\Controllers\Api\V1\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// This API endpoint uses Meta signature authentication, independently of user login.
Route::withoutMiddleware([\App\Http\Middleware\EnsureGoSchema::class])->group(function () {
    Route::get('whatsapp/webhook', [WhatsAppWebhookController::class, 'verify'])
        ->name('whatsapp.webhook.verify');
    Route::post('whatsapp/webhook', [WhatsAppWebhookController::class, 'receive'])
        ->name('whatsapp.webhook.receive');
});
