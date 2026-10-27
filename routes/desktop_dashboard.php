<?php
use Illuminate\Support\Facades\Route;

require __DIR__.'/desktop_workspace.php';

Route::middleware(['lang','IsAdmin'])->get('admin/desktop-dashboard',[\App\Http\Controllers\Dashboard\DesktopDashboardController::class,'index'])->name('desktop-dashboard.index');
Route::middleware(['lang','IsAdmin'])->get('admin/desktop-dashboard/download',[\App\Http\Controllers\Dashboard\DesktopDashboardController::class,'download'])->name('desktop-dashboard.download');
Route::middleware(['lang','IsAdmin'])->get('admin/desktop-dashboard/published/{sha}',[\App\Http\Controllers\Dashboard\DesktopDashboardController::class,'publishedDownload'])->where('sha','[a-f0-9]{64}')->name('desktop-dashboard.published.download');
Route::middleware(['lang','IsAdmin'])->get('admin/desktop-dashboard/release/status',[\App\Http\Controllers\Dashboard\DesktopDashboardController::class,'releaseStatus'])->name('desktop-dashboard.release.status');
Route::middleware(['lang','IsAdmin'])->post('admin/desktop-dashboard/release/request',[\App\Http\Controllers\Dashboard\DesktopDashboardController::class,'requestRelease'])->name('desktop-dashboard.release.request');
Route::middleware(['lang','IsAdmin'])->post('admin/desktop-dashboard/enroll',[\App\Http\Controllers\Dashboard\DesktopDashboardController::class,'enroll'])->name('desktop-dashboard.enroll');
Route::middleware(['lang','IsAdmin'])->get('admin/desktop-dashboard/session',[\App\Http\Controllers\Dashboard\DesktopDashboardController::class,'session'])->name('desktop-dashboard.session');
