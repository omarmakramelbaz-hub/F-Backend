<?php
use App\Http\Controllers\Dashboard\HomeHologramVoiceController;
use Illuminate\Support\Facades\Route;

// Included inside the existing authenticated admin group; no actor/text/path input.
Route::get('dashboard/hologram-voice/{ticket}/status', [HomeHologramVoiceController::class, 'status'])
    ->where('ticket', '[a-f0-9]{64}')->name('dashboard.hologram-voice.status');
Route::get('dashboard/hologram-voice/{ticket}/audio', [HomeHologramVoiceController::class, 'audio'])
    ->where('ticket', '[a-f0-9]{64}')->name('dashboard.hologram-voice.audio');
