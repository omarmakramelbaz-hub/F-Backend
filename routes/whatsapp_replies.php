<?php

use App\Http\Controllers\Dashboard\WhatsAppReplyController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'admin/whatsapp', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::get('/conversations/{conversation}/reply-state', [WhatsAppReplyController::class, 'state'])
        ->whereNumber('conversation')->name('whatsapp-replies.state');
    Route::post('/conversations/{conversation}/replies', [WhatsAppReplyController::class, 'send'])
        ->whereNumber('conversation')->name('whatsapp-replies.send');
    Route::post('/conversations/{conversation}/voice', [WhatsAppReplyController::class, 'voice'])
        ->whereNumber('conversation')->name('whatsapp-replies.voice');
});
