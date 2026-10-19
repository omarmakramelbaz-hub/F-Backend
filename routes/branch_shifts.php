<?php
use App\Http\Controllers\Dashboard\BranchShiftClosingController;
use Illuminate\Support\Facades\Route;
Route::middleware(['lang','IsAdmin'])->prefix('admin/branch-shifts')->name('branch-shifts.')->group(function(){
    Route::get('/',[BranchShiftClosingController::class,'index'])->name('index');
    foreach(['data','recover'] as $method)Route::get($method,[BranchShiftClosingController::class,$method])->name($method);
    Route::post('reprint',[BranchShiftClosingController::class,'reprint'])->name('reprint');
    Route::post('close',[BranchShiftClosingController::class,'close'])->name('close');
    Route::get('{id}/details',[BranchShiftClosingController::class,'details'])->whereNumber('id')->name('details');
    Route::get('{id}/print',[BranchShiftClosingController::class,'print'])->whereNumber('id')->name('print');
});

Route::middleware(['lang','IsAdmin'])->prefix('admin/branch-shift-print')->name('branch-shift-print.')->group(function(){
    Route::get('jobs',[\App\Http\Controllers\Dashboard\BranchShiftPrintingController::class,'jobs'])->name('jobs');
    Route::post('claim',[\App\Http\Controllers\Dashboard\BranchShiftPrintingController::class,'claim'])->name('claim');
    Route::post('complete',[\App\Http\Controllers\Dashboard\BranchShiftPrintingController::class,'complete'])->name('complete');
    Route::get('{id}/receipt',[\App\Http\Controllers\Dashboard\BranchShiftPrintingController::class,'receipt'])->whereNumber('id')->name('receipt');
});
