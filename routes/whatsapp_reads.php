<?php

use App\Http\Controllers\Dashboard\WhatsAppInboxReadController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'admin/whatsapp', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::get('/unread', [WhatsAppInboxReadController::class, 'unread'])->name('whatsapp-inbox.unread');
    Route::post('/conversations/{conversation}/read', [WhatsAppInboxReadController::class, 'read'])
        ->whereNumber('conversation')->name('whatsapp-inbox.read');
});
