<?php
use Illuminate\Support\Facades\Route;

Route::middleware(['lang','IsAdmin'])->post('admin/desktop-dashboard/enroll',[\App\Http\Controllers\Dashboard\DesktopDashboardController::class,'enroll'])->name('desktop-dashboard.enroll');
