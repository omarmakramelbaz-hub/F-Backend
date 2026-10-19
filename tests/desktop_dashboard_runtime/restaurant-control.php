<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardRestaurantControl,DesktopDashboardReferences};

(function()use($database,$ownerStage,$ownerDevice,$remoteDevice,$env,$application,$httpPort,$http,$profile,$origin){
    $switch=function(string $db,bool $local=false){config(['database.connections.mysql.database'=>$db,'desktop_dashboard.local'=>$local]);DB::purge();};
    $rows=fn()=>DB::table('resturants')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
    $restore=function(array $values){foreach($values as $row){$id=$row['id'];unset($row['id']);DB::table('resturants')->where('id',$id)->update($row);}};
    $business=function(array $values){foreach($values as &$r)unset($r['updated_at']);unset($r);return $values;};
    $related=function(){ $data=[];foreach(['users','resturant_products','resturant_areas','orders','carts','wallets','media','reviews','wishlists'] as $table)
        $data[$table]=DB::table($table)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();return $data;};
    $reject=function(callable $work,int $status,string $message){try{$work();throw new RuntimeException('Control unexpectedly succeeded.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){verify($e->getStatusCode()===$status,$message);}};
    $saved=function(string $uuid){$r=DB::table('desktop_dashboard_commands')->where('command_id',$uuid)->first();$v=json_decode(Crypt::decryptString($r->local_result_cipher),true,512,JSON_THROW_ON_ERROR);
        return ['command_id'=>$uuid,'actor_id'=>(int)$r->actor_id,'route_name'=>$r->route_name,'payload'=>json_decode(Crypt::decryptString($r->command_cipher),true,512,JSON_THROW_ON_ERROR),
            'local_result'=>$v['result'],'local_references'=>$v['references'],'dependencies'=>json_decode($r->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($r->created_at,'UTC')->toIso8601String()];};
    $get=fn(array $query=[],array $headers=[],string $method='GET')=>$http('/admin/resturantControl'.($query?'?'.http_build_query($query):''),null,['Referer: '.$origin.'/admin/dashboard',...$headers],$method);
    $switch($database);$serverBefore=$rows();$deviceBefore=(array)DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->first();$serverRelated=$related();
    $baseline=$serverBefore;foreach($baseline as &$r){$r['control']=$r['id']===100?'show':'hide';$r['updated_at']='2020-01-01 00:00:00';}unset($r);$restore($baseline);
    $switch($ownerStage,true);$localBefore=$rows();$restore($baseline);$localRelated=$related();$localScope=(array)DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->first();
    $web=null;$pipes=[];$operations=[];
    try{
        foreach([$database,$ownerStage] as $db){$switch($db,$db===$ownerStage);DB::table('resturants')->insert(['id'=>82,'added_by'=>1,'user_id'=>10,'name'=>'excluded control fixture','control'=>'show']);}
        $switch($ownerStage,true);$localEnv=$env;$localEnv['DB_DATABASE']=$ownerStage;$localEnv['DESKTOP_DASHBOARD_LOCAL']='true';
        $web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/restaurant-control.log','a'],['file',$profile.'/restaurant-control.log','a']],$pipes,$application,$localEnv);
        for($i=0;$i<100;$i++){if($http('/_desktop/health')[0]===200)break;usleep(50000);}
        $before=$rows();$count=DB::table('desktop_dashboard_commands')->count();
        verify($get([],['Accept: application/json'])[0]===422&&$get(['_desktop_command'=>[(string)Str::uuid()]],['Accept: application/json'])[0]===422&&$rows()===$before,
            'the actual original global GET requires one scalar operation UUID before any toggle');
        verify($get(['_desktop_command'=>(string)Str::uuid()],['Accept: application/json'],'HEAD')[0]===405&&$rows()===$before,'an automatic HEAD cannot toggle global restaurant control');
        foreach([['show','hide'],['hide','show']] as $index=>$expected){
            $id=(string)Str::uuid();$query=['_desktop_command'=>$id];$before=$rows();
            [$status,$body,$headers]=$get($query);$after=$rows();$operation=$saved($id);$operations[]=$operation;
            verify($status===302&&in_array('Location: '.$origin.'/admin/dashboard',$headers,true)&&$after[0]['id']===82&&$after[0]['control']==='show'
                &&DB::table('resturants')->where('id',100)->value('control')===$expected[1]&&DB::table('resturants')->where('id',101)->value('control')===$expected[0],
                'the original controller toggles every enrolled selected restaurant once and excludes ID82 '.$index);
            $clean=$after;foreach($clean as &$r)foreach($before as $old)if($old['id']===$r['id'])$r['control']=$old['control'];unset($r);
            verify($business($clean)===$business($before)&&count($operation['payload']['facts']['restaurant_control_before'])===2
                &&count($operation['local_result']['after'])===2&&$operation['local_references']===[],
                'global control retains all29 original columns and changes only control/timestamps '.$index);
            verify($get($query)[1]===$body&&$rows()===$after&&DB::table('desktop_dashboard_commands')->count()===$count+$index+1,
                'a lost original local global-toggle reply cannot toggle the fleet twice '.$index);
            verify($get($query+['control'=>'show'],['Accept: application/json'])[0]===422&&$rows()===$after,'an extra control value cannot widen the original query payload '.$index);
            verify($get($query,['Accept: application/json','X-Fasakhansta-Command: '.Str::uuid()])[0]===409&&$rows()===$after,'conflicting command aliases cannot retoggle the fleet '.$index);
            $old=DB::table('users')->where('id',1)->value('status');DB::table('users')->where('id',1)->update(['status'=>'disabled']);
            try{verify($get($query,['Accept: application/json'])[0]===403&&$rows()===$after,'disabled local actors cannot recover a cached global-toggle outcome '.$index);}finally{DB::table('users')->where('id',1)->update(['status'=>$old]);}
            DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>json_encode(['f:100'])]);
            try{verify($get($query,['Accept: application/json'])[0]===403&&$rows()===$after,'a partial local enrollment cannot replay a global action as a silent branch subset '.$index);}finally{DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>$localScope['branches']]);}
        }
        DB::unprepared("CREATE TRIGGER reject_global_control_journal BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture global control rollback'");
        try{$before=$rows();$queued=DB::table('desktop_dashboard_commands')->count();verify($get(['_desktop_command'=>(string)Str::uuid()],['Accept: application/json'])[0]===500&&$rows()===$before
            &&DB::table('desktop_dashboard_commands')->count()===$queued,'a failed local journal write rolls back every original fleet toggle and timestamp');}finally{DB::unprepared('DROP TRIGGER reject_global_control_journal');}
        verify($related()===$localRelated,'local global control preserves all related customer, product, image and financial rows');
        fclose($pipes[0]);proc_terminate($web);proc_close($web);$web=null;$switch($database);
        foreach($operations as $index=>$operation){
            $before=$rows();DB::table('resturants')->where('id',101)->update(['min_order_price'=>'987.65']);
            $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operation),409,'a complete current-row change conflicts before any fleet toggle '.$index);
            verify(DB::table('resturants')->where('id',100)->value('control')===$before[1]['control']&&!DB::table('desktop_dashboard_commands')->where('command_id',$operation['command_id'])->exists(),
                'a global conflict leaves the other branch and all journal receipts untouched '.$index);$restore($before);
            $tampered=$operation;$tampered['local_result']['after'][0]['status']='hide';$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$tampered),409,'a fabricated unrelated local result rolls back the full global toggle '.$index);
            verify($rows()===$before,'failed global after-state verification rolls back every original branch write '.$index);
            $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operation);verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operation)
                &&$receipt['references']===[],'an exact global-toggle replay recovers one encrypted server receipt '.$index);
            DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>json_encode(['f:100'])]);
            try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operation),403,'cached server global receipts require enrollment for the entire fleet '.$index);}finally{DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>$deviceBefore['branches']]);}
            $old=DB::table('users')->where('id',1)->value('account_type');DB::table('users')->where('id',1)->update(['account_type'=>'vendor']);
            try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operation),403,'a current non-admin cannot recover the wider global-toggle receipt '.$index);}finally{DB::table('users')->where('id',1)->update(['account_type'=>$old]);}
            $switch($ownerStage,true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$operation['command_id'],$receipt);$switch($database);
        }
        verify($related()===$serverRelated,'server global control preserves all original related rows and balances');
        $adapter=app(DesktopDashboardRestaurantControl::class);$raw=app(DesktopDashboardReferences::class)->resolve($ownerDevice,$operations[0]['payload']);
        $typed=$adapter->inputs($raw,fn($entity,$id)=>['$desktop_ref'=>['entity'=>$entity,'command_id'=>(string)Str::uuid(),'local_id'=>$id]]);$adapter->validateReferences($typed);
        $typed['facts']['restaurant_control_before'][0]['id']['$desktop_ref']['entity']='catalog_product';$reject(fn()=>$adapter->validateReferences($typed),422,'global branch references reject colliding product entity types');
        echo 'Original global restaurant control acceptance completed'.PHP_EOL;
    }finally{
        if(is_resource($web)){if(isset($pipes[0])&&is_resource($pipes[0]))fclose($pipes[0]);proc_terminate($web);proc_close($web);}
        $switch($ownerStage,true);DB::table('resturants')->where('id',82)->delete();$restore($localBefore);DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>$localScope['branches']]);
        $switch($database);DB::table('resturants')->where('id',82)->delete();$restore($serverBefore);DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>$deviceBefore['branches']]);
    }
})();
