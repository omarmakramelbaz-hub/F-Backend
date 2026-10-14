<?php

use App\Http\Controllers\Dashboard\MetaCommentAiController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix'=>'admin/meta/comments/ai','middleware'=>['lang','IsAdmin']],function () {
    Route::get('/settings',[MetaCommentAiController::class,'status'])->name('meta-comment-ai.status');
    Route::post('/settings',[MetaCommentAiController::class,'update'])->name('meta-comment-ai.update');
});
