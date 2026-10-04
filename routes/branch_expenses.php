<?php
use App\Http\Controllers\Dashboard\BranchExpensesController;
use Illuminate\Support\Facades\Route;
Route::middleware(['lang','IsAdmin'])->prefix('admin/branch-expenses')->name('branch-expenses.')->group(function(){
    foreach(['index'=>'/','data'=>'data','recover'=>'recover','export'=>'export','report'=>'report'] as $action=>$path)Route::get($path,[BranchExpensesController::class,$action])->name($action);
    Route::post('save',[BranchExpensesController::class,'save'])->name('save');
    Route::get('{id}',[BranchExpensesController::class,'show'])->whereNumber('id')->name('show');
    Route::post('{id}/review',[BranchExpensesController::class,'review'])->whereNumber('id')->name('review');
    Route::get('{id}/attachment',[BranchExpensesController::class,'attachment'])->whereNumber('id')->name('attachment');
    Route::get('{id}/print',[BranchExpensesController::class,'print'])->whereNumber('id')->name('print');
});

Route::middleware(['lang','IsAdmin'])->prefix('admin/print-settings')->name('print-settings.')->group(function(){
    Route::get('/',[\App\Http\Controllers\Dashboard\PrintSettingsController::class,'index'])->name('index');
    Route::get('test',[\App\Http\Controllers\Dashboard\PrintSettingsController::class,'test'])->name('test');
});
