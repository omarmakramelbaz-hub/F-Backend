<?php

use App\Http\Controllers\Dashboard\WhatsAppInboxController;
use App\Http\Controllers\Dashboard\WhatsAppInboxMessageController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'admin/whatsapp', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::get('/', [WhatsAppInboxController::class, 'index'])->name('whatsapp-inbox.index');
    Route::get('/conversations', [WhatsAppInboxController::class, 'conversations'])->name('whatsapp-inbox.conversations');
    Route::get('/conversations/{conversation}/messages', [WhatsAppInboxController::class, 'messages'])
        ->whereNumber('conversation')->name('whatsapp-inbox.messages');
    Route::get('/conversations/{conversation}/messages/selection', [WhatsAppInboxMessageController::class, 'selection'])
        ->whereNumber('conversation')->name('whatsapp-inbox.selection');
    Route::get('/conversations/{conversation}/messages/updates', [WhatsAppInboxMessageController::class, 'updates'])
        ->whereNumber('conversation')->name('whatsapp-inbox.updates');
    Route::post('/conversations/{conversation}/messages/delete', [WhatsAppInboxMessageController::class, 'deleteSelected'])
        ->whereNumber('conversation')->name('whatsapp-inbox.delete-selected');
    Route::get('/conversations/{conversation}/messages/{message}/media', [WhatsAppInboxMessageController::class, 'media'])
        ->whereNumber('conversation')->whereNumber('message')->name('whatsapp-inbox.media');
    Route::get('/conversations/{conversation}/replies/{reply}/media', [WhatsAppInboxMessageController::class, 'replyMedia'])
        ->whereNumber('conversation')->whereNumber('reply')->name('whatsapp-inbox.reply-media');
    Route::post('/conversations/{conversation}/messages/{message}/delete', [WhatsAppInboxMessageController::class, 'delete'])
        ->whereNumber('conversation')->whereNumber('message')->name('whatsapp-inbox.delete');
});
