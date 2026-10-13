<?php

use App\Http\Controllers\Dashboard\WhatsAppReplyController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'admin/whatsapp', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::get('/conversations/{conversation}/reply-state', [WhatsAppReplyController::class, 'state'])
        ->whereNumber('conversation')->name('whatsapp-replies.state');
    Route::post('/conversations/{conversation}/replies', [WhatsAppReplyController::class, 'send'])
        ->whereNumber('conversation')->name('whatsapp-replies.send');
    Route::get('/conversations/{conversation}/replies/history', [WhatsAppReplyController::class, 'history'])
        ->whereNumber('conversation')->name('whatsapp-replies.history');
    Route::post('/conversations/{conversation}/voice', [WhatsAppReplyController::class, 'voice'])
        ->whereNumber('conversation')->name('whatsapp-replies.voice');
    Route::post('/conversations/{conversation}/attachment', [WhatsAppReplyController::class, 'attachment'])
        ->whereNumber('conversation')->name('whatsapp-replies.attachment');
    Route::get('/conversations/{conversation}/replies/{reply}/attachment', [WhatsAppReplyController::class, 'downloadAttachment'])
        ->whereNumber('conversation')->whereNumber('reply')->name('whatsapp-replies.attachment-download');
    Route::post('/conversations/{conversation}/messages/{message}/forward', [WhatsAppReplyController::class, 'forward'])
        ->whereNumber('conversation')->whereNumber('message')->name('whatsapp-replies.forward');
    Route::post('/conversations/{conversation}/messages/{message}/reaction', [WhatsAppReplyController::class, 'reaction'])
        ->whereNumber('conversation')->whereNumber('message')->name('whatsapp-replies.reaction');
    Route::post('/conversations/{conversation}/replies/{reply}/forward', [WhatsAppReplyController::class, 'forwardReply'])
        ->whereNumber('conversation')->whereNumber('reply')->name('whatsapp-replies.forward-reply');
    Route::post('/conversations/{conversation}/replies/{reply}/reaction', [WhatsAppReplyController::class, 'reactionReply'])
        ->whereNumber('conversation')->whereNumber('reply')->name('whatsapp-replies.reaction-reply');
});
