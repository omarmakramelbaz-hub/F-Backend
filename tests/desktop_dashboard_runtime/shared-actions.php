<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardReferences,OperatingDay};

config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
$ownNote=(string)Str::uuid();$foreignNote=(string)Str::uuid();$laterNote=(string)Str::uuid();
$seedNotes=function()use($ownNote,$foreignNote,$laterNote){
    foreach([$ownNote=>1,$foreignNote=>20,$laterNote=>1] as $id=>$actor)DB::table('notifications')->insert([
        'id'=>$id,'type'=>'FixtureNotification','notifiable_type'=>User::class,'notifiable_id'=>$actor,'data'=>json_encode(['title'=>'إشعار اختبار مستقل','text'=>'نص الإشعار الأصلي']),
        'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
    ]);
};$seedNotes();$sharedCommands=[];
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$env);
try{
    for($n=0;$n<100;$n++){[$status]=$http('/_desktop/health');if($status===200)break;usleep(50000);}
    [$status,$page]=$http('/admin/notifications');preg_match('/name="csrf-token" content="([^"]+)"/',$page,$sharedCsrf);
    verify($status===200&&isset($sharedCsrf[1]),'the original notification page retains its account history and CSRF');
    $read=['_token'=>$sharedCsrf[1],'idempotency_key'=>(string)Str::uuid(),'ids'=>[$ownNote,$foreignNote,(string)Str::uuid()]];
    [$status,$body]=$http('/admin/dashboard-inbox/notifications/read',$read,['Accept: application/json']);$firstRead=json_decode($body,true);
    verify($status===200&&$firstRead['marked']===1&&DB::table('notifications')->where('id',$foreignNote)->value('read_at')===null
        &&DB::table('notifications')->where('id',$laterNote)->value('read_at')===null,'original notification reads mark only selected own IDs, leaving other accounts and later notes unread');
    [$status,$retry]=$http('/admin/dashboard-inbox/notifications/read',$read,['Accept: application/json']);
    verify($status===200&&json_decode($retry,true)===$firstRead,'a lost original notification reply retains its exact count');$sharedCommands[]=$read['idempotency_key'];
    $invalid=$read;$invalid['idempotency_key']=(string)Str::uuid();$invalid['ids']='invalid';$before=DB::table('desktop_dashboard_commands')->count();
    verify($http('/admin/dashboard-inbox/notifications/read',$invalid,['Accept: application/json'])[0]===422&&DB::table('desktop_dashboard_commands')->count()===$before,'invalid original notification IDs cannot commit a local journal entry');
    $category=['_token'=>$sharedCsrf[1],'idempotency_key'=>(string)Str::uuid(),'name'=>'تصنيف محلي مستقل'];
    [$status,$body]=$http('/admin/branch-expenses/categories',$category,['Accept: application/json']);$createdCategory=json_decode($body,true);
    verify($status===200&&preg_match('/^custom_([1-9][0-9]*)$/D',$createdCategory['category']['key']??'',$categoryMatch),'the original shared expense category creates without a fictitious branch');$sharedCommands[]=$category['idempotency_key'];
    $localCategory=(int)$categoryMatch[1];
    $update=['_token'=>$sharedCsrf[1],'idempotency_key'=>(string)Str::uuid(),'action'=>'update','key'=>'custom_'.$localCategory,'name'=>'تصنيف محلي معدّل','expected_revision'=>0];
    verify($http('/admin/branch-expenses/categories',$update,['Accept: application/json'])[0]===200,'the original category update retains a typed dependency on its creation');$sharedCommands[]=$update['idempotency_key'];
    $expense=['_token'=>$sharedCsrf[1],'idempotency_key'=>(string)Str::uuid(),'branch'=>'f:100','occurred_on'=>OperatingDay::date(),'category'=>'custom_'.$localCategory,
        'description'=>'مصروف مرتبط بتصنيف محلي','amount'=>'2.00','payment_method'=>'cash','approve'=>'0'];
    verify($http('/admin/branch-expenses/save',$expense,['Accept: application/json'])[0]===200,'the original expense form retains its custom classification dependency');$sharedCommands[]=$expense['idempotency_key'];
    $delete=['_token'=>$sharedCsrf[1],'idempotency_key'=>(string)Str::uuid(),'action'=>'delete','key'=>'custom_'.$localCategory,'expected_revision'=>1];
    [$status,$deletedBody]=$http('/admin/branch-expenses/categories',$delete,['Accept: application/json']);
    verify($status===200&&!json_decode($deletedBody,true)['category']['active'],'the original category deletion keeps the historical expense classification');$sharedCommands[]=$delete['idempotency_key'];
    verify($http('/admin/branch-expenses/categories',$delete,['Accept: application/json'])[1]===$deletedBody,'a lost original category deletion reply preserves the exact original state');
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();$seedNotes();
DB::table('branch_expense_categories')->insert(['id'=>$localCategory,'name'=>'تصنيف مستقل على السيرفر','name_hash'=>hash('sha256','server collision'),
    'created_by'=>1,'request_key'=>(string)Str::uuid(),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
foreach($sharedCommands as $id){
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
    $row=DB::table('desktop_dashboard_commands')->where('command_id',$id)->first();$saved=json_decode(Crypt::decryptString($row->local_result_cipher),true);
    $command=['command_id'=>$id,'actor_id'=>1,'route_name'=>$row->route_name,'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true),
        'local_result'=>$saved['result'],'local_references'=>$saved['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String()];
    config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
    $payload=app(DesktopDashboardReferences::class)->resolve($remoteDevice->id,$command['payload']);
    if($id===$category['idempotency_key']){
        $previousOwner=DB::table('users')->where('id',1)->value('owner_resturant_id');
        try{
            DB::table('users')->where('id',1)->update(['owner_resturant_id'=>100]);
            try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Revoked primary-owner category authority was accepted.');}
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403&&!DB::table('branch_expense_categories')->where('name',$category['name'])->exists(),'shared category reconciliation requires the current original primary-owner authority');}
        }finally{DB::table('users')->where('id',1)->update(['owner_resturant_id'=>$previousOwner]);}
    }
    if($id===$update['idempotency_key']){
        $mapped=(int)substr($payload['values']['key'],7);verify($mapped!==$localCategory,'the shared category key maps around an unrelated server integer collision');
        DB::table('branch_expense_category_settings')->updateOrInsert(['category_key'=>'custom_'.$mapped],['name'=>'تصنيف تغير على السيرفر','active'=>1,'revision'=>1,'updated_at'=>now('UTC')]);
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Changed expense category was overwritten.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&DB::table('branch_expense_category_settings')->where('category_key','custom_'.$mapped)->value('name')==='تصنيف تغير على السيرفر','a changed server category rejects its pending offline edit while retaining both states');}
        DB::table('branch_expense_category_settings')->where('category_key','custom_'.$mapped)->delete();
    }
    $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
    verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command),'the original actor/shared action reconciles exactly once: '.$row->route_name);
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$id,$receipt);
}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
verify(DB::table('branch_expense_categories')->where('id',$localCategory)->value('name')==='تصنيف مستقل على السيرفر'
    &&DB::table('branch_expenses')->where('description',$expense['description'])->value('category')==='custom_'.$mapped,'mapped category reconciliation preserves the independent classification and assigns the expense to the actual created category');
verify(!DB::table('branch_expense_category_settings')->where('category_key','custom_'.$mapped)->value('active'),'mapped original category deletion retains the historical category while removing its active choice');
verify(DB::table('notifications')->where('id',$ownNote)->value('read_at')!==null&&DB::table('notifications')->where('id',$foreignNote)->value('read_at')===null
    &&DB::table('notifications')->where('id',$laterNote)->value('read_at')===null,'reconciled notification reads retain account scope and unseen IDs');
$branchNote=(string)Str::uuid();DB::table('notifications')->insert(['id'=>$branchNote,'type'=>'FixtureNotification','notifiable_type'=>User::class,'notifiable_id'=>10,
    'data'=>json_encode(['title'=>'إشعار حساب الفرع','text'=>'إشعار خاص']),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$branchCommand=(string)Str::uuid();$branchDevice=app(\App\Services\Dashboard\DesktopDashboardDevices::class)->device($link['token']);
$previousPrincipal=auth('admin')->getUser();
app(DesktopDashboardReconciliation::class)->ingest($branchDevice,['command_id'=>$branchCommand,'actor_id'=>10,'route_name'=>'dashboard-inbox.notifications.read',
    'payload'=>['values'=>['idempotency_key'=>$branchCommand,'ids'=>[$branchNote,$laterNote]],'parameters'=>[],'files'=>[]],
    'local_result'=>['success'=>true,'marked'=>1,'count'=>0],'local_references'=>[],'dependencies'=>[],'occurred_at'=>now('UTC')->toIso8601String()]);
verify(DB::table('notifications')->where('id',$branchNote)->value('read_at')!==null&&DB::table('notifications')->where('id',$laterNote)->value('read_at')===null
    &&auth('admin')->getUser()===$previousPrincipal,'a branch account reconciles only its own notification IDs and the original replay restores the outer principal');
