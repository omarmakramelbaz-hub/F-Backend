<?php
// Loaded inside admin's existing authenticated web/IsAdmin group. Every action also checks the persisted primary owner.
use App\Http\Controllers\Dashboard\OwnersController;
use Illuminate\Support\Facades\Route;
Route::get('owners',[OwnersController::class,'index'])->name('owners.index');
Route::get('owners/create',[OwnersController::class,'create'])->name('owners.create');
Route::post('owners',[OwnersController::class,'store'])->middleware('throttle:10,1')->name('owners.store');
Route::get('owners/{owner}/edit',[OwnersController::class,'edit'])->name('owners.edit');
Route::put('owners/{owner}',[OwnersController::class,'update'])->name('owners.update');
Route::post('owners/{owner}/state',[OwnersController::class,'state'])->name('owners.state');
Route::get('owners/{owner}/status',[OwnersController::class,'status'])->name('owners.status');
