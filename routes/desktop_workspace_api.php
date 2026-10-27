<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DesktopDashboardWorkspaceController;

Route::withoutMiddleware('throttle:api')->middleware([\App\Http\Middleware\DesktopDashboardWorkspaceEnabled::class])
    ->prefix('desktop-dashboard/workspace')->name('desktop-dashboard.workspace-api.')->group(function(){
        Route::get('manifest',[DesktopDashboardWorkspaceController::class,'manifest'])->middleware('throttle:desktop-pos')->name('manifest');
        Route::get('bootstrap',[DesktopDashboardWorkspaceController::class,'bootstrap'])->middleware('throttle:desktop-pos')->name('bootstrap');
        Route::post('delta',[DesktopDashboardWorkspaceController::class,'delta'])->middleware('throttle:desktop-pos')->name('delta');
        Route::post('commands',[DesktopDashboardWorkspaceController::class,'ingest'])->middleware('throttle:desktop-pos')->name('commands');
        Route::post('print-outcomes',[DesktopDashboardWorkspaceController::class,'printOutcome'])->middleware('throttle:desktop-pos')->name('print-outcomes');
        Route::post('remote-attempts',[DesktopDashboardWorkspaceController::class,'remoteAttempt'])->middleware('throttle:desktop-pos')->name('remote-attempts');
        Route::get('media',[DesktopDashboardWorkspaceController::class,'media'])->middleware('throttle:desktop-dashboard-media')->name('media');
    });
