<?php

use App\Http\Controllers\Dashboard\MetaInboxController;
use App\Http\Controllers\Dashboard\MetaMessageActionsController;
use App\Http\Controllers\Dashboard\MetaConnectionController;
use App\Http\Middleware\MetaOAuthCallbackPrivacy;
use Illuminate\Support\Facades\Route;

require __DIR__.'/meta_ai_controls.php';

// Scrub the callback before language or authentication middleware can retain its URL.
Route::group(['prefix' => 'admin/meta/connection', 'middleware' => [MetaOAuthCallbackPrivacy::class, 'lang', 'IsAdmin']], function () {
    Route::get('/', [MetaConnectionController::class, 'index'])->name('meta-connection.index');
    Route::post('/start', [MetaConnectionController::class, 'start'])->name('meta-connection.start');
    Route::get('/callback', [MetaConnectionController::class, 'callback'])->name('meta-connection.callback');
    Route::post('/confirm', [MetaConnectionController::class, 'confirm'])->name('meta-connection.confirm');
});

Route::group(['prefix' => 'admin/meta', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::get('/', [MetaInboxController::class, 'index'])->name('meta-inbox.index');
    Route::get('/conversations', [MetaInboxController::class, 'conversations'])->name('meta-inbox.conversations');
    Route::post('/conversations/{conversation}/profile', [MetaInboxController::class, 'profile'])
        ->whereNumber('conversation')->name('meta-inbox.profile');
    Route::get('/conversations/{conversation}/messages', [MetaInboxController::class, 'messages'])
        ->whereNumber('conversation')->name('meta-inbox.messages');
    Route::get('/conversations/{conversation}/messages/{message}/media/{index}', [MetaMessageActionsController::class, 'media'])
        ->whereNumber(['conversation', 'message', 'index'])->name('meta-inbox.media');
    Route::post('/conversations/{conversation}/messages/{message}/hide', [MetaMessageActionsController::class, 'hide'])
        ->whereNumber(['conversation', 'message'])->name('meta-inbox.hide');
});
