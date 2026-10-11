<?php

use App\Http\Controllers\Dashboard\WhatsAppInboxController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'admin/whatsapp', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::get('/', [WhatsAppInboxController::class, 'index'])->name('whatsapp-inbox.index');
    Route::get('/conversations', [WhatsAppInboxController::class, 'conversations'])->name('whatsapp-inbox.conversations');
    Route::get('/conversations/{conversation}/messages', [WhatsAppInboxController::class, 'messages'])
        ->whereNumber('conversation')->name('whatsapp-inbox.messages');
});
