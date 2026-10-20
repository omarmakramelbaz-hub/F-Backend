<?php
use Illuminate\Support\Facades\{DB,Crypt,Storage};
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation};

(function()use($database,$ownerStage,$ownerDevice,$ownerLink,$remoteDevice,$application,$httpPort,$origin,$browserToken,$profile,$env){
    $roots=[true=>$profile.'/go-products-local',false=>$profile.'/go-products-server'];
    foreach($roots as $root)foreach(['app/public/go-stores/60','framework/cache/data','framework/sessions','framework/views','logs','private','bootstrap/cache'] as $dir)if(!is_dir($root.'/'.$dir))mkdir($root.'/'.$dir,0700,true);
    $oldRoot=config('filesystems.disks.public.root');$switch=function(bool $local)use($database,$ownerStage,$roots){config(['database.connections.mysql.database'=>$local?$ownerStage:$database,'desktop_dashboard.local'=>$local,
        'filesystems.disks.public.root'=>$roots[$local].'/app/public']);DB::purge();app('filesystem')->forgetDisk('public');};
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aA+8AAAAASUVORK5CYII=');
    $seedKey=(string)Str::uuid();$option=(string)Str::uuid();$old=[];$profileBefore=[];$oldFee=[];$oldOwnerTime=[];$newIds=[];
    foreach([false,true] as $local){$switch($local);$old[$local]=(array)DB::table('go_store_products')->where('id',1)->first();$profileBefore[$local]=(array)DB::table('go_stores')->where('user_id',60)->first();$oldFee[$local]=DB::table('users')->where('id',60)->value('delegate_fees');$oldOwnerTime[$local]=DB::table('users')->where('id',60)->value('created_at');
        DB::table('go_stores')->where('user_id',60)->update(['name'=>'GO product fixture','kind'=>'supermarket','address'=>'عنوان تجريبي ثابت','revision'=>1]);DB::table('users')->where('id',60)->update(['delegate_fees'=>'10.00','created_at'=>'2020-02-01 00:00:00']);
        DB::table('go_store_products')->where('id',1)->update(['request_key'=>$seedKey,'name'=>'صنف قديم','unit'=>'عبوة','description'=>'محتوى محفوظ','image_path'=>'go-stores/60/existing.png','price_cents'=>10000,
            'available'=>true,'revision'=>1,'options'=>json_encode([['id'=>$option,'label'=>'نصف كيلو','price_cents'=>5000]],JSON_UNESCAPED_UNICODE),'created_at'=>'2020-01-01 00:00:00','updated_at'=>'2020-01-01 00:00:00']);
        file_put_contents($roots[$local].'/app/public/go-stores/60/existing.png',$png);
    }
    $web=null;$pipes=[];$cookies=[];$local=true;
    $http=function(string $path,?array $form=null,?string $image=null,array $extra=[])use($origin,$browserToken,&$cookies){
        $headers=['X-Fasakhansta-Desktop: '.$browserToken,'Accept: application/json',...$extra];$body='';
        if($cookies)$headers[]='Cookie: '.implode('; ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($cookies),$cookies));
        if($form!==null){if($image!==null){$boundary='go-product-'.bin2hex(random_bytes(12));foreach($form as $name=>$value)$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$name."\"\r\n\r\n".$value."\r\n";
            $body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"image\"; filename=\"product.png\"\r\nContent-Type: image/png\r\n\r\n".$image."\r\n--".$boundary."--\r\n";$headers[]='Content-Type: multipart/form-data; boundary='.$boundary;}
            else{$body=http_build_query($form);$headers[]='Content-Type: application/x-www-form-urlencoded';}}
        $context=stream_context_create(['http'=>['method'=>$form===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>20,'follow_location'=>0]]);
        $reply=@file_get_contents($origin.$path,false,$context);$rh=$http_response_header??[];preg_match('/^HTTP\/\S+ (\d+)/',$rh[0]??'',$status);foreach($rh as $h)if(preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$h,$m))$cookies[$m[1]]=$m[2];return [(int)($status[1]??0),$reply,$rh];
    };
    $start=function(bool $isLocal)use($env,$roots,$application,$database,$ownerStage,$httpPort,$profile,$http,&$cookies,&$web,&$pipes){
        if(is_resource($web)){fclose($pipes[0]);proc_terminate($web);proc_close($web);}$cookies=[];$webEnv=$env;$webEnv['DESKTOP_DASHBOARD_STORAGE']=$roots[$isLocal];$webEnv['DB_DATABASE']=$isLocal?$ownerStage:$database;$webEnv['DESKTOP_TEST_APPLICATION']=$application;$webEnv['DESKTOP_DASHBOARD_ENABLED']='true';
        foreach(['APP_CONFIG_CACHE'=>'config.php','APP_PACKAGES_CACHE'=>'packages.php','APP_SERVICES_CACHE'=>'services.php','APP_ROUTES_CACHE'=>'routes.php'] as $name=>$value)$webEnv[$name]=$roots[$isLocal].'/bootstrap/cache/'.$value;
        $cmd=$isLocal?[PHP_BINARY,'-d','upload_max_filesize=5M','-d','post_max_size=12M','-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php']:[PHP_BINARY,'-S','127.0.0.1:'.$httpPort,__DIR__.'/server-router.php'];
        $web=proc_open($cmd,[['pipe','r'],['file',$profile.'/go-products.log','a'],['file',$profile.'/go-products.log','a']],$pipes,$application,$webEnv);
        for($i=0;$i<100;$i++){[$status,$page]=$http('/admin/login');if($status===200)break;usleep(50000);}preg_match('/name="_token" value="([^"]+)"/',$page??'',$csrf);
        verify($status===200&&isset($csrf[1])&&$http('/admin/signin',['_token'=>$csrf[1],'email'=>'owner@test.invalid','password'=>'Fixture123'])[0]===302,'the original GO catalog fixture signs in through '.($isLocal?'local':'server').' Laravel HTTP');
        [$status,$page]=$http('/admin/go-stores/60/products/create');preg_match('/name="_token" value="([^"]+)"/',$page,$csrf);verify($status===200&&isset($csrf[1])&&str_contains($page,'name="image"'),'the original GO product form preserves its upload, options and CSRF');return $csrf[1];
    };
    $envelope=function(string $id){$r=DB::table('desktop_dashboard_commands')->where('command_id',$id)->first();$saved=json_decode(Crypt::decryptString($r->local_result_cipher),true,512,JSON_THROW_ON_ERROR);return ['command_id'=>$id,'actor_id'=>1,'route_name'=>$r->route_name,
        'payload'=>json_decode(Crypt::decryptString($r->command_cipher),true,512,JSON_THROW_ON_ERROR),'local_result'=>$saved['result'],'local_references'=>$saved['references'],'dependencies'=>json_decode($r->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($r->created_at,'UTC')->toIso8601String()];};
    $reject=function(callable $work,int $status,string $message){try{$work();throw new RuntimeException('GO product unexpectedly succeeded.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){verify($e->getStatusCode()===$status,$message.' ('.$e->getStatusCode().')');}};
    $replaceIdentity=function(){
        $owner=(array)DB::table('users')->where('id',60)->first();$store=(array)DB::table('go_stores')->where('user_id',60)->first();
        DB::table('go_stores')->where('user_id',60)->delete();DB::table('users')->where('id',60)->delete();$new=$owner;$new['created_at']='2021-02-01 00:00:00';DB::table('users')->insert($new);DB::table('go_stores')->insert($store);
        return function()use($owner,$store){DB::table('go_stores')->where('user_id',60)->delete();DB::table('users')->where('id',60)->delete();DB::table('users')->insert($owner);DB::table('go_stores')->insert($store);};
    };
    try{
        $switch(true);$csrf=$start(true);$count=DB::table('desktop_dashboard_commands')->count();$form=['_token'=>$csrf,'_desktop_command'=>(string)Str::uuid(),'name'=>'منتج جديد','description'=>'وصف عربي','unit'=>'كيلو','price'=>'125.50','available'=>'1','options'=>json_encode([['label'=>'ربع كيلو','price'=>'35.00']],JSON_UNESCAPED_UNICODE),'revision'=>'','request_key'=>(string)Str::uuid()];
        verify($http('/admin/go-stores/60/products',$form)[0]===422&&DB::table('desktop_dashboard_commands')->count()===$count,'the original GO create requires a real image and creates no success envelope on validation failure');
        DB::unprepared("CREATE TRIGGER reject_go_product_journal BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW BEGIN IF NEW.route_name='go-stores.products.store' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture GO product rollback'; END IF; END");
        try{verify($http('/admin/go-stores/60/products',$form,$png)[0]===500&&DB::table('go_store_products')->where('request_key',$form['request_key'])->count()===0
            &&count(glob($roots[true].'/app/public/go-stores/60/desktop-*'))===0,'a failed GO journal insertion rolls back original product/options and removes only the newly owned image');}finally{DB::unprepared('DROP TRIGGER reject_go_product_journal');}
        [$status,$body]=$http('/admin/go-stores/60/products',$form,$png);$create=$envelope($form['_desktop_command']);$product=$create['local_result']['after']['product'];$newIds[true][]=$product['id'];
        verify($status===302&&$product['name']==='منتج جديد'&&(int)$product['price_cents']===12550&&(int)$product['revision']===1&&count(json_decode($product['options'],true))===1
            &&str_starts_with($product['image_path'],'go-stores/60/desktop-'.$form['_desktop_command'].'-'),'the actual original GO controller persists product, one generated option and an immutable command image');
        verify($http('/admin/go-stores/60/products',$form,$png)[1]===$body&&DB::table('go_store_products')->where('request_key',$form['request_key'])->count()===1&&DB::table('desktop_dashboard_commands')->count()===$count+1,'a lost GO create reply reuses its UUID, row, option ID and image without another file write');
        DB::table('users')->where('id',60)->update(['created_at'=>null]);try{verify($http('/admin/go-stores/60/products',$form,$png)[0]===409,'a missing immutable GO owner creation timestamp requires connected review even for a cached CREATE reply');}finally{DB::table('users')->where('id',60)->update(['created_at'=>'2020-02-01 00:00:00']);}
        $restoreIdentity=$replaceIdentity();try{verify($http('/admin/go-stores/60/products',$form,$png)[0]===403,'an actual same-ID owner/profile delete and recreation rejects the cached local GO CREATE outcome');}finally{$restoreIdentity();}
        $bad=$form;$bad['price']='126.50';verify($http('/admin/go-stores/60/products',$bad,$png)[0]===409,'a saved GO command cannot acknowledge changed product content');
        $dependentForm=$form;$dependentForm['_desktop_command']=(string)Str::uuid();$dependentForm['revision']='1';$dependentForm['name']='منتج تابع بعد الإنشاء';
        $createdOption=json_decode($product['options'],true)[0]['id'];$dependentForm['options']=json_encode([['id'=>$createdOption,'label'=>'ربع كيلو','price'=>'36.00'],['label'=>'نصف كيلو','price'=>'70.00']],JSON_UNESCAPED_UNICODE);
        verify($http('/admin/go-stores/60/products/'.$product['id'],$dependentForm)[0]===302,'the original GO form edits a locally created product before it has reached the server');
        $dependent=$envelope($dependentForm['_desktop_command']);verify(in_array($create['command_id'],$dependent['dependencies'],true)
            &&($dependent['payload']['parameters']['product']['$desktop_ref']['entity']??null)==='menu_store_product','GO dependent product routes and beforefacts retain the named product-creation dependency');
        $originalProduct=(array)DB::table('go_store_products')->where('id',1)->first();$oldImage=$roots[true].'/app/public/'.$originalProduct['image_path'];
        $update=$form;$update['_desktop_command']=(string)Str::uuid();$update['revision']='1';$update['request_key']=$seedKey;$update['name']='تعديل صنف محفوظ';$update['options']=json_encode([['id'=>$option,'label'=>'نصف كيلو معدل','price'=>'60.00'],['label'=>'كيلو','price'=>'130.00']],JSON_UNESCAPED_UNICODE);
        [$status,$body]=$http('/admin/go-stores/60/products/1',$update);$edit=$envelope($update['_desktop_command']);$after=$edit['local_result']['after']['product'];
        verify($status===302&&(int)$after['revision']===2&&json_decode($after['options'],true)[0]['id']===$option&&count(json_decode($after['options'],true))===2&&file_get_contents($oldImage)===$png,
            'the original GO update retains an existing option ID, adds one distinct option and preserves immutable previous image bytes');
        verify($http('/admin/go-stores/60/products/1',$update)[1]===$body&&(int)DB::table('go_store_products')->where('id',1)->value('revision')===2,'a lost GO edit response cannot increment revision or replace option IDs twice');
        $oldBranches=DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->value('branches');DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>'["f:100"]']);
        try{verify($http('/admin/go-stores/60/products/1',$update)[0]===403,'cached local GO edit replies require current gs owner enrollment');}finally{DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>$oldBranches]);}
        if(is_resource($web)){fclose($pipes[0]);proc_terminate($web);proc_close($web);$web=null;}$switch(false);
        $collision=$originalProduct;$collision['id']=900;$collision['user_id']=61;$collision['request_key']=(string)Str::uuid();$collision['name']='unrelated numeric collision';DB::table('go_store_products')->insert($collision);$newIds[false][]=900;
        foreach([$create,$dependent,$edit] as $i=>$operation){
            $row=$operation['payload']['parameters']['product']??null;if(is_array($row))$row=$row['$desktop_ref']['local_id']??null;if($row===1){$before=(array)DB::table('go_store_products')->where('id',1)->first();DB::table('go_store_products')->where('id',1)->update(['description'=>'server changed']);
                $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operation),409,'GO full-row beforefacts reject an independent description change');DB::table('go_store_products')->where('id',1)->update($before);}
            if($i===0){$forged=$operation;$forged['local_result']['after']['profile']['row']['user_id']=61;
                $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$forged),409,'a fabricated unrelated GO owner after-state cannot obtain a receipt');}
            if($i===1){$wrong=$operation;$wrong['payload']['parameters']['product']['$desktop_ref']['entity']='catalog_product';
                $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$wrong),422,'a dependent GO product rejects another entity kind before ID resolution');}
            $tampered=$operation;$tampered['local_result']['after']['product']['price_cents']=99999;$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$tampered),409,'fabricated GO price results roll back original controller and receipt');
            verify(!DB::table('desktop_dashboard_commands')->where('command_id',$operation['command_id'])->exists(),'failed GO result verification creates no server receipt');
            $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operation);$newIds[false][]=$receipt['result']['after']['product']['id'];
            if($i===0)verify($receipt['result']['after']['product']['id']!==$product['id']&&$receipt['references']===[['entity'=>'menu_store_product','local_id'=>$product['id'],'server_id'=>$receipt['result']['after']['product']['id']]],
                'a real independent server auto-increment collision produces a typed GO product identity mapping');
            if($i===1)verify($receipt['result']['after']['product']['name']==='منتج تابع بعد الإنشاء'&&DB::table('go_store_products')->where('id',900)->value('name')==='unrelated numeric collision',
                'a dependent GO update follows the acknowledged product ID drift and preserves the colliding unrelated product');
            verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operation)&&$receipt['result']['after']['product']['options']===$operation['local_result']['after']['product']['options'],
                'GO original product replay preserves the exact generated option UUIDs and one durable server result '.$i);
            if($i===0){$restoreIdentity=$replaceIdentity();try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operation),403,'same-ID owner/profile recreation rejects an already committed server GO CREATE result');}finally{$restoreIdentity();}}
            $switch(true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$operation['command_id'],$receipt);$switch(false);
        }
        if(!\Illuminate\Support\Facades\Schema::hasTable('desktop_dashboard_remote_attempts')){require_once $application.'/database/migrations/2026_10_08_210000_create_desktop_dashboard_remote_attempts.php';(new \CreateDesktopDashboardRemoteAttempts)->up();}
        $csrf=$start(false);$native=['id'=>(string)Str::uuid(),'method'=>'POST','path'=>'/admin/go-stores/60/products/1'];
        $decide=function(array $attempt,string $action='reserve')use($http,$ownerLink){[$status,$body]=$http('/api/desktop-dashboard/remote-attempts',['action'=>$action]+$attempt,null,['Authorization: Bearer '.$ownerLink['token']]);return [$status,json_decode($body,true)];};
        [$status,$ready]=$decide($native);verify($status===200&&$ready['status']==='ready','the exact original GO multipart product POST reserves a native durable outcome');
        $proof=fn($r)=>['X-Fasakhansta-Remote-Attempt: '.$r['id'],'X-Fasakhansta-Remote-Capability: '.$r['capability']];
        $current=(array)DB::table('go_store_products')->where('id',1)->first();$nativeForm=$update;$nativeForm['_token']=$csrf;$nativeForm['_desktop_command']=(string)Str::uuid();$nativeForm['revision']=(string)$current['revision'];$nativeForm['name']='منتج معدل باتصال السيرفر';
        [$status,$body]=$http($native['path'],$nativeForm,$png,$proof($ready));$afterNative=(array)DB::table('go_store_products')->where('id',1)->first();
        verify($status===302&&$afterNative['name']==='منتج معدل باتصال السيرفر'&&$decide($native,'settle')[1]['status']==='committed','the original GO native controller and immutable image commit with its durable transport outcome');
        verify($http($native['path'],$nativeForm,$png,$proof($ready))[1]===$body&&(array)DB::table('go_store_products')->where('id',1)->first()===$afterNative,'a lost native GO upload response reuses the exact saved image, options and revision');
        $restoreIdentity=$replaceIdentity();try{verify($http($native['path'],$nativeForm,$png,$proof($ready))[0]===403&&$decide($native,'settle')[0]===403,'same-ID owner/profile recreation rejects cached and settled native GO product outcomes');}finally{$restoreIdentity();}
        $another=$native;$another['id']=(string)Str::uuid();[, $anotherProof]=$decide($another);verify($http($native['path'],$nativeForm,$png,$proof($anotherProof))[1]===$body&&$decide($another,'settle')[1]['status']==='committed','another GO transmission retains the original logical UUID without a second upload');
        $rollback=$native;$rollback['id']=(string)Str::uuid();[, $rollbackProof]=$decide($rollback);$rollbackForm=$nativeForm;$rollbackForm['_desktop_command']=(string)Str::uuid();$rollbackForm['revision']=(string)$afterNative['revision'];$filesBefore=glob($roots[false].'/app/public/go-stores/60/desktop-*');
        DB::unprepared("CREATE TRIGGER reject_go_native_outcome BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.operation_id='".$rollbackForm['_desktop_command']."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture GO native rollback'; END IF; END");
        try{verify($http($native['path'],$rollbackForm,$png,$proof($rollbackProof))[0]===500&&(array)DB::table('go_store_products')->where('id',1)->first()===$afterNative&&glob($roots[false].'/app/public/go-stores/60/desktop-*')===$filesBefore,
            'a failed native GO outcome rolls back product/options/revision and only its newly owned image');}finally{DB::unprepared('DROP TRIGGER reject_go_native_outcome');}
        verify($decide($rollback,'settle')[1]['status']==='cancelled','a failed original GO native upload resolves to a terminal cancelled transmission');
        $branches=DB::table('desktop_dashboard_devices')->where('id',$ownerDevice)->value('branches');DB::table('desktop_dashboard_devices')->where('id',$ownerDevice)->update(['branches'=>'["f:100"]']);
        try{verify($http($native['path'],$nativeForm,$png,$proof($ready))[0]===403&&$decide($native,'settle')[0]===403,'both cached native GO replies and settlement require current gs enrollment');}finally{DB::table('desktop_dashboard_devices')->where('id',$ownerDevice)->update(['branches'=>$branches]);}
        echo 'Original GO catalog products acceptance completed'.PHP_EOL;
    }finally{
        if(is_resource($web)){if(is_resource($pipes[0]))fclose($pipes[0]);proc_terminate($web);proc_close($web);}
        foreach([false,true] as $isLocal){$switch($isLocal);foreach($newIds[$isLocal]??[] as $id)if($id!==1)DB::table('go_store_products')->where('id',$id)->delete();DB::table('go_store_products')->where('id',1)->update($old[$isLocal]);DB::table('go_stores')->where('user_id',60)->update($profileBefore[$isLocal]);DB::table('users')->where('id',60)->update(['delegate_fees'=>$oldFee[$isLocal],'created_at'=>$oldOwnerTime[$isLocal]]);}
        config(['filesystems.disks.public.root'=>$oldRoot]);app('filesystem')->forgetDisk('public');$switch(false);config(['filesystems.disks.public.root'=>$oldRoot]);app('filesystem')->forgetDisk('public');
    }
})();
