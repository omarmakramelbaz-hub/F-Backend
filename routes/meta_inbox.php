<?php

use App\Http\Controllers\Dashboard\MetaInboxController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'admin/meta', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::get('/', [MetaInboxController::class, 'index'])->name('meta-inbox.index');
    Route::get('/conversations', [MetaInboxController::class, 'conversations'])->name('meta-inbox.conversations');
    Route::get('/conversations/{conversation}/messages', [MetaInboxController::class, 'messages'])
        ->whereNumber('conversation')->name('meta-inbox.messages');
});
