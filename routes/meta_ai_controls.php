<?php

use App\Http\Controllers\Dashboard\MetaAiControlController;
use Illuminate\Support\Facades\Route;

// Loaded by the web routes: session authentication and CSRF apply to mutations.
Route::group(['prefix' => 'admin/meta', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::get('/ai-control', [MetaAiControlController::class, 'state'])->name('meta-ai-control.state');
    Route::post('/ai-control', [MetaAiControlController::class, 'update'])->name('meta-ai-control.update');
});
