<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardReferences};

config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$env);
$contractCommands=[];$contractIds=[];
try{
    for($n=0;$n<100;$n++){[$status]=$http('/_desktop/health');if($status===200)break;usleep(50000);}
    [$status,$page]=$http('/admin/contracts/create');preg_match('/name="_token" value="([^"]+)"/',$page,$contractCsrf);
    verify($status===200&&isset($contractCsrf[1])&&preg_match('/<select[^>]+name="type"/',$page)&&str_contains($page,'[vendorName]'),'the original contract template form can select its required type while retaining its editor and placeholders');
    $contractForm=['_token'=>$contractCsrf[1],'_desktop_command'=>(string)Str::uuid(),'added_by'=>1,'type'=>'delegate','template'=>'<p>قالب محلي أصلي · [vendorName] · [contractDate]</p>'];
    verify($http('/admin/contracts',$contractForm)[0]===302,'the original contract FormRequest persists only validated template fields and one local command');$contractCommands[]=$contractForm['_desktop_command'];
    $first=(int)DB::table('contracts')->where('template',$contractForm['template'])->value('id');$contractIds[]=$first;
    verify($first>0&&$http('/admin/contracts',$contractForm)[0]===302&&DB::table('contracts')->where('template',$contractForm['template'])->count()===1,'a lost original contract creation reply preserves a single template and its exact placeholders');
    [$status,$editPage]=$http('/admin/contracts/'.$first.'/edit');
    verify($status===200&&str_contains($editPage,'name="type" value="delegate"'),'the original contract edit form retains its saved hidden type');
    $contractForm['_method']='PUT';$contractForm['_desktop_command']=(string)Str::uuid();$contractForm['template']='<p>قالب محلي معدل · [vendorName] · [vendorMobile]</p>';
    verify($http('/admin/contracts/'.$first,$contractForm)[0]===302&&DB::table('contracts')->where('id',$first)->value('template')===$contractForm['template'],'the original contract update keeps HTML and placeholders with its before-state');$contractCommands[]=$contractForm['_desktop_command'];
    verify($http('/admin/contracts/'.$first,$contractForm)[0]===302,'a lost original contract update returns its saved local response');
    $invalid=$contractForm;unset($invalid['_method']);$invalid['_desktop_command']=(string)Str::uuid();$invalid['type']='invalid';$before=DB::table('desktop_dashboard_commands')->count();
    verify($http('/admin/contracts',$invalid)[0]===302&&DB::table('desktop_dashboard_commands')->count()===$before,'the original contract type validation redirects without committing another template or journal entry');
    unset($contractForm['_method']);$contractForm['_desktop_command']=(string)Str::uuid();$contractForm['type']='vendor';$contractForm['template']='<p>قالب محلي للحذف</p>';
    verify($http('/admin/contracts',$contractForm)[0]===302,'the original vendor contract form creates its deletion fixture');$contractCommands[]=$contractForm['_desktop_command'];
    $second=(int)DB::table('contracts')->where('template',$contractForm['template'])->value('id');$contractIds[]=$second;
    $remove=['_token'=>$contractCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid()];
    verify($http('/admin/contracts/'.$second,$remove)[0]===302&&!DB::table('contracts')->where('id',$second)->exists()
        &&$http('/admin/contracts/'.$second,$remove)[0]===302,'the original contract deletion retries its UUID before binding the removed template');$contractCommands[]=$remove['_desktop_command'];
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
foreach($contractIds as $id)DB::table('contracts')->insert(['id'=>$id,'added_by'=>1,'type'=>'vendor','template'=>'قالب سيرفر مستقل '.$id]);
foreach($contractCommands as $id){
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
    $row=DB::table('desktop_dashboard_commands')->where('command_id',$id)->first();$saved=json_decode(Crypt::decryptString($row->local_result_cipher),true);
    $command=['command_id'=>$id,'actor_id'=>1,'route_name'=>$row->route_name,'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true),
        'local_result'=>$saved['result'],'local_references'=>$saved['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String()];
    config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
    $resolved=app(DesktopDashboardReferences::class)->resolve($remoteDevice->id,$command['payload']);
    if($row->route_name!=='contracts.store'){
        $mapped=$resolved['parameters']['contract'];verify(!in_array($mapped,$contractIds,true),'the original contract reference maps around unrelated colliding server IDs');
        $original=DB::table('contracts')->where('id',$mapped)->value('template');DB::table('contracts')->where('id',$mapped)->update(['template'=>'قالب تغير على السيرفر']);
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Changed contract template was overwritten.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&DB::table('contracts')->where('id',$mapped)->value('template')==='قالب تغير على السيرفر','a changed server contract remains intact while the offline update or deletion is retained');}
        DB::table('contracts')->where('id',$mapped)->update(['template'=>$original]);
        $permission=$row->route_name==='contracts.destroy'?'contract-delete':'contract-edit';$currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);
        $currentRole->revokePermissionTo($permission);app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Revoked contract mutation was accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403&&DB::table('contracts')->where('id',$mapped)->exists(),'current original contract permissions protect the mapped template before replay');}
        $currentRole->givePermissionTo($permission);app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
    $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
    verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command),'the original contract controller reconciles exactly once: '.$row->route_name);
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$id,$receipt);
}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
verify(DB::table('contracts')->whereIn('id',$contractIds)->count()===count($contractIds)
    &&DB::table('contracts')->where('template','like','<p>قالب محلي معدل%')->count()===1,'mapped contract reconciliation preserves every unrelated template and one exact edited template');
