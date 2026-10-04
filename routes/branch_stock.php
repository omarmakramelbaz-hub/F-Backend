<?php
use App\Http\Controllers\Dashboard\BranchStockController;
use Illuminate\Support\Facades\Route;
Route::middleware(['lang','IsAdmin'])->prefix('admin/branch-stock')->name('branch-stock.')->group(function(){
    Route::get('/',[BranchStockController::class,'index'])->name('index');
    Route::get('data',[BranchStockController::class,'data'])->name('data');
    Route::get('recipes',[BranchStockController::class,'recipes'])->name('recipes');
    Route::post('recipes',[BranchStockController::class,'saveRecipe'])->name('recipe-save');
    Route::post('receive',[BranchStockController::class,'receive'])->name('receive');
});
