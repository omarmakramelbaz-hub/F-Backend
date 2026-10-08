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
