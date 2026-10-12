<?php

use App\Http\Controllers\Dashboard\MetaReplyController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'admin/meta', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::get('/conversations/{conversation}/reply-state', [MetaReplyController::class, 'state'])
        ->whereNumber('conversation')->name('meta-replies.state');
    Route::post('/conversations/{conversation}/replies', [MetaReplyController::class, 'send'])
        ->whereNumber('conversation')->name('meta-replies.send');
    Route::post('/conversations/{conversation}/voice', [MetaReplyController::class, 'voice'])
        ->whereNumber('conversation')->name('meta-replies.voice');
});
