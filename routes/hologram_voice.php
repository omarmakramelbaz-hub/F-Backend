<?php
use App\Http\Controllers\Dashboard\HomeHologramVoiceController;
use Illuminate\Support\Facades\Route;

// The provider loads this standalone group with Laravel's route-cache guard.
// A legacy include inside another group must not duplicate or alter its prefix.
if (app('router')->getGroupStack() || app()->bound('go.hologram.voice.routes.loaded')) return;
app()->instance('go.hologram.voice.routes.loaded', true);

Route::prefix('admin')->middleware(['web', 'lang', 'IsAdmin'])->group(function () {
    Route::get('dashboard/hologram-voice/{ticket}/status', [HomeHologramVoiceController::class, 'status'])
        ->where('ticket', '[a-f0-9]{64}')->name('dashboard.hologram-voice.status');
    Route::get('dashboard/hologram-voice/{ticket}/audio', [HomeHologramVoiceController::class, 'audio'])
        ->where('ticket', '[a-f0-9]{64}')->name('dashboard.hologram-voice.audio');
});
