<?php
use App\Http\Controllers\Dashboard\BranchShiftClosingController;
use Illuminate\Support\Facades\Route;
Route::middleware(['lang','IsAdmin'])->prefix('admin/branch-shifts')->name('branch-shifts.')->group(function(){
    Route::get('/',[BranchShiftClosingController::class,'index'])->name('index');
    foreach(['data','recover'] as $method)Route::get($method,[BranchShiftClosingController::class,$method])->name($method);
    Route::post('close',[BranchShiftClosingController::class,'close'])->name('close');
    Route::get('{id}/print',[BranchShiftClosingController::class,'print'])->whereNumber('id')->name('print');
});
