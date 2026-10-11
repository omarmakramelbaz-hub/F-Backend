<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardRestaurantFlags};

// Isolated variables and exact prior-row restoration preserve every accepted fixture's state.
(function() use($database,$ownerStage,$ownerDevice,$remoteDevice,$link,$env,$application,$httpPort,$http,$profile,$origin){
    $switch=function(string $db,bool $local=false){config(['database.connections.mysql.database'=>$db,'desktop_dashboard.local'=>$local]);DB::purge();};
    $row=fn()=>(array)DB::table('resturants')->where('id',100)->first();
    $restore=function(array $state){unset($state['id']);DB::table('resturants')->where('id',100)->update($state);};
    $business=function(array $state){unset($state['updated_at']);return $state;};
    $onlyFlag=function(array $before,array $after,string $field)use($business){unset($before[$field],$after[$field]);return $business($before)===$business($after);};
    $snapshot=function(){
        $result=[];
        foreach(['users','orders','carts','resturant_products','resturant_areas','reviews','wishlists','media','wallets','advertisings','coupon_wheel_resturants','slidears','last_searches'] as $table)
            $result[$table]=DB::table($table)->orderBy('id')->get()->map(fn($value)=>(array)$value)->all();
        return $result;
    };
    $saved=function(string $uuid){
        $entry=DB::table('desktop_dashboard_commands')->where('command_id',$uuid)->first();$result=json_decode(Crypt::decryptString($entry->local_result_cipher),true,512,JSON_THROW_ON_ERROR);
        return ['command_id'=>$uuid,'actor_id'=>1,'route_name'=>$entry->route_name,'payload'=>json_decode(Crypt::decryptString($entry->command_cipher),true,512,JSON_THROW_ON_ERROR),
            'local_result'=>$result['result'],'local_references'=>$result['references'],'dependencies'=>json_decode($entry->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($entry->created_at,'UTC')->toIso8601String()];
    };
    $reject=function(callable $work,int $status,string $message){try{$work();throw new RuntimeException('Restaurant flag unexpectedly succeeded.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===$status,$message);}};
    $switch($database);$serverBefore=$row();$deviceBefore=(array)DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->first();
    $baseline=$serverBefore;$baseline['is_featured']='no';$baseline['under_contract']='no';$baseline['created_at']='2019-01-01 00:00:00';$baseline['updated_at']='2020-01-01 00:00:00';
    $restore($baseline);$serverRelated=$snapshot();
    $switch($ownerStage,true);$localBefore=$row();$restore($baseline);$localRelated=$snapshot();
    $localScopeBefore=(array)DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->first();
    $localEnv=$env;$localEnv['DB_DATABASE']=$ownerStage;$localEnv['DESKTOP_DASHBOARD_LOCAL']='true';
    $web=null;$pipes=[];$operations=[];$mappedId=97701;$createdMapped=false;
    try{
        $web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/restaurant-flags.log','a'],['file',$profile.'/restaurant-flags.log','a']],$pipes,$application,$localEnv);
        for($n=0;$n<100;$n++){if($http('/_desktop/health')[0]===200)break;usleep(50000);}
        [$status,$page]=$http('/admin/notifications');preg_match('/name="csrf-token" content="([^"]+)"/',$page,$csrf);
        verify($status===200&&isset($csrf[1]),'the original local session supplies real restaurant-flag CSRF');
        $actor=\App\Models\User::withoutGlobalScopes()->findOrFail(1);
        verify(!$actor->getAllPermissions()->pluck('name')->contains('resturant-edit')&&!$actor->getAllPermissions()->pluck('name')->contains('resturant-list'),'restaurant checkbox endpoint acceptance adds no original resource edit/list grant');
        $form=['_token'=>$csrf[1],'_desktop_command'=>(string)Str::uuid(),'is_featured'=>'on','page'=>'3'];$countBefore=DB::table('desktop_dashboard_commands')->count();
        [$status,$body,$headers]=$http('/admin/resturantchangeStatus/100',$form);
        $firstAfter=$row();verify($status===302&&in_array('Location: '.$origin.'/admin/resturants?page=3',$headers,true)&&$firstAfter['is_featured']==='yes'
            &&$onlyFlag($baseline,$firstAfter,'is_featured')&&DB::table('desktop_dashboard_commands')->count()===$countBefore+1,'the original featured checkbox updates only its field and timestamp with one durable command and its page redirect');
        $first=$saved($form['_desktop_command']);$operations[]=$first;
        verify(count($first['payload']['facts']['restaurant_flag_before']['row'])===29&&count($first['local_result']['after']['row'])===29
            &&$first['local_result']['after']['row']===$firstAfter&&$first['local_result']['success']===trans('messages.UpdateSuccessfully')
            &&$first['local_references']===['menu_restaurant'=>100],'restaurant flag facts and actual result retain all29 columns, original success and typed restaurant identity');
        verify($http('/admin/resturantchangeStatus/100',$form)[1]===$body&&DB::table('desktop_dashboard_commands')->count()===$countBefore+1&&$row()===$firstAfter,'a lost local featured redirect reuses its exact outcome without changing its timestamp');
        $changed=$form;unset($changed['is_featured']);verify($http('/admin/resturantchangeStatus/100',$changed,['Accept: application/json'])[0]===409&&$row()===$firstAfter,'the same restaurant-flag UUID cannot acknowledge changed checkbox content');
        $wrongRoute=$form;unset($wrongRoute['is_featured']);$wrongRoute['under_contract']='on';verify($http('/admin/resturantchangeUnderContract/100',$wrongRoute,['Accept: application/json'])[0]===409&&$row()===$firstAfter,'the two restaurant flag routes cannot share one logical operation UUID');
        foreach([false,true] as $header){$bad=$form;$bad['_desktop_command']=[(string)Str::uuid()];$extra=['Accept: application/json'];if($header)$extra[]='X-Fasakhansta-Command: '.Str::uuid();
            verify($http('/admin/resturantchangeStatus/100',$bad,$extra)[0]===422&&$row()===$firstAfter&&DB::table('desktop_dashboard_commands')->count()===$countBefore+1,'a raw local restaurant-flag UUID array is rejected before conversion'.($header?' with a valid header':' without a header'));}
        $bad=$form;$bad['_desktop_command']=(string)Str::uuid();verify($http('/admin/resturantchangeStatus/100',$bad,['Accept: application/json','X-Fasakhansta-Command: '.Str::uuid()])[0]===409&&$row()===$firstAfter,'conflicting restaurant flag form/header UUIDs cannot update or journal');
        $oldStatus=DB::table('users')->where('id',1)->value('status');DB::table('users')->where('id',1)->update(['status'=>'disabled']);try{verify($http('/admin/resturantchangeStatus/100',$form,['Accept: application/json'])[0]===403,'a disabled local actor cannot obtain a saved restaurant flag reply');}finally{DB::table('users')->where('id',1)->update(['status'=>$oldStatus]);}
        $oldOwner=DB::table('users')->where('id',1)->value('owner_resturant_id');DB::table('users')->where('id',1)->update(['owner_resturant_id'=>101]);try{verify($http('/admin/resturantchangeStatus/100',$form,['Accept: application/json'])[0]===403,'saved local restaurant flag replies require the current actor branch relationship');}finally{DB::table('users')->where('id',1)->update(['owner_resturant_id'=>$oldOwner]);}
        DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>json_encode(['f:101'])]);try{verify($http('/admin/resturantchangeStatus/100',$form,['Accept: application/json'])[0]===403,'a locally removed enrolled F branch cannot obtain a saved flag reply');}finally{DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>$localScopeBefore['branches']]);}
        usleep(1100000);
        $contract=['_token'=>$csrf[1],'_desktop_command'=>(string)Str::uuid(),'under_contract'=>'on','page'=>'9'];
        [$status,$body,$headers]=$http('/admin/resturantchangeUnderContract/100',$contract);$contractAfter=$row();
        verify($status===302&&in_array('Location: '.$origin.'/admin/resturants',$headers,true)&&$contractAfter['under_contract']==='yes'&&$onlyFlag($firstAfter,$contractAfter,'under_contract')
            &&$contractAfter['updated_at']!==$firstAfter['updated_at'],'the original contract checkbox ignores page and preserves the featured flag across an actual second boundary');$operations[]=$saved($contract['_desktop_command']);
        usleep(1100000);
        $last=['_token'=>$csrf[1],'_desktop_command'=>(string)Str::uuid(),'page'=>'7'];
        verify($http('/admin/resturantchangeStatus/100',$last)[0]===302&&$row()['is_featured']==='no'&&$row()['under_contract']==='yes'
            &&$row()['updated_at']!==$contractAfter['updated_at'],'an absent original featured checkbox means no and completes a three-operation temporal chain');$operations[]=$saved($last['_desktop_command']);
        $fourth=['_token'=>$csrf[1],'_desktop_command'=>(string)Str::uuid(),'is_featured'=>'on'];verify($http('/admin/resturantchangeStatus/100',$fourth)[0]===302,'the original featured controller prepares a complete before-state conflict fixture');$conflict=$saved($fourth['_desktop_command']);
        $fifth=['_token'=>$csrf[1],'_desktop_command'=>(string)Str::uuid()];verify($http('/admin/resturantchangeStatus/100',$fifth)[0]===302,'the original featured controller prepares a typed identity replay with its actual local after-state');$typed=$saved($fifth['_desktop_command']);
        DB::unprepared("CREATE TRIGGER reject_restaurant_flag_journal BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture restaurant flag journal rollback'");
        try{$rollback=['_token'=>$csrf[1],'_desktop_command'=>(string)Str::uuid(),'is_featured'=>'on'];$beforeRollback=$row();$journalBefore=DB::table('desktop_dashboard_commands')->count();
            verify($http('/admin/resturantchangeStatus/100',$rollback,['Accept: application/json'])[0]===500&&$row()===$beforeRollback&&DB::table('desktop_dashboard_commands')->count()===$journalBefore,'failure to persist the restaurant flag command rolls back its original field and timestamp update');}
        finally{DB::unprepared('DROP TRIGGER reject_restaurant_flag_journal');}
        verify($snapshot()===$localRelated,'local restaurant flags preserve all original related rows, media and financial balances');
        fclose($pipes[0]);proc_terminate($web);proc_close($web);$web=null;
        $switch($database);$actor=\App\Models\User::withoutGlobalScopes()->findOrFail(1);
        DB::table('resturants')->where('id',100)->update(['updated_at'=>'2020-01-02 00:00:00']);
        foreach($operations as $index=>$operation){
            $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operation);
            verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operation),'restaurant flag chain reconciles exactly once with an identical retained receipt '.$index);
            app(DesktopDashboardRestaurantFlags::class)->verifyAfter($receipt['result'],$operation['local_result'],app(\App\Services\Dashboard\DesktopDashboardReferences::class)->resolve($ownerDevice,$operation['payload']),$operation['payload']);
            verify($receipt['result']['after']['row']['created_at']===$baseline['created_at'],'restaurant flag replay retains the exact original creation identity '.$index);
            $switch($ownerStage,true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$operation['command_id'],$receipt);$switch($database);
        }
        verify($row()['is_featured']==='no'&&$row()['under_contract']==='yes','the three real local flag writes reconcile in order despite request/journal updated_at timing');
        $adapter=app(DesktopDashboardRestaurantFlags::class);$resolvedFirst=app(\App\Services\Dashboard\DesktopDashboardReferences::class)->resolve($ownerDevice,$operations[0]['payload']);
        $vendor=\App\Models\User::withoutGlobalScopes()->findOrFail(10);$vendorDevice=app(\App\Services\Dashboard\DesktopDashboardDevices::class)->device($link['token']);
        verify((int)$adapter->authorize($operations[0]['route_name'],$resolvedFirst,$vendor,$vendorDevice)->id===10,'the original fresh scoped restaurant binding permits its enrolled vendor without resource flag permissions');
        $foreign=new PDO('mysql:host=127.0.0.1;port='.config('database.connections.mysql.port').';dbname='.$database.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        DB::beginTransaction();try{DB::table('resturants')->where('id',100)->first();$foreign->exec('UPDATE resturants SET user_id=11 WHERE id=100');
            $reject(fn()=>$adapter->authorize($operations[0]['route_name'],$resolvedFirst,$vendor,$vendorDevice),403,'current locking reads reject restaurant ownership transferred after an older RR snapshot');}
        finally{DB::rollBack();$foreign->exec('UPDATE resturants SET user_id='.(int)$baseline['user_id'].' WHERE id=100');}
        $ownerActorBefore=(array)DB::table('users')->where('id',1)->first();$childBefore=(array)DB::table('resturants')->where('id',101)->first();
        DB::table('users')->where('id',1)->update(['account_type'=>'resturant_owner','owner_resturant_id'=>100]);DB::table('resturants')->where('id',101)->update(['parent_id'=>100]);
        try{$probe=\Illuminate\Http\Request::create(url('/admin/resturantchangeStatus/101'),'POST',['is_featured'=>'on']);$probeRoute=clone app('router')->getRoutes()->getByName('resturants.changeStatus');$probeRoute->bind($probe);$probe->setRouteResolver(fn()=>$probeRoute);
            $ownerPayload=$adapter->remotePayload('resturants.changeStatus',$probe,(string)Str::uuid(),$actor,$remoteDevice);
            verify($ownerPayload['parameters']['resturant']===101,'an enrolled restaurant owner uses the exact original child-restaurant scope under a freshly loaded guard');
            DB::table('resturants')->where('id',101)->update(['parent_id'=>null]);$reject(fn()=>$adapter->authorize('resturants.changeStatus',$ownerPayload,$actor,$remoteDevice),403,'removing a current child-parent relationship rejects saved restaurant-owner flag authority');}
        finally{DB::table('users')->where('id',1)->update(['account_type'=>$ownerActorBefore['account_type'],'owner_resturant_id'=>$ownerActorBefore['owner_resturant_id']]);DB::table('resturants')->where('id',101)->update(['parent_id'=>$childBefore['parent_id']]);}
        foreach(['status'=>'disabled','owner_resturant_id'=>101] as $field=>$value){$old=DB::table('users')->where('id',1)->value($field);DB::table('users')->where('id',1)->update([$field=>$value]);
            try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operations[0]),403,'a saved restaurant flag server receipt checks current actor '.$field.' before deduplication');}finally{DB::table('users')->where('id',1)->update([$field=>$old]);}}
        DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['enabled'=>false]);try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operations[0]),401,'a revoked current device cannot obtain a cached restaurant flag receipt');}finally{DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['enabled'=>$deviceBefore['enabled']]);}
        DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>json_encode(['f:101'])]);try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operations[0]),403,'a server-removed enrolled F branch rejects cached restaurant flag receipts');}finally{DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>$deviceBefore['branches']]);}
        $beforeConflict=$row();
        foreach(['created_at'=>'2019-01-02 00:00:00','min_order_price'=>'987.65','control'=>$beforeConflict['control']==='show'?'hide':'show','sortby_is_featured'=>987,'user_id'=>11,'parent_id'=>101] as $field=>$value){
            DB::table('resturants')->where('id',100)->update([$field=>$value]);$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$conflict),409,'changed restaurant '.$field.' conflicts before original flag update or receipt');
            verify(!DB::table('desktop_dashboard_commands')->where('command_id',$conflict['command_id'])->exists()&&$row()['is_featured']==='no','a restaurant before-state conflict retains its original flag and creates no receipt '.$field);$restore($beforeConflict);
        }
        $bad=$conflict;$bad['command_id']=(string)Str::uuid();$bad['payload']['values']['idempotency_key']=$bad['command_id'];$bad['payload']['facts']='not-an-array';
        $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$bad),422,'malformed scalar restaurant facts produce controlled422 without PHP TypeError');
        verify($row()===$beforeConflict&&!DB::table('desktop_dashboard_commands')->where('command_id',$bad['command_id'])->exists(),'malformed restaurant facts change no row and create no receipt');
        $tampered=$conflict;$tampered['local_result']['after']['row']['under_contract']='no';$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$tampered),409,'a fabricated unrelated local after-field rolls back original flag reconciliation');
        verify($row()===$beforeConflict&&!DB::table('desktop_dashboard_commands')->where('command_id',$conflict['command_id'])->exists(),'after-state disagreement rolls back the original flag and journal together');
        foreach(['missing','array'] as $kind){$tampered=$conflict;if($kind==='missing')unset($tampered['local_result']['after']['row']['updated_at']);else $tampered['local_result']['after']['row']['updated_at']=['invalid'];
            $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$tampered),409,'restaurant after-state keeps its complete typed timestamp even when comparison excludes timing '.$kind);
            verify($row()===$beforeConflict&&!DB::table('desktop_dashboard_commands')->where('command_id',$conflict['command_id'])->exists(),'invalid restaurant result timestamp rolls back its original flag and receipt '.$kind);}
        DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['actor_id'=>10]);
        try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$conflict),403,'a stale device object cannot reconcile after its persisted actor is replaced');
            verify($row()===$beforeConflict&&!DB::table('desktop_dashboard_commands')->where('command_id',$conflict['command_id'])->exists(),'actor replacement changes no restaurant flag and records no receipt');}
        finally{DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['actor_id'=>$deviceBefore['actor_id']]);}
        $relatedBefore=$snapshot();$receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$conflict);verify($row()['is_featured']==='yes'&&$row()['under_contract']==='yes'&&$snapshot()===$relatedBefore,'restored complete flag facts reconcile without changing any related row');
        $switch($ownerStage,true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$conflict['command_id'],$receipt);$switch($database);
        verify(!DB::table('resturants')->where('id',$mappedId)->exists(),'the typed restaurant fixture uses an independently confirmed unused server identity');
        $mappedRow=$row();$mappedRow['id']=$mappedId;DB::table('resturants')->insert($mappedRow);$createdMapped=true;
        $branches=json_decode($deviceBefore['branches'],true,512,JSON_THROW_ON_ERROR);$branches[]='f:'.$mappedId;DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>json_encode(array_values(array_unique($branches)))]);
        $dependency=(string)Str::uuid();DB::table('desktop_dashboard_commands')->insert(['device_id'=>$ownerDevice,'command_id'=>$dependency,'actor_id'=>1,'route_name'=>'restaurant-flag-fixture.source',
            'request_hash'=>str_repeat('a',64),'command_cipher'=>Crypt::encryptString('{}'),'local_result_cipher'=>Crypt::encryptString('{}'),
            'server_result_cipher'=>Crypt::encryptString(json_encode(['references'=>[['entity'=>'menu_restaurant','local_id'=>100,'server_id'=>$mappedId]]])),
            'dependencies'=>'[]','status'=>'acknowledged','attempts'=>0,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        $mapped=$typed;$ref=['$desktop_ref'=>['entity'=>'menu_restaurant','command_id'=>$dependency,'local_id'=>100]];
        $mapped['payload']['parameters']['resturant']=$ref;$mapped['payload']['facts']['restaurant_flag_before']['row']['id']=$ref;$mapped['payload']['facts']['restaurant_flag_before']['branch']['id']=$ref;$mapped['dependencies']=[$dependency];
        $wrong=$mapped;$wrong['payload']['parameters']['resturant']['$desktop_ref']['entity']='catalog_product';$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$wrong),422,'a restaurant flag rejects another entity kind before numeric reference resolution');
        $mappedReceipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$mapped);
        verify(DB::table('resturants')->where('id',$mappedId)->value('is_featured')==='no'&&$row()['is_featured']==='yes'
            &&$mappedReceipt['references']===[['entity'=>'menu_restaurant','local_id'=>100,'server_id'=>$mappedId]],'typed restaurant flag replay changes only the mapped restaurant and preserves a colliding numeric ID');
        $switch($ownerStage,true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$typed['command_id'],$mappedReceipt);$switch($database);
        verify($snapshot()===$serverRelated,'server restaurant flags preserve original related media, users, orders and financial balances');
        echo 'Restaurant flag original local and reconciliation acceptance completed'.PHP_EOL;
    }finally{
        if(is_resource($web)){if(isset($pipes[0])&&is_resource($pipes[0]))fclose($pipes[0]);proc_terminate($web);proc_close($web);}
        $switch($ownerStage,true);$restore($localBefore);DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>$localScopeBefore['branches']]);
        $switch($database);$restore($serverBefore);if($createdMapped)DB::table('resturants')->where('id',$mappedId)->delete();DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['actor_id'=>$deviceBefore['actor_id'],'enabled'=>$deviceBefore['enabled'],'branches'=>$deviceBefore['branches']]);
    }
})();
