<?php

use App\Http\Controllers\Dashboard\MetaCommentsController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'admin/meta/comments', 'middleware' => ['lang', 'IsAdmin']], function () {
    Route::get('/', [MetaCommentsController::class, 'index'])->name('meta-comments.index');
    Route::get('/accounts', [MetaCommentsController::class, 'accounts'])->name('meta-comments.accounts');
    Route::get('/posts', [MetaCommentsController::class, 'posts'])->name('meta-comments.posts');
    Route::get('/comments', [MetaCommentsController::class, 'comments'])->name('meta-comments.comments');
    Route::get('/replies', [MetaCommentsController::class, 'replies'])->name('meta-comments.replies');
    Route::post('/reply', [MetaCommentsController::class, 'reply'])->name('meta-comments.reply');
});
