<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation};

$historyIds=[(string)Str::uuid(),(string)Str::uuid(),(string)Str::uuid()];$historyForeign=(string)Str::uuid();$historyLate=(string)Str::uuid();
$historySeed=function($id,$actor=1){DB::table('notifications')->insert(['id'=>$id,'type'=>'FixtureNotification','notifiable_type'=>\App\Models\User::class,
    'notifiable_id'=>$actor,'data'=>json_encode(['title'=>'إشعار سجل مستقل','text'=>'نص الإشعار الأصلي']),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);};
config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
foreach($historyIds as $note)$historySeed($note);$historySeed($historyForeign,20);
$historyEnv=$env;$historyEnv['DB_DATABASE']=$ownerStage;$historyEnv['DESKTOP_DASHBOARD_LOCAL']='true';
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$historyEnv);
try{
    for($n=0;$n<100;$n++){[$status]=$http('/_desktop/health');if($status===200)break;usleep(50000);}
    [$status,$page]=$http('/admin/notifications');preg_match('/name="csrf-token" content="([^"]+)"/',$page,$historyCsrf);
    verify($status===200&&str_contains($page,'data-desktop-notification-read')&&str_contains($page,'data-notification-ids')
        &&str_contains($page,'data-notification-generation="'.$ownerSnapshot['snapshot_id'].'"'),'the original notification history preserves its forms and binds immutable read snapshots to the imported dataset');
    $historySingle=['_token'=>$historyCsrf[1],'_method'=>'PUT','_desktop_command'=>(string)Str::uuid(),'desktop_notification_ids'=>json_encode([$historyIds[0]])];
    $path='/admin/read/'.$historyIds[0];$before=DB::table('desktop_dashboard_commands')->count();
    [$status,,$headers]=$http($path,$historySingle);
    verify($status===302&&in_array('Location: '.$origin.'/admin/notifications',$headers,true)&&DB::table('notifications')->where('id',$historyIds[0])->value('read_at')!==null
        &&DB::table('desktop_dashboard_commands')->count()===$before+1,'the original single-read redirect and notification update commit with one encrypted command');
    $stamp=DB::table('notifications')->where('id',$historyIds[0])->value('read_at');
    verify($http($path,$historySingle)[0]===302&&DB::table('desktop_dashboard_commands')->count()===$before+1
        &&DB::table('notifications')->where('id',$historyIds[0])->value('read_at')===$stamp,'a lost original history response reuses its stored redirect and first read timestamp');
    $historyBulk=['_token'=>$historyCsrf[1],'_desktop_command'=>(string)Str::uuid(),'desktop_notification_ids'=>json_encode(array_reverse(array_slice($historyIds,1)))];
    verify($http('/admin/read/all/notification',$historyBulk)[0]===302&&DB::table('notifications')->whereIn('id',$historyIds)->whereNull('read_at')->count()===0,
        'the original mark-all form journals only the visible account-scoped snapshot');
    $historySeed($historyLate);
    $historyBulk['desktop_notification_ids']=json_encode(array_slice($historyIds,1));
    verify($http('/admin/read/all/notification',$historyBulk)[0]===302&&DB::table('notifications')->where('id',$historyLate)->value('read_at')===null,
        'a reordered lost mark-all response keeps notifications arriving afterwards unread');
    $bad=$historyBulk;$bad['_desktop_command']=(string)Str::uuid();$bad['desktop_notification_ids']=json_encode([$historyForeign]);$before=DB::table('desktop_dashboard_commands')->count();
    verify($http('/admin/read/all/notification',$bad,['Accept: application/json'])[0]===404&&DB::table('desktop_dashboard_commands')->count()===$before
        &&DB::table('notifications')->where('id',$historyForeign)->value('read_at')===null,'a foreign history snapshot writes neither another account notification nor a success command');
    $bad['desktop_notification_ids']='{"invalid":"shape"}';
    verify($http('/admin/read/all/notification',$bad,['Accept: application/json'])[0]===422&&DB::table('desktop_dashboard_commands')->count()===$before,
        'an incomplete history snapshot is rejected before a journal can report success');
    $bad=$historySingle;$bad['_desktop_command']=(string)Str::uuid();$bad['desktop_notification_ids']=json_encode([$historyLate]);
    verify($http($path,$bad,['Accept: application/json'])[0]===422,'a history form cannot mismatch its route notification and requested snapshot');
    DB::statement("CREATE TRIGGER reject_history_command BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='simulated history journal failure'");
    try{
        $rollback=$historyBulk;$rollback['_desktop_command']=(string)Str::uuid();$rollback['desktop_notification_ids']=json_encode([$historyLate]);
        verify($http('/admin/read/all/notification',$rollback,['Accept: application/json'])[0]===500&&DB::table('notifications')->where('id',$historyLate)->value('read_at')===null,
            'failure to persist the encrypted history command rolls back the original notification read');
    }finally{DB::statement('DROP TRIGGER reject_history_command');}
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
foreach($historyIds as $note)$historySeed($note);$historySeed($historyForeign,20);$historySeed($historyLate);
foreach([$historySingle['_desktop_command'],$historyBulk['_desktop_command']] as $operation){
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
    $row=DB::table('desktop_dashboard_commands')->where('command_id',$operation)->first();$result=json_decode(Crypt::decryptString($row->local_result_cipher),true);
    $command=['command_id'=>$operation,'actor_id'=>1,'route_name'=>$row->route_name,'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true),
        'local_result'=>$result['result'],'local_references'=>[],'dependencies'=>[],'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String()];
    config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();$principal=auth('admin')->getUser();
    $reply=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
    verify($reply===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command)&&auth('admin')->getUser()===$principal,
        'the original history controller reconciles once and restores the outer authenticated principal: '.$row->route_name);
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$operation,$reply);
}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
verify(DB::table('notifications')->whereIn('id',$historyIds)->whereNull('read_at')->count()===0
    &&DB::table('notifications')->whereIn('id',[$historyForeign,$historyLate])->whereNotNull('read_at')->count()===0,
    'history reconciliation changes only the selected own snapshot, retaining foreign and newly arrived unread notifications');
$branchHistory=(string)Str::uuid();$historySeed($branchHistory,10);$branchHistoryCommand=(string)Str::uuid();
$branchHistoryDevice=app(\App\Services\Dashboard\DesktopDashboardDevices::class)->device($link['token']);
$branchEnvelope=['command_id'=>$branchHistoryCommand,'actor_id'=>10,'route_name'=>'read_notify',
    'payload'=>['values'=>['idempotency_key'=>$branchHistoryCommand,'desktop_notification_ids'=>json_encode([$branchHistory])],
        'parameters'=>['id'=>$branchHistory],'files'=>[]],'local_result'=>['http'=>['status'=>302,'content'=>'','location'=>'/admin/notifications'],'references'=>[]],
    'local_references'=>[],'dependencies'=>[],'occurred_at'=>now('UTC')->toIso8601String()];
$principal=auth('admin')->getUser();app(DesktopDashboardReconciliation::class)->ingest($branchHistoryDevice,$branchEnvelope);
verify(DB::table('notifications')->where('id',$branchHistory)->value('read_at')!==null&&auth('admin')->getUser()===$principal,
    'a branch account replays its own original history form without acquiring global administration authority');
$branchEnvelope['command_id']=(string)Str::uuid();$branchEnvelope['payload']['values']['idempotency_key']=$branchEnvelope['command_id'];
$branchEnvelope['payload']['values']['desktop_notification_ids']=json_encode([$historyLate]);$branchEnvelope['payload']['parameters']['id']=$historyLate;
try{app(DesktopDashboardReconciliation::class)->ingest($branchHistoryDevice,$branchEnvelope);throw new RuntimeException('Foreign branch history was accepted.');}
catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===404
    &&DB::table('notifications')->where('id',$historyLate)->value('read_at')===null,'a branch history replay cannot mark an owner notification or retain a successful receipt');}
