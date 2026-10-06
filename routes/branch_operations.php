<?php
use App\Http\Controllers\Dashboard\BranchOperationsController as Operations;
use Illuminate\Support\Facades\Route;
Route::middleware(['lang','IsAdmin'])->group(function(){
    Route::get('admin/branch-operations/recover',[Operations::class,'recover'])->name('branch-operations.recover');
    foreach(['customers','delivery-companies','employees'] as $module)Route::prefix('admin/'.$module)->name($module.'.')->group(function()use($module){
        Route::get('/',[Operations::class,'index'])->name('index');Route::get('data',[Operations::class,'data'])->name('data');Route::post('save',[Operations::class,'save'])->name('save');
        if($module==='employees'){
            foreach(['statement','entries','export'] as $method)Route::get($method,[Operations::class,$method])->name($method);
            foreach(['attendance','entry','wallet','daily-notes'=>'dailyNotes','void-entry'=>'voidEntry','close','pay'] as $key=>$method){$path=is_int($key)?$method:$key;Route::post($path,[Operations::class,$method])->name($path);}
        }
    });
});
