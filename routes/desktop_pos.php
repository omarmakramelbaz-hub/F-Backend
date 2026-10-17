<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Dashboard\DesktopPosController;

Route::middleware(['lang','IsAdmin'])->prefix('admin/desktop-pos')->name('desktop-pos.')->group(function () {
    Route::get('/',[DesktopPosController::class,'index'])->name('index');
    Route::post('pair',[DesktopPosController::class,'issue'])->name('issue');
    Route::post('revoke',[DesktopPosController::class,'revoke'])->name('revoke');
    Route::get('download',[DesktopPosController::class,'download'])->name('download');
    Route::post('upload/start',[DesktopPosController::class,'uploadStart'])->name('upload-start');
    Route::post('upload/chunk',[DesktopPosController::class,'uploadChunk'])->name('upload-chunk');
    Route::post('upload/finish',[DesktopPosController::class,'uploadFinish'])->name('upload-finish');
});
