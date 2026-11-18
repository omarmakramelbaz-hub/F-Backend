<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Dashboard\DesktopDashboardWorkspaceController;

Route::middleware(['lang','IsAdmin',\App\Http\Middleware\DesktopDashboardWorkspaceEnabled::class])
    ->prefix('admin/desktop-dashboard/workspace')->name('desktop-dashboard.workspace.')->group(function(){
        Route::get('challenge',[DesktopDashboardWorkspaceController::class,'challenge'])->name('challenge');
        Route::post('enroll',[DesktopDashboardWorkspaceController::class,'enroll'])->name('enroll');
        Route::post('session',[DesktopDashboardWorkspaceController::class,'session'])->name('session');
        Route::get('local-session',[DesktopDashboardWorkspaceController::class,'localSession'])->name('local-session');
    });
