<?php

use App\Http\Controllers\MediaThumbController;
use Illuminate\Support\Facades\Route;

// Small copies of product and branch photos (App\Support\MediaThumb): reached only while a copy does not exist yet.
// No session or cookies: a picture request carries none.
Route::get('storage/thumbs/{box}/{disk}/{id}/{name}.webp', [MediaThumbController::class, 'show'])
    ->where(['box' => '[0-9]+', 'disk' => '[a-z_]+', 'id' => '[0-9]+', 'name' => '[^/]+'])
    ->middleware('throttle:600,1')
    ->name('media-thumbs.show');
