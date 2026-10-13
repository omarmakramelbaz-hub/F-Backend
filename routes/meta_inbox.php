<?php

use App\Http\Controllers\Dashboard\MetaInboxController;
use App\Http\Controllers\Dashboard\MetaMessageActionsController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'admin/meta', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::get('/', [MetaInboxController::class, 'index'])->name('meta-inbox.index');
    Route::get('/conversations', [MetaInboxController::class, 'conversations'])->name('meta-inbox.conversations');
    Route::get('/conversations/{conversation}/messages', [MetaInboxController::class, 'messages'])
        ->whereNumber('conversation')->name('meta-inbox.messages');
    Route::get('/conversations/{conversation}/messages/{message}/media/{index}', [MetaMessageActionsController::class, 'media'])
        ->whereNumber(['conversation', 'message', 'index'])->name('meta-inbox.media');
    Route::post('/conversations/{conversation}/messages/{message}/hide', [MetaMessageActionsController::class, 'hide'])
        ->whereNumber(['conversation', 'message'])->name('meta-inbox.hide');
});
