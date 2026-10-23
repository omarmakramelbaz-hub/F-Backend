<?php
use Illuminate\Support\Facades\Route;

Route::middleware(['lang','IsAdmin'])->get('admin/desktop-dashboard',[\App\Http\Controllers\Dashboard\DesktopDashboardController::class,'index'])->name('desktop-dashboard.index');
Route::middleware(['lang','IsAdmin'])->get('admin/desktop-dashboard/download',[\App\Http\Controllers\Dashboard\DesktopDashboardController::class,'download'])->name('desktop-dashboard.download');
Route::middleware(['lang','IsAdmin'])->post('admin/desktop-dashboard/enroll',[\App\Http\Controllers\Dashboard\DesktopDashboardController::class,'enroll'])->name('desktop-dashboard.enroll');
Route::middleware(['lang','IsAdmin'])->get('admin/desktop-dashboard/session',[\App\Http\Controllers\Dashboard\DesktopDashboardController::class,'session'])->name('desktop-dashboard.session');
