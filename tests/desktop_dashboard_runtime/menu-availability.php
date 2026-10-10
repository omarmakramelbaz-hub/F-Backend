<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardReferences,DesktopDashboardMenuAvailability};

$menuSwitch=function(bool $local)use($ownerStage,$database){config(['database.connections.mysql.database'=>$local?$ownerStage:$database,'desktop_dashboard.local'=>$local]);DB::purge();};
$menuEnvelope=function($id)use($menuSwitch){
    $menuSwitch(true);$row=DB::table('desktop_dashboard_commands')->where('command_id',$id)->first();$saved=json_decode(Crypt::decryptString($row->local_result_cipher),true);
    return ['command_id'=>$id,'actor_id'=>1,'route_name'=>$row->route_name,'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true),
        'local_result'=>$saved['result'],'local_references'=>$saved['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String()];
};
$menuSwitch(true);
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$env);
$menuCommands=[];
try{
    for($n=0;$n<100;$n++){if($http('/_desktop/health')[0]===200)break;usleep(50000);}
    [$menuStatus,$menuPage]=$http('/admin/applies-orders');preg_match('/name="csrf-token" content="([^"]+)"/',$menuPage,$menuCsrf);
    verify($menuStatus===200&&isset($menuCsrf[1]),'the actual original order board prepares its availability controls and CSRF');
    foreach(['f:100','gs:60'] as $branch){
        [$menuStatus,$menuBody]=$http('/admin/order-board/menu?branch='.urlencode($branch));$listing=json_decode($menuBody,true);
        verify($menuStatus===200&&$listing['desktop_generation']===$ownerSnapshot['snapshot_id']&&$listing['can_toggle'],'the original menu listing binds its availability operation to the enrolled generation: '.$branch);
    }
    $before=DB::table('desktop_dashboard_commands')->count();
    $form=['_token'=>$menuCsrf[1],'idempotency_key'=>(string)Str::uuid(),'available'=>false,'expected_available'=>true,'expected_revision'=>1];
    verify($http('/admin/order-board/menu/f/100/products/2/availability',$form,['Accept: application/json'])[0]===404&&DB::table('desktop_dashboard_commands')->count()===$before,
        'a restaurant product in another branch cannot be toggled by a colliding menu path');
    verify($http('/admin/order-board/menu/gs/60/products/2/availability',$form,['Accept: application/json'])[0]===404&&DB::table('desktop_dashboard_commands')->count()===$before,
        'a store product belonging to another owner cannot enter the local journal');
    foreach(['f'=>100,'gs'=>60] as $kind=>$branchId){
        $path='/admin/order-board/menu/'.$kind.'/'.$branchId.'/products/1/availability';$table=$kind==='f'?'resturant_products':'go_store_products';$field=$kind==='f'?'status':'available';
        $form['idempotency_key']=(string)Str::uuid();$form['expected_revision']=$kind==='gs'?1:null;
        $invalid=$form;$invalid['available']='invalid';
        verify($http($path,$invalid,['Accept: application/json'])[0]===422&&DB::table('desktop_dashboard_commands')->count()===$before,'original availability validation commits no journal: '.$kind);
        DB::unprepared("CREATE TRIGGER fixture_menu_journal_rollback BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW BEGIN IF NEW.route_name='order-board.menu.availability' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture menu journal rollback'; END IF; END");
        try{verify($http($path,$form,['Accept: application/json'])[0]===500&&DB::table($table)->where('id',1)->value($field)===($kind==='f'?'show':1)&&DB::table('desktop_dashboard_commands')->count()===$before,
            'failed journal persistence rolls back actual availability and store revision: '.$kind);}finally{DB::unprepared('DROP TRIGGER fixture_menu_journal_rollback');}
        [$status,$body]=$http($path,$form,['Accept: application/json']);$result=json_decode($body,true);
        verify($status===200&&$result['item']['id']===1&&$result['item']['available']===false&&$http($path,$form,['Accept: application/json'])[1]===$body,
            'the original JSON availability reply and one immutable UUID survive a lost local response: '.$kind);
        $before++;$menuCommands[$kind]=[$form['idempotency_key'],$path,$form];
        $changed=$form;$changed['available']=true;
        verify($http($path,$changed,['Accept: application/json'])[0]===409&&DB::table('desktop_dashboard_commands')->count()===$before,'a committed availability UUID cannot request a different target: '.$kind);
        $oldType=DB::table('users')->where('id',1)->value('account_type');DB::table('users')->where('id',1)->update(['account_type'=>'user']);
        try{verify($http($path,$form,['Accept: application/json'])[0]===403,'a stored local availability reply still requires current original toggle authority: '.$kind);}
        finally{DB::table('users')->where('id',1)->update(['account_type'=>$oldType]);}
        $oldBranches=DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->value('branches');
        DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>json_encode(array_values(array_diff(json_decode($oldBranches,true),[$kind.':'.$branchId])))]);
        try{verify($http($path,$form,['Accept: application/json'])[0]===403,'a saved local availability reply requires its current prepared branch scope: '.$kind);}
        finally{DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>$oldBranches]);}
    }
    $adapter=app(DesktopDashboardMenuAvailability::class);$foreign=User::withoutGlobalScopes()->findOrFail(11);
    foreach([['kind'=>'f','branchId'=>100,'product'=>1],['kind'=>'gs','branchId'=>60,'product'=>1]] as $p){
        try{DB::transaction(fn()=>$adapter->authorize($p,$foreign));throw new RuntimeException('Foreign branch was accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===404,'the original current branch ownership rejects foreign availability: '.$p['kind']);}
    }
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}

foreach($menuCommands as $kind=>[$id,$path,$form]){
    $command=$menuEnvelope($id);$menuSwitch(false);$table=$kind==='f'?'resturant_products':'go_store_products';$field=$kind==='f'?'status':'available';
    DB::table($table)->where('id',1)->update([$field=>$kind==='f'?'hide':false]);
    try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Changed availability was accepted.');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&!DB::table('desktop_dashboard_commands')->where('command_id',$id)->exists(),'a changed server availability conflicts even if the current server target already matches the offline target: '.$kind.' ('.$error->getStatusCode().' '.$error->getMessage().')');}
    DB::table($table)->where('id',1)->update([$field=>$kind==='f'?'show':true]);
    if($kind==='gs'){
        DB::table($table)->where('id',1)->update(['revision'=>2]);
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Changed store revision was accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409,'a changed store product revision retains the local availability operation');}
        DB::table($table)->where('id',1)->update(['revision'=>1]);
    }
    $oldType=DB::table('users')->where('id',1)->value('account_type');DB::table('users')->where('id',1)->update(['account_type'=>'user']);
    try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Revoked availability authority was accepted.');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403,'current original authority is checked before availability reconciliation: '.$kind);}
    finally{DB::table('users')->where('id',1)->update(['account_type'=>$oldType]);}
    $oldBranches=DB::table('desktop_dashboard_devices')->where('id',$ownerDevice)->value('branches');
    DB::table('desktop_dashboard_devices')->where('id',$ownerDevice)->update(['branches'=>json_encode(array_values(array_diff(json_decode($oldBranches,true),[$kind.':'.($kind==='f'?100:60)])))]);
    try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Saved receipt bypassed changed device scope.');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403,'a cached receipt uses the current locked device branches rather than its stale caller object: '.$kind);}
    finally{DB::table('desktop_dashboard_devices')->where('id',$ownerDevice)->update(['branches'=>$oldBranches]);}
    DB::unprepared("CREATE TRIGGER fixture_menu_receipt_rollback BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW BEGIN IF NEW.route_name='order-board.menu.availability' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture menu receipt rollback'; END IF; END");
    try{try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Receipt failure was accepted.');}
        catch(\Illuminate\Database\QueryException $error){verify(DB::table($table)->where('id',1)->value($field)===($kind==='f'?'show':1),'failed server receipt rolls back the original product availability: '.$kind);}}
    finally{DB::unprepared('DROP TRIGGER fixture_menu_receipt_rollback');}
    $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
    verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command)&&count($receipt['references'])===2,'both typed branch and product identities reconcile once: '.$kind);
    DB::table('users')->where('id',1)->update(['account_type'=>'user']);
    try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Saved receipt bypassed availability authority.');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403,'an acknowledged availability receipt cannot bypass revoked current authority: '.$kind);}
    finally{DB::table('users')->where('id',1)->update(['account_type'=>$oldType]);}
    $menuSwitch(true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$id,$receipt);
}
$menuSwitch(false);
verify(DB::table('resturant_products')->where('id',2)->value('status')==='show'&&(int)DB::table('go_store_products')->where('id',2)->value('available')===1,'both unrelated branches retain their colliding menu product rows');
require __DIR__.'/menu-availability-identities.php';
require __DIR__.'/menu-availability-owner-http.php';
