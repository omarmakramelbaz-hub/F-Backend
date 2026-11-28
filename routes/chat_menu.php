<?php

use App\Http\Controllers\Site\ChatMenuController;
use Illuminate\Support\Facades\Route;

// Loaded from web.php: session cookies and CSRF apply.
// «إرسال المنيو»: the customer's personal menu link from a WhatsApp/Messenger/Instagram chat.
Route::get('/m/{token}', [ChatMenuController::class, 'show'])->middleware('throttle:60,1')->name('chat-menu.show');
Route::get('/m/{token}/menu', [ChatMenuController::class, 'menu'])->middleware('throttle:60,1')->name('chat-menu.menu');
Route::post('/m/{token}', [ChatMenuController::class, 'submit'])->middleware('throttle:6,1')->name('chat-menu.submit');

Route::group(['prefix' => 'admin/chat-menu', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::post('/link', [ChatMenuController::class, 'link'])->middleware('throttle:30,1')->name('chat-menu.link');
});
