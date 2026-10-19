<?php
use App\Http\Controllers\Dashboard\BranchOperationsController as Operations;
use Illuminate\Support\Facades\Route;
Route::middleware(['lang','IsAdmin'])->group(function(){
    Route::get('admin/branch-operations/recover',[Operations::class,'recover'])->name('branch-operations.recover');
    foreach(['customers','delivery-companies','employees'] as $module)Route::prefix('admin/'.$module)->name($module.'.')->group(function()use($module){
        Route::get('/',[Operations::class,'index'])->name('index');Route::get('data',[Operations::class,'data'])->name('data');Route::post('save',[Operations::class,'save'])->name('save');
        if($module==='customers'){
            Route::post('directory-save',[Operations::class,'directorySave'])->name('directory-save');
            Route::post('consent',[Operations::class,'customerConsent'])->name('consent');
            Route::get('messaging-status',[Operations::class,'messagingStatus'])->name('messaging-status');
            Route::post('messaging-configure',[Operations::class,'configureMessaging'])->name('messaging-configure');
            Route::post('feedback-template-prepare',[Operations::class,'prepareFeedbackTemplate'])->name('feedback-template-prepare');
            Route::post('marketing-template-prepare',[Operations::class,'prepareMarketingTemplate'])->name('marketing-template-prepare');
            Route::post('campaign-queue',[Operations::class,'queueCampaign'])->name('campaign-queue');
        }
        if($module==='employees'){
            foreach(['statement','entries','export'] as $method)Route::get($method,[Operations::class,$method])->name($method);
            foreach(['attendance','attendance-rules'=>'attendanceRules','entry','wallet','daily-notes'=>'dailyNotes','void-entry'=>'voidEntry','close','pay'] as $key=>$method){$path=is_int($key)?$method:$key;Route::post($path,[Operations::class,$method])->name($path);}
        }
    });
});
