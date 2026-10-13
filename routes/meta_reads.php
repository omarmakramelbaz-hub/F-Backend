<?php

use App\Http\Controllers\Dashboard\MetaInboxReadController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'admin/meta', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::get('/unread', [MetaInboxReadController::class, 'unread'])->name('meta-inbox.unread');
    Route::post('/conversations/{conversation}/read', [MetaInboxReadController::class, 'read'])
        ->whereNumber('conversation')->name('meta-inbox.read');
});
