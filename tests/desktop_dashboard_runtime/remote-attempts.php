<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;

// Actual server middleware/API and original catalog controllers on a disposable database.
require $application.'/database/migrations/2026_10_08_210000_create_desktop_dashboard_remote_attempts.php';
(new CreateDesktopDashboardRemoteAttempts)->up();
$remoteEnv=$env;$remoteEnv['DB_DATABASE']=$database;$remoteEnv['DESKTOP_TEST_APPLICATION']=$application;$remoteEnv['DESKTOP_DASHBOARD_ENABLED']='true';
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,__DIR__.'/server-router.php'],[['pipe','r'],['file',$profile.'/remote-attempts.log','a'],['file',$profile.'/remote-attempts.log','a']],$pipes,$application,$remoteEnv);
$cookies=[];
$decide=function(array $attempt,string $action='reserve')use($http,$ownerLink){
    [$status,$body]=$http('/api/desktop-dashboard/remote-attempts',['action'=>$action]+$attempt,['Authorization: Bearer '.$ownerLink['token'],'Accept: application/json']);
    return [$status,json_decode($body,true)];
};
$proof=fn($reply)=>['X-Fasakhansta-Remote-Attempt: '.$reply['id'],'X-Fasakhansta-Remote-Capability: '.$reply['capability']];
$attempt=fn($path='/admin/products')=>['id'=>(string)Str::uuid(),'method'=>'POST','path'=>$path];
try{
    for($n=0;$n<100;$n++){[$status,$page]=$http('/admin/login');if($status)break;usleep(50000);}
    preg_match('/name="_token" value="([^"]+)"/',$page,$serverCsrf);
    verify($status===200&&isset($serverCsrf[1]),'the real original server session prepares catalog outcome tests');
    verify($http('/admin/signin',['_token'=>$serverCsrf[1],'email'=>'owner@test.invalid','password'=>'Fixture123'])[0]===302,'the outcome fixture signs into the original owner account');
    require __DIR__.'/category-order-remote.php';
    $ownRemoteNote=(string)Str::uuid();$foreignRemoteNote=(string)Str::uuid();
    foreach([$ownRemoteNote=>1,$foreignRemoteNote=>20] as $note=>$actor)DB::table('notifications')->insert(['id'=>$note,'type'=>'FixtureNotification','notifiable_type'=>\App\Models\User::class,
        'notifiable_id'=>$actor,'data'=>json_encode(['title'=>'إشعار نتيجة السيرفر','text'=>'نص الإشعار الأصلي']),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    $noteForm=['_token'=>$serverCsrf[1],'idempotency_key'=>(string)Str::uuid(),'ids'=>[$ownRemoteNote,$foreignRemoteNote]];
    $noteAttempt=$attempt('/admin/dashboard-inbox/notifications/read');[, $noteProof]=$decide($noteAttempt);
    [$noteStatus,$noteBody]=$http($noteAttempt['path'],$noteForm,[...$proof($noteProof),'Accept: application/json']);
    verify($noteStatus===200&&json_decode($noteBody,true)['marked']===1&&DB::table('notifications')->where('id',$foreignRemoteNote)->value('read_at')===null
        &&$decide($noteAttempt,'settle')[1]['status']==='committed','the original own-notification read commits its scoped reserved outcome without a branch');
    verify($http($noteAttempt['path'],$noteForm,[...$proof($noteProof),'Accept: application/json'])[1]===$noteBody,'a lost notification reply returns its exact original count and marked total');
    $noteRetry=$attempt($noteAttempt['path']);[, $noteRetryProof]=$decide($noteRetry);
    verify($http($noteRetry['path'],$noteForm,[...$proof($noteRetryProof),'Accept: application/json'])[1]===$noteBody,'another notification transmission reuses the same original operation result');
    DB::table('users')->where('id',1)->update(['status'=>'disabled']);
    verify($http($noteAttempt['path'],$noteForm,[...$proof($noteProof),'Accept: application/json'])[0]===403,'disabling the enrolled account also rejects its stored notification reply');
    DB::table('users')->where('id',1)->update(['status'=>'accepted']);
    require __DIR__.'/notification-history-remote.php';
    [$status,$page]=$http('/admin/products/create');preg_match('/name="_token" value="([^"]+)"/',$page,$serverCsrf);
    $form=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'added_by'=>1,'category_id'=>1,'name_ar'=>'صنف نتيجة السيرفر','status'=>'show'];
    $first=$attempt();[$status,$reserved]=$decide($first);
    verify($status===200&&$reserved['status']==='ready'&&strlen($reserved['capability'])===64,'the native API reserves an account-bound original server request');
    verify($http('/admin/products',$form,$proof($reserved))[0]===302&&DB::table('products')->where('name_ar',$form['name_ar'])->count()===1,
        'the original server catalog write commits with its encrypted outcome in one transaction');
    [$status,$settled]=$decide($first,'settle');
    verify($status===200&&$settled['status']==='committed','a lost original HTTP reply resolves to the actual committed server outcome');
    verify($http('/admin/products',$form,$proof($reserved))[0]===302&&DB::table('products')->where('name_ar',$form['name_ar'])->count()===1,
        'the same server transmission replays its receipt before original unique-name validation');
    $differentOperation=$form;$differentOperation['_desktop_command']=(string)Str::uuid();
    verify($http('/admin/products',$differentOperation,$proof($reserved))[0]===409,'an old transmission capability cannot acknowledge a different logical operation');
    $second=$attempt();[$status,$secondProof]=$decide($second);
    verify($http('/admin/products',$form,$proof($secondProof))[0]===302&&DB::table('products')->where('name_ar',$form['name_ar'])->count()===1,
        'a retried form with a new transmission UUID retains its logical operation and creates no duplicate');
    $changed=$form;$changed['name_ar'].=' محتوى مختلف';$changedAttempt=$attempt();[, $changedProof]=$decide($changedAttempt);
    verify($http('/admin/products',$changed,$proof($changedProof))[0]===409&&!DB::table('products')->where('name_ar',$changed['name_ar'])->exists(),
        'changed content cannot reuse an already committed server operation UUID');
    verify($decide($changedAttempt,'settle')[1]['status']==='cancelled','a rejected new transmission can be terminally cancelled without changing the committed operation');
    $currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('product-create');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http('/admin/products',$form,$proof($reserved))[0]===403,'a stored original server reply still requires the current original product permission');
    $currentRole->givePermissionTo('product-create');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $cancel=$attempt();[, $cancelProof]=$decide($cancel);$cancelForm=$form;$cancelForm['_desktop_command']=(string)Str::uuid();$cancelForm['name_ar']='طلب متأخر تم إلغاؤه';
    verify($decide($cancel,'settle')[1]['status']==='cancelled'&&$http('/admin/products',$cancelForm,$proof($cancelProof))[0]===409
        &&!DB::table('products')->where('name_ar',$cancelForm['name_ar'])->exists(),'a terminal cancellation rejects an original HTTP write that arrives late');
    verify($decide($cancel)[1]['status']==='cancelled','a delayed reservation retry cannot reopen a cancelled server attempt');
    $neverArrived=$attempt();verify($decide($neverArrived,'settle')[1]['status']==='cancelled'&&$decide($neverArrived)[1]['status']==='cancelled',
        'recovery before a lost reservation arrives creates a terminal tombstone');
    $forged=$attempt();[, $forgedProof]=$decide($forged);$forgedProof['capability']=str_repeat('0',64);
    verify($http('/admin/products',$cancelForm,$proof($forgedProof))[0]===403,'a forged per-request capability cannot execute an original catalog write');
    $productId=(int)DB::table('products')->where('name_ar',$form['name_ar'])->value('id');$delete=$attempt('/admin/products/'.$productId);[, $deleteProof]=$decide($delete);
    $deleteForm=['_token'=>$serverCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid()];
    verify($http($delete['path'],$deleteForm,$proof($deleteProof))[0]===302&&!DB::table('products')->where('id',$productId)->exists()
        &&$http($delete['path'],$deleteForm,$proof($deleteProof))[0]===302,'a lost original server delete reply replays before binding the removed product');
    $rollback=$attempt();[, $rollbackProof]=$decide($rollback);$rollbackForm=$form;$rollbackForm['_desktop_command']=(string)Str::uuid();$rollbackForm['name_ar']='تراجع نتيجة السيرفر';
    DB::statement("CREATE TRIGGER reject_remote_outcome BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.status='committed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='simulated remote outcome persistence failure'; END IF; END");
    try{verify($http('/admin/products',$rollbackForm,$proof($rollbackProof))[0]===500&&!DB::table('products')->where('name_ar',$rollbackForm['name_ar'])->exists()
        &&DB::table('desktop_dashboard_remote_attempts')->where('id',$rollback['id'])->value('status')==='ready',
        'a failed outcome save rolls back the original server product rather than leaving an unrecorded commit');}
    finally{DB::statement('DROP TRIGGER reject_remote_outcome');}
    verify($decide($rollback,'settle')[1]['status']==='cancelled','recovery safely cancels a server write rolled back by outcome persistence failure');
    $ownerCookies=$cookies;$cookies=[];[$status,$page]=$http('/admin/login');preg_match('/name="_token" value="([^"]+)"/',$page,$foreignCsrf);
    $http('/admin/signin',['_token'=>$foreignCsrf[1],'email'=>'branch@test.invalid','password'=>'Fixture123']);
    $foreignForm=$form;$foreignForm['_token']=$foreignCsrf[1];
    verify($http('/admin/products',$foreignForm,$proof($reserved))[0]===403,'another original browser account cannot replay an enrolled owner outcome');$cookies=$ownerCookies;
    DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['enabled'=>false]);
    try{verify($decide($first,'settle')[0]===401&&$http('/admin/products',$form,$proof($reserved))[0]===401,'revoking a device blocks both outcome recovery and stored original responses');}
    finally{DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['enabled'=>true]);}
    $normal=$form;unset($normal['_desktop_command']);$normal['name_ar']='طلب السيرفر العادي';
    verify($http('/admin/products',$normal)[0]===302&&DB::table('products')->where('name_ar',$normal['name_ar'])->count()===1,
        'normal browser catalog requests retain the original server behavior without a reservation');
    $bulkIds=[];foreach([1,2] as $index){
        $normal['name_ar']='نتيجة حذف جماعي السيرفر '.$index;verify($http('/admin/products',$normal)[0]===302,'the original server form creates a bulk outcome fixture');
        $bulkIds[]=(int)DB::table('products')->where('name_ar',$normal['name_ar'])->value('id');
    }
    $bulk=['id'=>(string)Str::uuid(),'method'=>'DELETE','path'=>'/admin/productsDeleteAll'];[, $bulkProof]=$decide($bulk);
    $bulkForm=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'ids'=>implode(',',array_reverse($bulkIds))];
    verify($http($bulk['path'],$bulkForm,$proof($bulkProof),'DELETE')[0]===200&&DB::table('products')->whereIn('id',$bulkIds)->count()===0,
        'the actual original bulk server DELETE saves its transactional result');
    $bulkForm['ids']=implode(',',$bulkIds);
    verify($http($bulk['path'],$bulkForm,$proof($bulkProof),'DELETE')[0]===200,'a reordered bulk server retry acknowledges the same operation after its rows are gone');
    $areaForm=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'added_by'=>1,'parent_id'=>null,'title_ar'=>'منطقة نتيجة السيرفر','title_en'=>'Remote area'];
    $areaAttempt=$attempt('/admin/areas');[, $areaProof]=$decide($areaAttempt);
    verify($http('/admin/areas',$areaForm,$proof($areaProof))[0]===302&&DB::table('areas')->where('title_ar',$areaForm['title_ar'])->count()===1
        &&$decide($areaAttempt,'settle')[1]['status']==='committed','the original area creation commits its reserved server outcome without transport columns');
    $areaRetry=$attempt('/admin/areas');[, $areaRetryProof]=$decide($areaRetry);
    verify($http('/admin/areas',$areaForm,$proof($areaRetryProof))[0]===302&&DB::table('areas')->where('title_ar',$areaForm['title_ar'])->count()===1,'a later original area transmission returns its stored creation without duplication');
    $currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('areas-create');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http('/admin/areas',$areaForm,$proof($areaProof))[0]===403,'a committed original area response still requires its current creation permission');
    $currentRole->givePermissionTo('areas-create');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $remoteAreaId=(int)DB::table('areas')->where('title_ar',$areaForm['title_ar'])->value('id');
    $areaForm['_desktop_command']=(string)Str::uuid();$areaForm['_method']='PUT';$areaForm['title_ar']='منطقة نتيجة السيرفر معدلة';
    $areaUpdate=$attempt('/admin/areas/'.$remoteAreaId);[, $areaUpdateProof]=$decide($areaUpdate);
    verify($http($areaUpdate['path'],$areaForm,$proof($areaUpdateProof))[0]===302&&DB::table('areas')->where('id',$remoteAreaId)->value('title_ar')===$areaForm['title_ar']
        &&$http($areaUpdate['path'],$areaForm,$proof($areaUpdateProof))[0]===302,'the original area update preserves its reserved response and scalar route parameter');
    $areaRemove=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'_method'=>'DELETE'];$areaDelete=$attempt('/admin/areas/'.$remoteAreaId);[, $areaDeleteProof]=$decide($areaDelete);
    verify($http($areaDelete['path'],$areaRemove,$proof($areaDeleteProof))[0]===302&&!DB::table('areas')->where('id',$remoteAreaId)->exists()
        &&$http($areaDelete['path'],$areaRemove,$proof($areaDeleteProof))[0]===302,'a lost original server area deletion replays before its removed model is bound');
    $areaBulkIds=[];unset($areaForm['_desktop_command'],$areaForm['_method']);
    foreach([1,2] as $index){$areaForm['title_ar']='منطقة حذف نتيجة السيرفر '.$index;
        verify($http('/admin/areas',$areaForm)[0]===302,'the normal original area controller accepts its CSRF-protected form');
        $areaBulkIds[]=(int)DB::table('areas')->where('title_ar',$areaForm['title_ar'])->value('id');}
    $areaBulk=['id'=>(string)Str::uuid(),'method'=>'DELETE','path'=>'/admin/areasDeleteAll'];[, $areaBulkProof]=$decide($areaBulk);
    $areaBulkForm=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'ids'=>implode(',',array_reverse($areaBulkIds))];
    verify($http($areaBulk['path'],$areaBulkForm,$proof($areaBulkProof),'DELETE')[0]===200&&DB::table('areas')->whereIn('id',$areaBulkIds)->count()===0,'the original area bulk DELETE commits its reserved result atomically');
    $areaBulkForm['ids']=implode(',',$areaBulkIds);
    verify($http($areaBulk['path'],$areaBulkForm,$proof($areaBulkProof),'DELETE')[0]===200,'a reordered original area bulk retry returns its receipt after deletion');
    $faqForm=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'added_by'=>1,'question_ar'=>'سؤال نتيجة السيرفر','question_en'=>'Remote outcome question','answer_ar'=>'<p>إجابة نتيجة السيرفر</p>','answer_en'=>'<p>Remote outcome answer</p>'];
    $faqAttempt=$attempt('/admin/question_answers');[, $faqProof]=$decide($faqAttempt);
    verify($http('/admin/question_answers',$faqForm,$proof($faqProof))[0]===302&&DB::table('question_answers')->where('question_ar',$faqForm['question_ar'])->count()===1
        &&$decide($faqAttempt,'settle')[1]['status']==='committed','the actual FAQ repository commits its validated HTML fields with a reserved server outcome');
    $faqRetry=$attempt('/admin/question_answers');[, $faqRetryProof]=$decide($faqRetry);
    verify($http('/admin/question_answers',$faqForm,$proof($faqRetryProof))[0]===302&&DB::table('question_answers')->where('question_ar',$faqForm['question_ar'])->count()===1,'another native FAQ transmission replays one original creation');
    $currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('question_answer-create');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http('/admin/question_answers',$faqForm,$proof($faqProof))[0]===403,'a stored FAQ creation response still requires its current original permission');
    $currentRole->givePermissionTo('question_answer-create');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $remoteFaqId=(int)DB::table('question_answers')->where('question_ar',$faqForm['question_ar'])->value('id');
    $faqForm['_desktop_command']=(string)Str::uuid();$faqForm['_method']='PUT';$faqForm['answer_ar']='<p>إجابة نتيجة السيرفر معدلة</p>';
    $faqUpdate=$attempt('/admin/question_answers/'.$remoteFaqId);[, $faqUpdateProof]=$decide($faqUpdate);
    verify($http($faqUpdate['path'],$faqForm,$proof($faqUpdateProof))[0]===302&&DB::table('question_answers')->where('id',$remoteFaqId)->value('answer_ar')===$faqForm['answer_ar']
        &&$http($faqUpdate['path'],$faqForm,$proof($faqUpdateProof))[0]===302,'the actual FAQ update returns its saved response while preserving the exact HTML answer');
    $faqRemove=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'_method'=>'DELETE'];$faqDelete=$attempt('/admin/question_answers/'.$remoteFaqId);[, $faqDeleteProof]=$decide($faqDelete);
    verify($http($faqDelete['path'],$faqRemove,$proof($faqDeleteProof))[0]===302&&!DB::table('question_answers')->where('id',$remoteFaqId)->exists()
        &&$http($faqDelete['path'],$faqRemove,$proof($faqDeleteProof))[0]===302,'a lost original FAQ server deletion replays before binding its removed model');
    $faqBulkIds=[];unset($faqForm['_desktop_command'],$faqForm['_method']);
    foreach([1,2] as $index){$faqForm['question_ar']='سؤال حذف نتيجة السيرفر '.$index;
        verify($http('/admin/question_answers',$faqForm)[0]===302,'the normal original FAQ request validates and persists without transport metadata');
        $faqBulkIds[]=(int)DB::table('question_answers')->where('question_ar',$faqForm['question_ar'])->value('id');}
    $faqBulk=['id'=>(string)Str::uuid(),'method'=>'DELETE','path'=>'/admin/question_answersDeleteAll'];[, $faqBulkProof]=$decide($faqBulk);
    $faqBulkForm=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'ids'=>implode(',',array_reverse($faqBulkIds))];
    $currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('question_answer-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http($faqBulk['path'],$faqBulkForm,$proof($faqBulkProof),'DELETE')[0]===403&&DB::table('question_answers')->whereIn('id',$faqBulkIds)->count()===count($faqBulkIds),'the corrected original FAQ bulk route checks its actual deletion permission before changing rows');
    $currentRole->givePermissionTo('question_answer-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http($faqBulk['path'],$faqBulkForm,$proof($faqBulkProof),'DELETE')[0]===200&&DB::table('question_answers')->whereIn('id',$faqBulkIds)->count()===0,'the original FAQ bulk DELETE commits its protected reserved outcome');
    $faqBulkForm['ids']=implode(',',$faqBulkIds);
    verify($http($faqBulk['path'],$faqBulkForm,$proof($faqBulkProof),'DELETE')[0]===200,'a reordered original FAQ bulk retry returns the saved result after deletion');
    $siteFeatureForm=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'added_by'=>1,'status'=>'show','title_ar'=>'ميزة نتيجة السيرفر','title_en'=>'Remote feature title','text_ar'=>'<p>وصف نتيجة السيرفر</p>','text_en'=>'<p>Remote feature description</p>'];
    $siteFeatureImage=$attempt('/admin/features');[, $siteFeatureImageProof]=$decide($siteFeatureImage);
    verify($featureUpload('/admin/features',$siteFeatureForm,$proof($siteFeatureImageProof))===501
        &&DB::table('features')->where('title_ar',$siteFeatureForm['title_ar'])->count()===0
        &&$decide($siteFeatureImage,'settle')[1]['status']==='cancelled','a real original server feature upload is excluded before either its text row or a committed outcome exists');
    $siteFeatureAttempt=$attempt('/admin/features');[, $siteFeatureProof]=$decide($siteFeatureAttempt);
    verify($http('/admin/features',$siteFeatureForm,$proof($siteFeatureProof))[0]===302&&DB::table('features')->where('title_ar',$siteFeatureForm['title_ar'])->count()===1
        &&$decide($siteFeatureAttempt,'settle')[1]['status']==='committed','the actual site feature repository commits its validated HTML fields with a reserved server outcome');
    $siteFeatureRetry=$attempt('/admin/features');[, $siteFeatureRetryProof]=$decide($siteFeatureRetry);
    verify($http('/admin/features',$siteFeatureForm,$proof($siteFeatureRetryProof))[0]===302&&DB::table('features')->where('title_ar',$siteFeatureForm['title_ar'])->count()===1,'another native site feature transmission replays one original creation');
    $currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('feature-create');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http('/admin/features',$siteFeatureForm,$proof($siteFeatureProof))[0]===403,'a stored site feature creation response still requires its current original permission');
    $currentRole->givePermissionTo('feature-create');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $remoteSiteFeatureId=(int)DB::table('features')->where('title_ar',$siteFeatureForm['title_ar'])->value('id');
    $siteFeatureForm['_desktop_command']=(string)Str::uuid();$siteFeatureForm['_method']='PUT';$siteFeatureForm['text_ar']='<p>وصف نتيجة السيرفر معدلة</p>';$siteFeatureForm['status']='hide';
    $siteFeatureUpdate=$attempt('/admin/features/'.$remoteSiteFeatureId);[, $siteFeatureUpdateProof]=$decide($siteFeatureUpdate);
    verify($http($siteFeatureUpdate['path'],$siteFeatureForm,$proof($siteFeatureUpdateProof))[0]===302&&DB::table('features')->where('id',$remoteSiteFeatureId)->value('text_ar')===$siteFeatureForm['text_ar']
        &&$http($siteFeatureUpdate['path'],$siteFeatureForm,$proof($siteFeatureUpdateProof))[0]===302,'the actual site feature update returns its saved response while preserving the exact HTML answer');
    $siteFeatureRemove=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'_method'=>'DELETE'];$siteFeatureDelete=$attempt('/admin/features/'.$remoteSiteFeatureId);[, $siteFeatureDeleteProof]=$decide($siteFeatureDelete);
    verify($http($siteFeatureDelete['path'],$siteFeatureRemove,$proof($siteFeatureDeleteProof))[0]===302&&!DB::table('features')->where('id',$remoteSiteFeatureId)->exists()
        &&$http($siteFeatureDelete['path'],$siteFeatureRemove,$proof($siteFeatureDeleteProof))[0]===302,'a lost original site feature server deletion replays before binding its removed model');
    $siteFeatureBulkIds=[];unset($siteFeatureForm['_desktop_command'],$siteFeatureForm['_method']);
    foreach([1,2] as $index){$siteFeatureForm['title_ar']='ميزة حذف نتيجة السيرفر '.$index;
        verify($http('/admin/features',$siteFeatureForm)[0]===302,'the normal original site feature request validates and persists without transport metadata');
        $siteFeatureBulkIds[]=(int)DB::table('features')->where('title_ar',$siteFeatureForm['title_ar'])->value('id');}
    $siteFeatureBulk=['id'=>(string)Str::uuid(),'method'=>'DELETE','path'=>'/admin/featuresDeleteAll'];[, $siteFeatureBulkProof]=$decide($siteFeatureBulk);
    $siteFeatureBulkForm=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'ids'=>implode(',',array_reverse($siteFeatureBulkIds))];
    $currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('feature-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http($siteFeatureBulk['path'],$siteFeatureBulkForm,$proof($siteFeatureBulkProof),'DELETE')[0]===403&&DB::table('features')->whereIn('id',$siteFeatureBulkIds)->count()===count($siteFeatureBulkIds),'the corrected original site feature bulk route checks its actual deletion permission before changing rows');
    $currentRole->givePermissionTo('feature-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http($siteFeatureBulk['path'],$siteFeatureBulkForm,$proof($siteFeatureBulkProof),'DELETE')[0]===200&&DB::table('features')->whereIn('id',$siteFeatureBulkIds)->count()===0,'the original site feature bulk DELETE commits its protected reserved outcome');
    $siteFeatureBulkForm['ids']=implode(',',$siteFeatureBulkIds);
    verify($http($siteFeatureBulk['path'],$siteFeatureBulkForm,$proof($siteFeatureBulkProof),'DELETE')[0]===200,'a reordered original site feature bulk retry returns the saved result after deletion');
    $contractForm=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'added_by'=>1,'type'=>'vendor','template'=>'<p>قالب نتيجة السيرفر · [vendorName]</p>'];
    $contractAttempt=$attempt('/admin/contracts');[, $contractProof]=$decide($contractAttempt);
    verify($http('/admin/contracts',$contractForm,$proof($contractProof))[0]===302&&DB::table('contracts')->where('template',$contractForm['template'])->count()===1
        &&$decide($contractAttempt,'settle')[1]['status']==='committed','the original server contract creation stores its exact terminal template outcome');
    $currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('contract-edit');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http('/admin/contracts',$contractForm,$proof($contractProof))[0]===403,'a stored contract creation reply requires its current original editing permission');
    $currentRole->givePermissionTo('contract-edit');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $retryContract=$attempt('/admin/contracts');[, $retryContractProof]=$decide($retryContract);
    verify($http('/admin/contracts',$contractForm,$proof($retryContractProof))[0]===302&&DB::table('contracts')->where('template',$contractForm['template'])->count()===1,'a later contract transmission returns its stored original creation without another template');
    $remoteContract=(int)DB::table('contracts')->where('template',$contractForm['template'])->value('id');
    $contractForm['_method']='PUT';$contractForm['_desktop_command']=(string)Str::uuid();$contractForm['template']='<p>قالب نتيجة السيرفر معدل · [vendorMobile]</p>';
    $contractUpdate=$attempt('/admin/contracts/'.$remoteContract);[, $contractUpdateProof]=$decide($contractUpdate);
    verify($http($contractUpdate['path'],$contractForm,$proof($contractUpdateProof))[0]===302&&DB::table('contracts')->where('id',$remoteContract)->value('template')===$contractForm['template']
        &&$http($contractUpdate['path'],$contractForm,$proof($contractUpdateProof))[0]===302,'the original contract update saves its placeholder HTML and replays its reserved response');
    $contractRemove=['_token'=>$serverCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid()];$contractDelete=$attempt('/admin/contracts/'.$remoteContract);[, $contractDeleteProof]=$decide($contractDelete);
    $currentRole->revokePermissionTo('contract-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http($contractDelete['path'],$contractRemove,$proof($contractDeleteProof))[0]===403&&DB::table('contracts')->where('id',$remoteContract)->exists(),'the original contract deletion enforces its UI permission before removing a template');
    $currentRole->givePermissionTo('contract-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http($contractDelete['path'],$contractRemove,$proof($contractDeleteProof))[0]===302&&!DB::table('contracts')->where('id',$remoteContract)->exists()
        &&$http($contractDelete['path'],$contractRemove,$proof($contractDeleteProof))[0]===302,'a lost original contract deletion returns its committed outcome before removed-model binding');
    $currentRole->revokePermissionTo('contract-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http($contractDelete['path'],$contractRemove,$proof($contractDeleteProof))[0]===403,'a stored contract deletion reply requires its current original permission');
    $currentRole->givePermissionTo('contract-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($decide(['id'=>(string)Str::uuid(),'method'=>'DELETE','path'=>'/admin/contractsDeleteAll'])[0]===422,'the nonexistent contract bulk endpoint cannot reserve an original operation');
    foreach([86001,86002,86003] as $id)DB::table('contacts')->insert(['id'=>$id,'user_id'=>20,'name'=>'رسالة نتيجة السيرفر '.$id,'email'=>'outcome@test.invalid','message'=>'محتوى رسالة نتيجة السيرفر '.$id]);
    $contactForm=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'_method'=>'DELETE'];
    $contactAttempt=$attempt('/admin/contacts/86001');[, $contactProof]=$decide($contactAttempt);
    verify($http($contactAttempt['path'],$contactForm,$proof($contactProof))[0]===302&&!DB::table('contacts')->where('id',86001)->exists()
        &&$decide($contactAttempt,'settle')[1]['status']==='committed'&&$http($contactAttempt['path'],$contactForm,$proof($contactProof))[0]===302,'the original server contact deletion and lost reply retain one terminal outcome before removed-model binding');
    $currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('contact-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http($contactAttempt['path'],$contactForm,$proof($contactProof))[0]===403,'a stored original contact deletion response still requires its current permission');
    $currentRole->givePermissionTo('contact-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $contactBulk=['id'=>(string)Str::uuid(),'method'=>'DELETE','path'=>'/admin/contactsDeleteAll'];[, $contactBulkProof]=$decide($contactBulk);
    $contactBulkForm=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'ids'=>'86003,86002'];
    $currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('contact-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http($contactBulk['path'],$contactBulkForm,$proof($contactBulkProof),'DELETE')[0]===403&&DB::table('contacts')->whereIn('id',[86002,86003])->count()===2,'the actual original contact bulk controller checks the deletion permission before changing either message');
    $currentRole->givePermissionTo('contact-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http($contactBulk['path'],$contactBulkForm,$proof($contactBulkProof),'DELETE')[0]===200&&DB::table('contacts')->whereIn('id',[86002,86003])->count()===0&&$decide($contactBulk,'settle')[1]['status']==='committed','the protected original contact bulk deletion stores its complete terminal result');
    $contactBulkForm['ids']='86002,86003';verify($http($contactBulk['path'],$contactBulkForm,$proof($contactBulkProof),'DELETE')[0]===200,'a reordered original contact bulk retry returns its stored result after all messages disappear');
    verify($decide($attempt('/admin/contacts'))[0]===422,'a contact listing path cannot reserve an unimplemented contact creation');
    $cart=['_token'=>$serverCsrf[1],'branch'=>'f:100','items'=>[['product_id'=>1,'quantity_mode'=>'weight','quantity'=>'0.250']],'discount'=>'0.00','payment_method'=>'cash'];
    [$quoteStatus,$quoteBody]=$http('/admin/takeaway/quote',$cart,['Accept: application/json']);$quote=json_decode($quoteBody,true);
    verify($quoteStatus===200&&isset($quote['quote_hash'],$quote['total']),'the original server quote prepares a real cash checkout outcome test');
    $checkout=$cart+['idempotency_key'=>(string)Str::uuid(),'quote_hash'=>$quote['quote_hash'],'cash_received'=>$quote['total'],'payment_confirmed'=>true];
    $checkoutAttempt=$attempt('/admin/takeaway/checkout');[$status,$checkoutProof]=$decide($checkoutAttempt);
    verify($status===200&&$checkoutProof['status']==='ready','a reviewed original cash checkout receives a native server reservation');
    $balance=(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents');
    [$saleStatus,$saleBody]=$http($checkoutAttempt['path'],$checkout,array_merge($proof($checkoutProof),['Accept: application/json']));$sale=json_decode($saleBody,true);
    verify($saleStatus===200&&isset($sale['receipt'])&&DB::table('takeaway_orders')->where('request_key',$checkout['idempotency_key'])->count()===1,
        'the original server cash checkout commits its actual financial rows and reserved outcome');
    $paidBalance=(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents');
    verify($paidBalance===$balance+\App\Services\GoServices\Money::minor($quote['total'])&&$decide($checkoutAttempt,'settle')[1]['status']==='committed',
        'a lost cash checkout response resolves the actual single drawer movement');
    $checkoutRetry=$attempt('/admin/takeaway/checkout');[, $checkoutRetryProof]=$decide($checkoutRetry);
    verify($http($checkoutRetry['path'],$checkout,array_merge($proof($checkoutRetryProof),['Accept: application/json']))[1]===$saleBody
        &&(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===$paidBalance,
        'a later transmission of the same checkout returns the exact saved receipt without moving cash again');
    $crossBranch=$attempt('/admin/customers/save');[, $crossBranchProof]=$decide($crossBranch);
    $customer=['_token'=>$serverCsrf[1],'branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'name'=>'عميل نتيجة السيرفر','phone'=>'01012345678','address'=>'المنصورة'];
    verify($http($crossBranch['path'],$customer,array_merge($proof($crossBranchProof),['Accept: application/json']))[0]===200
        &&DB::table('branch_customers')->where('name',$customer['name'])->count()===1,'the original branch customer controller uses the same remote outcome protection');
    $neverCheckout=$attempt('/admin/takeaway/checkout');[, $neverCheckoutProof]=$decide($neverCheckout);$neverSale=$checkout;$neverSale['idempotency_key']=(string)Str::uuid();
    $decide($neverCheckout,'settle');
    verify($http($neverCheckout['path'],$neverSale,array_merge($proof($neverCheckoutProof),['Accept: application/json']))[0]===409
        &&(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===$paidBalance,
        'a cancelled delayed cash checkout cannot change the drawer after recovery');
    $unreviewed=$attempt('/admin/go-stores');
    verify($decide($unreviewed)[0]===422&&!DB::table('desktop_dashboard_remote_attempts')->where('id',$unreviewed['id'])->exists(),
        'unreviewed server actions cannot acquire a reservation by resembling a reviewed POST');
    $apply=function(string $path,array $values)use($attempt,$decide,$http,$proof){
        $binding=$attempt($path);[$reservedStatus,$reserved]=$decide($binding);if($reservedStatus!==200)throw new RuntimeException('Original operation did not reserve: '.$path.' (HTTP '.$reservedStatus.')');
        [$status,$body]=$http($path,$values,array_merge($proof($reserved),['Accept: application/json']));
        return [$status,$body,$binding,$reserved];
    };
    $day=\App\Services\Dashboard\OperatingDay::date();$month=substr($day,0,7);
    $base=['_token'=>$serverCsrf[1],'branch'=>'f:100'];
    $employeeForm=$base+['idempotency_key'=>(string)Str::uuid(),'name'=>'موظف نتيجة السيرفر','job_title'=>'اختبار','hired_on'=>$month.'-01','active'=>true,'salary'=>'3000.00','effective_month'=>$month];
    [$employeeStatus,$employeeBody]=$apply('/admin/employees/save',$employeeForm);$employee=json_decode($employeeBody,true)['employee']??null;
    verify($employeeStatus===200&&$employee&&DB::table('branch_employees')->where('name',$employeeForm['name'])->count()===1,
        'the original employee controller commits through a durable server outcome');
    $advanceForm=$base+['idempotency_key'=>(string)Str::uuid(),'employee_id'=>$employee['id'],'day'=>$day,'kind'=>'advance','amount'=>'10.00','reason'=>'اختبار نتيجة السلفة'];
    [$advanceStatus,$advanceBody,$advanceAttempt]=$apply('/admin/employees/entry',$advanceForm);$advance=json_decode($advanceBody,true)['entry']??null;
    verify($advanceStatus===200&&$advance&&$decide($advanceAttempt,'settle')[1]['status']==='committed',
        'a lost original employee advance reply resolves to its committed entry');
    verify($apply('/admin/employees/entry',$advanceForm)[1]===$advanceBody&&DB::table('branch_employee_entries')->where('employee_id',$employee['id'])->where('kind','advance')->count()===1,
        'another transmission returns the exact employee advance receipt without duplicating the debt');
    // Install the actual later attendance migration, rather than pretending the inspected old report contains it.
    require $application.'/database/migrations/2026_10_08_190000_add_employee_attendance_rules.php';(new AddEmployeeAttendanceRules)->up();
    $rulesForm=$base+['idempotency_key'=>(string)Str::uuid(),'morning_start'=>'09:00','morning_end'=>'17:00','morning_late'=>'0.00','morning_early'=>'0.00',
        'evening_start'=>'17:00','evening_end'=>'01:00','evening_late'=>'0.00','evening_early'=>'0.00','absence'=>'0.00'];
    [$rulesStatus,$rulesBody]=$apply('/admin/employees/attendance-rules',$rulesForm);
    verify($rulesStatus===200&&$apply('/admin/employees/attendance-rules',$rulesForm)[1]===$rulesBody
        &&DB::table('branch_attendance_rules')->where('branch','f:100')->count()===2,
        'the original owner attendance settings return the exact saved revision on another transmission');
    $attendanceForm=$base+['idempotency_key'=>(string)Str::uuid(),'employee_id'=>$employee['id'],'day'=>$day,'status'=>'morning','action'=>'check_in'];
    [$attendanceStatus,$attendanceBody]=$apply('/admin/employees/attendance',$attendanceForm);
    verify($attendanceStatus===200&&$apply('/admin/employees/attendance',$attendanceForm)[1]===$attendanceBody
        &&DB::table('branch_employee_days')->where('employee_id',$employee['id'])->where('day',$day)->count()===1,
        'the original employee check-in retains its recorded clock rather than executing another check-in');
    $walletForm=$base+['idempotency_key'=>(string)Str::uuid(),'employee_id'=>$employee['id'],'expected_revision'=>$employee['revision'],'wallet_phone'=>'01012345678'];
    [$walletStatus,$walletBody]=$apply('/admin/employees/wallet',$walletForm);
    verify($walletStatus===200&&$apply('/admin/employees/wallet',$walletForm)[1]===$walletBody,
        'the original employee wallet update replays before its employee revision has advanced');
    $notesForm=$base+['idempotency_key'=>(string)Str::uuid(),'employee_id'=>$employee['id'],'day'=>$day,
        'expected_revision'=>DB::table('branch_employee_days')->where('employee_id',$employee['id'])->where('day',$day)->value('revision'),'notes'=>'اختبار الملاحظات'];
    [$notesStatus,$notesBody]=$apply('/admin/employees/daily-notes',$notesForm);
    verify($notesStatus===200&&$apply('/admin/employees/daily-notes',$notesForm)[1]===$notesBody,
        'the original daily employee notes return the saved response before the day revision changes');
    $voidForm=$base+['idempotency_key'=>(string)Str::uuid(),'entry_id'=>$advance['id'],'expected_revision'=>1,'reason'=>'إلغاء حركة الاختبار'];
    [$voidStatus,$voidBody]=$apply('/admin/employees/void-entry',$voidForm);
    verify($voidStatus===200&&DB::table('branch_employee_entries')->where('id',$advance['id'])->value('voided_at'),
        'the original owner-only entry cancellation commits its server outcome');
    $ownerScope=DB::table('users')->where('id',1)->value('owner_resturant_id');DB::table('users')->where('id',1)->update(['owner_resturant_id'=>100]);
    try{verify($apply('/admin/employees/void-entry',$voidForm)[0]===403,
        'a saved owner-only employee reply still rejects an account that loses primary-owner authority');}
    finally{DB::table('users')->where('id',1)->update(['owner_resturant_id'=>$ownerScope]);}
    [$statementStatus,$statementBody]=$http('/admin/employees/statement?'.http_build_query(['branch'=>'f:100','employee_id'=>$employee['id'],'month'=>$month]),null,['Accept: application/json']);
    $statement=json_decode($statementBody,true)['statement']??null;
    $closeForm=$base+['idempotency_key'=>(string)Str::uuid(),'employee_id'=>$employee['id'],'month'=>$month,'preview_hash'=>$statement['preview_hash']??''];
    [$closeStatus,$closeBody]=$apply('/admin/employees/close',$closeForm);$closed=json_decode($closeBody,true)['statement']??null;
    verify($statementStatus===200&&$closeStatus===200&&$closed&&DB::table('branch_payrolls')->where('employee_id',$employee['id'])->count()===1,
        'the original reviewed payroll closing shares the durable server outcome');
    $payForm=$base+['idempotency_key'=>(string)Str::uuid(),'payroll_id'=>$closed['payroll_id'],'expected_revision'=>$closed['revision'],'payment_method'=>'cash','payment_confirmed'=>true];
    [$payStatus,$payBody,$payAttempt]=$apply('/admin/employees/pay',$payForm);
    verify($payStatus===200&&$decide($payAttempt,'settle')[1]['status']==='committed'
        &&$apply('/admin/employees/pay',$payForm)[1]===$payBody&&DB::table('branch_payrolls')->where('id',$closed['payroll_id'])->value('status')==='paid'
        &&(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===$paidBalance,
        'a lost payroll payment reply and another transmission preserve the exact accounting confirmation');
    DB::table('stock_ingredients')->insert(['id'=>88001,'name'=>'بضاعة اختبار النتيجة','unit'=>'kg','position'=>1]);
    $stockForm=$base+['idempotency_key'=>(string)Str::uuid(),'ingredient_id'=>88001,'unit'=>'kg','quantity'=>'2.000'];
    [$stockStatus,$stockBody,$stockAttempt]=$apply('/admin/branch-stock/receive',$stockForm);
    verify($stockStatus===200&&$decide($stockAttempt,'settle')[1]['status']==='committed'&&$apply('/admin/branch-stock/receive',$stockForm)[1]===$stockBody
        &&(int)DB::table('branch_inventory')->where('branch','f:100')->where('ingredient_id',88001)->value('quantity_units')===2000000
        &&DB::table('branch_inventory_movements')->where('branch','f:100')->where('ingredient_id',88001)->count()===1,
        'a lost original goods receipt and another transmission add inventory once');
    $recipeForm=$base+['idempotency_key'=>(string)Str::uuid(),'product_id'=>1,'feature_id'=>0,'unit'=>'kg',
        'components'=>[['ingredient_id'=>88001,'measure'=>'kg','quantity'=>'1.000']]];
    [$recipeStatus,$recipeBody]=$apply('/admin/branch-stock/recipes',$recipeForm);
    verify($recipeStatus===200&&$apply('/admin/branch-stock/recipes',$recipeForm)[1]===$recipeBody
        &&DB::table('branch_stock_recipes')->where('branch','f:100')->where('product_id',1)->count()===1,
        'the original inventory recipe returns the saved receipt before its recipe revision changes');
    $reviewForm=$base+['idempotency_key'=>(string)Str::uuid(),'expected_revision'=>1,'action'=>'approve'];
    $beforeExpense=(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents');
    [$reviewStatus,$reviewBody,$reviewAttempt]=$apply('/admin/branch-expenses/'.$expenseAttachmentId.'/review',$reviewForm);
    verify($reviewStatus===200&&$decide($reviewAttempt,'settle')[1]['status']==='committed'
        &&$apply('/admin/branch-expenses/'.$expenseAttachmentId.'/review',$reviewForm)[1]===$reviewBody
        &&(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===$beforeExpense-1000,
        'a lost original expense approval reply deducts cash once and replays the exact signed-in result');
    $categoryForm=['_token'=>$serverCsrf[1],'idempotency_key'=>(string)Str::uuid(),'action'=>'create','name'=>'تصنيف اختبار نتيجة السيرفر'];
    [$categoryStatus,$categoryBody,$categoryAttempt]=$apply('/admin/branch-expenses/categories',$categoryForm);
    verify($categoryStatus===200&&$decide($categoryAttempt,'settle')[1]['status']==='committed'
        &&$apply('/admin/branch-expenses/categories',$categoryForm)[1]===$categoryBody
        &&DB::table('branch_expense_categories')->where('name',$categoryForm['name'])->count()===1,
        'the original shared expense category commits and retries without requiring a fictitious branch');
    $ownerScope=DB::table('users')->where('id',1)->value('owner_resturant_id');DB::table('users')->where('id',1)->update(['owner_resturant_id'=>100]);
    try{verify($apply('/admin/branch-expenses/categories',$categoryForm)[0]===403,
        'a stored shared expense category reply still requires the current original primary-owner authority');}
    finally{DB::table('users')->where('id',1)->update(['owner_resturant_id'=>$ownerScope]);}
    require __DIR__.'/expense-attachments-http.php';
    $bonusEmployee=app(\App\Services\Dashboard\BranchPayroll::class)->employeeSave($base+['idempotency_key'=>(string)Str::uuid(),
        'name'=>'موظف اختبار صلاحية المكافأة','job_title'=>'اختبار','hired_on'=>'2026-09-01','active'=>true,'salary'=>'1000.00','effective_month'=>'2026-09'],
        \App\Models\User::withoutGlobalScopes()->findOrFail(1))['employee'];
    $bonusForm=$base+['idempotency_key'=>(string)Str::uuid(),'employee_id'=>$bonusEmployee['id'],'day'=>'2026-09-12','kind'=>'bonus','amount'=>'100.00','reason'=>'مكافأة الأونر'];
    [$bonusStatus,$bonusBody,$bonusAttempt]=$apply('/admin/employees/entry',$bonusForm);
    verify($bonusStatus===200&&DB::table('branch_employee_entries')->where('employee_id',$bonusEmployee['id'])->where('kind','bonus')->count()===1,
        'the retained primary Owner rule permits one bonus through its reserved original HTTP action');
    $ownerScope=DB::table('users')->where('id',1)->value('owner_resturant_id');DB::table('users')->where('id',1)->update(['owner_resturant_id'=>100]);
    try{verify($apply('/admin/employees/entry',$bonusForm)[0]===403&&DB::table('branch_employee_entries')->where('employee_id',$bonusEmployee['id'])->count()===1,
        'an already stored bonus response still requires current primary-Owner authority');}
    finally{DB::table('users')->where('id',1)->update(['owner_resturant_id'=>$ownerScope]);}
    verify($apply('/admin/employees/entry',$bonusForm)[1]===$bonusBody&&DB::table('branch_employee_entries')->where('employee_id',$bonusEmployee['id'])->count()===1,
        'restoring the Owner retains the original bonus response without granting another bonus');
    try{app(\App\Services\Dashboard\BranchPayroll::class)->entry(array_replace($bonusForm,['idempotency_key'=>(string)Str::uuid()]),\App\Models\User::withoutGlobalScopes()->findOrFail(10));throw new RuntimeException('A branch awarded a bonus.');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403&&DB::table('branch_employee_entries')->where('employee_id',$bonusEmployee['id'])->count()===1,
        'an ordinary branch cannot award a bonus in the merged original payroll service');}
    $concurrent=$attempt();$decide($concurrent);$client=null;
    DB::beginTransaction();DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->lockForUpdate()->first();
    try{
        $body=http_build_query(['action'=>'settle']+$concurrent);
        $code='$context=stream_context_create(["http"=>["method"=>"POST","header"=>"Content-Type: application/x-www-form-urlencoded\\r\\nAccept: application/json\\r\\nAuthorization: Bearer ".$argv[2],"content"=>$argv[3],"ignore_errors"=>true,"timeout"=>15]]);$body=file_get_contents($argv[1],false,$context);echo $body;';
        $client=proc_open([PHP_BINARY,'-r',$code,$origin.'/api/desktop-dashboard/remote-attempts',$ownerLink['token'],$body],
            [['pipe','r'],['file',$profile.'/remote-concurrent.json','w'],['file',$profile.'/remote-concurrent-error.log','w']],$clientPipes,$application,$remoteEnv);
        $waiting=false;
        for($n=0;$n<100;$n++){
            foreach($pdo->query('SHOW PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $process)if(str_contains($process['Info']??'','desktop_dashboard_devices')&&stripos($process['Info']??'','for update')!==false&&$process['Command']!=='Sleep')$waiting=true;
            if($waiting)break;usleep(10000);
        }
        verify($waiting&&proc_get_status($client)['running'],'an actual concurrent API settlement waits for the accepted InnoDB write rather than cancelling ahead of it');
        DB::table('products')->insert(['added_by'=>1,'category_id'=>1,'name_ar'=>'نتيجة معاملة متزامنة','status'=>'show']);
        $operation=(string)Str::uuid();$saved=['operation'=>$operation,'status'=>302,'content'=>'','type'=>'text/html','location'=>'/admin/products'];
        DB::table('desktop_dashboard_remote_attempts')->where('id',$concurrent['id'])->update(['status'=>'committed','operation_id'=>$operation,
            'request_hash'=>str_repeat('a',64),'response_cipher'=>Crypt::encryptString(json_encode($saved))]);DB::commit();
        fclose($clientPipes[0]);$clientExit=proc_close($client);$client=null;
        $decision=json_decode(file_get_contents($profile.'/remote-concurrent.json'),true);
        verify($clientExit===0&&($decision['status']??null)==='committed'&&DB::table('products')->where('name_ar','نتيجة معاملة متزامنة')->exists(),
            'settlement observes the final server commit after the real write lock is released');
    }finally{
        if(DB::transactionLevel())DB::rollBack();
        if(is_resource($client)){fclose($clientPipes[0]);proc_terminate($client);proc_close($client);}
    }
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}
