<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardReferences};

// Exercise the original global area forms and their mapped parent dependencies.
config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$env);
$areaCommands=[];
try{
    for($n=0;$n<100;$n++){[$status]=$http('/_desktop/health');if($status===200)break;usleep(50000);}
    [$status,$page]=$http('/admin/areas/create');preg_match('/name="_token" value="([^"]+)"/',$page,$areaCsrf);
    verify($status===200&&isset($areaCsrf[1])&&str_contains($page,'name="title_ar"'),'the original area creation page retains its fields and current owner permission');
    $area=['_token'=>$areaCsrf[1],'_desktop_command'=>(string)Str::uuid(),'added_by'=>1,'parent_id'=>null,'title_ar'=>'منطقة محلية أصلية','title_en'=>'Local area'];
    verify($http('/admin/areas',$area)[0]===302,'the original area controller creates locally without inserting transport fields');$areaCommands[]=$area['_desktop_command'];
    $areaParent=(int)DB::table('areas')->where('title_ar',$area['title_ar'])->value('id');
    verify($areaParent>0&&$http('/admin/areas',$area)[0]===302&&DB::table('areas')->where('title_ar',$area['title_ar'])->count()===1,'a lost original area creation reply retains one area and its command');
    $area['_desktop_command']=(string)Str::uuid();$area['parent_id']=$areaParent;$area['title_ar']='منطقة محلية تابعة';
    verify($http('/admin/areas',$area)[0]===302,'the original child area form records its locally created parent dependency');$areaCommands[]=$area['_desktop_command'];
    $areaChild=(int)DB::table('areas')->where('title_ar',$area['title_ar'])->value('id');
    $area['_desktop_command']=(string)Str::uuid();$area['_method']='PUT';$area['title_ar']='منطقة محلية تابعة معدلة';
    verify($http('/admin/areas/'.$areaChild,$area)[0]===302&&DB::table('areas')->where('id',$areaChild)->value('title_ar')===$area['title_ar'],'the original scalar area update parameter is journaled with its complete before-state');$areaCommands[]=$area['_desktop_command'];
    verify($http('/admin/areas/'.$areaChild,$area)[0]===302,'a lost area update reply reuses its original dependency facts');
    $invalid=$area;unset($invalid['_method']);$invalid['_desktop_command']=(string)Str::uuid();$invalid['title_ar']='';
    $before=DB::table('desktop_dashboard_commands')->count();
    verify($http('/admin/areas',$invalid)[0]===302&&DB::table('desktop_dashboard_commands')->count()===$before,'original area validation redirects without queuing a successful write');
    $area['_desktop_command']=(string)Str::uuid();unset($area['_method']);$area['title_ar']='منطقة محلية للحذف';$area['parent_id']=null;
    verify($http('/admin/areas',$area)[0]===302,'the original area form creates a single-delete fixture');$areaCommands[]=$area['_desktop_command'];
    $areaExtra=(int)DB::table('areas')->where('title_ar',$area['title_ar'])->value('id');
    $remove=['_token'=>$areaCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid()];
    verify($http('/admin/areas/'.$areaExtra,$remove)[0]===302&&!DB::table('areas')->where('id',$areaExtra)->exists()
        &&$http('/admin/areas/'.$areaExtra,$remove)[0]===302,'the original area deletion retries before binding its removed model');$areaCommands[]=$remove['_desktop_command'];
    $bulk=['_token'=>$areaCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid(),'ids'=>$areaChild.','.$areaParent];
    verify($http('/admin/areasDeleteAll',$bulk,['Accept: application/json'])[0]===200&&DB::table('areas')->whereIn('id',[$areaParent,$areaChild])->count()===0,'the original snake-case area bulk controller commits one atomic local command');
    $bulk['ids']=$areaParent.','.$areaChild;
    verify($http('/admin/areasDeleteAll',$bulk,['Accept: application/json'])[0]===200,'reordered area bulk deletion returns its stored receipt after all rows disappear');$areaCommands[]=$bulk['_desktop_command'];
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
foreach([$areaParent,$areaChild,$areaExtra] as $id)DB::table('areas')->insert(['id'=>$id,'added_by'=>1,'title_ar'=>'منطقة سيرفر مستقلة '.$id,'title_en'=>'Server collision']);
foreach($areaCommands as $id){
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
    $row=DB::table('desktop_dashboard_commands')->where('command_id',$id)->first();$saved=json_decode(Crypt::decryptString($row->local_result_cipher),true);
    $command=['command_id'=>$id,'actor_id'=>1,'route_name'=>$row->route_name,'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true),
        'local_result'=>$saved['result'],'local_references'=>$saved['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String()];
    config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
    $resolved=app(DesktopDashboardReferences::class)->resolve($remoteDevice->id,$command['payload']);
    if($row->route_name==='areas.update'){
        verify($resolved['parameters']['area']!==$areaChild&&$resolved['values']['parent_id']!==$areaParent
            &&(int)DB::table('areas')->where('id',$resolved['parameters']['area'])->value('parent_id')===$resolved['values']['parent_id'],
            'area route and parent references map to their own server records despite integer collisions');
    }
    if($row->route_name==='areas.destroy-all'){
        $selected=$resolved['values']['ids'];$first=$selected[0];$original=DB::table('areas')->where('id',$first)->value('title_ar');
        DB::table('areas')->where('id',$first)->update(['title_ar'=>'تعديل منطقة مستقل على السيرفر']);
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Changed area batch was deleted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&DB::table('areas')->whereIn('id',$selected)->count()===count($selected),'one changed area preserves every server row in the pending bulk deletion');}
        DB::table('areas')->where('id',$first)->update(['title_ar'=>$original]);
    }
    if(in_array($row->route_name,['areas.destroy','areas.destroy-all'],true)){
        $selected=$row->route_name==='areas.destroy-all'?$resolved['values']['ids']:[$resolved['parameters']['area']];
        $currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('areas-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Revoked area deletion was accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403&&DB::table('areas')->whereIn('id',$selected)->count()===count($selected),'current original area permissions protect all selected rows before reconciliation');}
        $currentRole->givePermissionTo('areas-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
    $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
    verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command),'the original area controller reconciles its command exactly once: '.$row->route_name);
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$id,$receipt);
}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
verify(DB::table('areas')->where('title_ar','like','منطقة محلية%')->count()===0&&DB::table('areas')->whereIn('id',[$areaParent,$areaChild,$areaExtra])->count()===3,
    'mapped original area deletion preserves every unrelated server row with a colliding local ID');
