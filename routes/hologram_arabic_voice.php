<?php
use App\Http\Controllers\Dashboard\HomeHologramArabicVoiceController;
use Illuminate\Support\Facades\Route;

if (app('router')->getGroupStack() || app()->bound('go.hologram.arabic.routes.loaded')) return;
app()->instance('go.hologram.arabic.routes.loaded', true);

Route::prefix('admin')->middleware(['web', 'lang', 'IsAdmin'])->group(function () {
    Route::post('dashboard/hologram-arabic/speak', [HomeHologramArabicVoiceController::class, 'speak'])
        ->middleware('throttle:12,1')->name('dashboard.hologram-arabic.speak');
});
