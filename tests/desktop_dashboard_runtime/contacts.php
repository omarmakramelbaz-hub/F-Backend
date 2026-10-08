<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation};

// Delete messages imported through the real primary-admin snapshot, preserving changed messages.
config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$env);
$contactCommands=[];
try{
    for($n=0;$n<100;$n++){[$status]=$http('/_desktop/health');if($status===200)break;usleep(50000);}
    [$status,$page]=$http('/admin/contacts');preg_match('/name="csrf-token" content="([^"]+)"/',$page,$contactCsrf);
    verify($status===200&&isset($contactCsrf[1])&&str_contains($page,'محتوى رسالة التواصل 85001'),'the original contact listing renders the administrator messages and deletion forms');
    $single=['_token'=>$contactCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid()];
    verify($http('/admin/contacts/85001',$single)[0]===302&&!DB::table('contacts')->where('id',85001)->exists()
        &&$http('/admin/contacts/85001',$single)[0]===302,'the original local contact deletion retries before binding its removed message');$contactCommands[]=$single['_desktop_command'];
    $bulk=['_token'=>$contactCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid(),'ids'=>'85003,85002'];
    verify($http('/admin/contactsDeleteAll',$bulk,['Accept: application/json'])[0]===200&&DB::table('contacts')->whereIn('id',[85002,85003])->count()===0,'the original contact bulk controller journals all selected messages in one transaction');
    $bulk['ids']='85002,85003';verify($http('/admin/contactsDeleteAll',$bulk,['Accept: application/json'])[0]===200,'reordered local contact deletion returns its stored receipt after both messages disappear');$contactCommands[]=$bulk['_desktop_command'];
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}
foreach($contactCommands as $id){
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
    $row=DB::table('desktop_dashboard_commands')->where('command_id',$id)->first();$saved=json_decode(Crypt::decryptString($row->local_result_cipher),true);
    $command=['command_id'=>$id,'actor_id'=>1,'route_name'=>$row->route_name,'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true),
        'local_result'=>$saved['result'],'local_references'=>$saved['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String()];
    config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
    $ids=$row->route_name==='contacts.destroy'?[85001]:[85002,85003];$original=DB::table('contacts')->where('id',$ids[0])->value('message');
    DB::table('contacts')->where('id',$ids[0])->update(['message'=>'رسالة تغيرت بعد تجهيز الجهاز']);
    try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Changed message was deleted.');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&DB::table('contacts')->whereIn('id',$ids)->count()===count($ids),'a changed contact message preserves the complete single or bulk selection');}
    DB::table('contacts')->where('id',$ids[0])->update(['message'=>$original]);
    $currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('contact-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Revoked message deletion was accepted.');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403&&DB::table('contacts')->whereIn('id',$ids)->count()===count($ids),'current original contact permissions prevent deletion of every selected message');}
    $currentRole->givePermissionTo('contact-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
    verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command)&&DB::table('contacts')->whereIn('id',$ids)->count()===0,'the original contact controller reconciles its protected deletion exactly once: '.$row->route_name);
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$id,$receipt);
}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
