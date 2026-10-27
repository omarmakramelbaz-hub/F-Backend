<?php
// Continues ONLY the original disposable MariaDB print harness, after its complete v1 regressions.
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardWorkspaceAuthority as WorkspaceAuthority,
    DesktopDashboardWorkspaceLocalAuthority as WorkspaceLocal,DesktopDashboardWorkspaceLocalSchema as WorkspaceSchema,
    DesktopDashboardWorkspaceExecutionContext as WorkspaceContext,DesktopDashboardPrintEffects as WorkspaceEffects,
    DesktopDashboardPrintLedger as WorkspaceLedger,DesktopDashboardJournal as WorkspaceJournal,TakeawayService as WorkspaceSales};
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;

if(!isset($application,$pdo)||DB::connection()->getConfig('host')!=='127.0.0.1'
    ||!preg_match('/^fasakhansta_dashboard_stage_[a-f0-9]{16}$/D',DB::connection()->getDatabaseName()))
    throw new RuntimeException('Shared print fixture requires the existing disposable harness.');
Schema::table('users',function(Blueprint $t){
    foreach(['email','mobile','app_scope','created_at','updated_at']as$field)if(!Schema::hasColumn('users',$field)){
        if(in_array($field,['created_at','updated_at'],true))$t->timestamp($field)->nullable();else$t->string($field)->nullable();
    }
    if(!Schema::hasColumn('users','pending_vendor_id'))$t->unsignedBigInteger('pending_vendor_id')->nullable();
});
foreach([1,10,11]as$id)DB::table('users')->where('id',$id)->update(['status'=>'accepted','email'=>'shared'.$id.'@example.test',
    'mobile'=>(string)(1000000000+$id),'app_scope'=>'fasakhansta','password'=>password_hash('public-fixture-'.$id,PASSWORD_BCRYPT,['cost'=>4]),
    'created_at'=>'2026-10-27 09:00:00','updated_at'=>'2026-10-27 09:00:00']);
foreach(['2026_10_27_030000_create_desktop_dashboard_workspaces.php'=>'CreateDesktopDashboardWorkspaces',
    '2026_10_27_030010_create_workspace_command_authorities.php'=>'CreateWorkspaceCommandAuthorities']as$file=>$class){
    require_once $application.'/database/migrations/'.$file;(new $class)->up();
}
$workspace=(string)Str::uuid();
config(['desktop_dashboard.enabled'=>true,'desktop_dashboard.workspace_enabled'=>true,'desktop_dashboard.local'=>false]);
$group=app(WorkspaceAuthority::class)->enroll(['device_id'=>$workspace,'name'=>'Shared fixture','nonce'=>str_repeat('6',64)],User::withoutGlobalScopes()->findOrFail(10));
$physical=app(WorkspaceAuthority::class)->authenticate($group['token']);$manifest=$group['manifest'];
putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL=true');config(['desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>$workspace]);
auth()->forgetGuards();WorkspaceSchema::ensure();
$snapshot=['workspace_id'=>$workspace,'custodian_actor_id'=>10,'snapshot_id'=>(string)Str::uuid(),'schema_hash'=>str_repeat('7',64),
    'source'=>['format'=>1,'files'=>10,'sha256'=>str_repeat('8',64),'framework'=>app()->version()],
    'branches'=>['f:100','f:101'],'coverage'=>['workspace_protocol'=>2,'print_protocol'=>1]];
$record=['format'=>2,'kind'=>'desktop-dashboard-workspace-local-authority','workspace_id'=>$workspace,
    'root_fingerprint'=>$group['root_fingerprint'],'root'=>['id'=>$workspace,'custodian_actor_id'=>10,'name'=>'Shared workspace',
        'enabled'=>true,'enrollment_hash'=>$physical->enrollment_hash,'token_hash'=>$physical->token_hash,'enrolled_at'=>$physical->enrolled_at],
    'manifest_revision'=>$manifest['revision'],'roster_sha256'=>$manifest['roster_sha256'],
    'manifest_sha256'=>hash('sha256',WorkspaceAuthority::canonical($manifest)),'manifest_signature'=>$manifest['signature'],
    'member_count'=>count($manifest['members']),'snapshot'=>$snapshot,'database'=>DB::connection()->getDatabaseName(),'expected_binding_digest'=>null];
$localMac=static fn(string$domain,array$value)=>hash_hmac('sha256',"desktop-workspace-local-v2\0".$domain."\0".WorkspaceAuthority::canonical($value),app('encrypter')->getKey());
$sealFixture=function()use(&$record,$manifest,$workspace,$localMac){
    DB::table('desktop_dashboard_workspace_local_authority')->updateOrInsert(['workspace_id'=>$workspace],[
        'record_json'=>WorkspaceAuthority::canonical($record),'manifest_json'=>WorkspaceAuthority::canonical($manifest),
        'local_mac'=>$localMac('record',$record),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    foreach($manifest['members']as$member)DB::table('desktop_dashboard_workspace_local_members')->updateOrInsert(
        ['workspace_id'=>$workspace,'actor_id'=>$member['actor_id']],['manifest_revision'=>$manifest['revision'],
            'member_json'=>WorkspaceAuthority::canonical($member),'local_mac'=>$localMac('member',[
                'record_sha256'=>hash('sha256',WorkspaceAuthority::canonical($record)),'member'=>$member]),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
};
DB::table('desktop_dashboard_local_state')->insert(['device_id'=>$workspace,'actor_id'=>10,'snapshot_id'=>$snapshot['snapshot_id'],
    'schema_hash'=>$snapshot['schema_hash'],'branches'=>WorkspaceAuthority::canonical($snapshot['branches']),
    'coverage'=>WorkspaceAuthority::canonical($snapshot['coverage']),'state'=>'ready','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$sealFixture();$groupLedger=app(WorkspaceLedger::class);$groupEffects=app(WorkspaceEffects::class);$groupJournal=app(WorkspaceJournal::class);
$printIdentity=$groupLedger->bind($workspace);check($printIdentity===$group['root_fingerprint'],'shared print enrollment uses physical root identity independent of the active account');
$local=app(WorkspaceLocal::class);$actorA=$local->scopeForActor(10);$actorB=$local->scopeForActor(1);
$groupSale=function($scope)use($workspace,$groupJournal){
    return WorkspaceContext::run($scope,function()use($scope,$workspace,$groupJournal){
        $actor=$scope->actor();$values=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'customer_phone'=>'01012345678','service_consent'=>true,
            'items'=>[['product_id'=>1,'option_id'=>'','quantity_mode'=>'weight','quantity'=>'0.250']],'discount'=>'0.00','payment_method'=>'cash','cash_received'=>'100.00'];
        $values['quote_hash']=app(WorkspaceSales::class)->quote($values,$actor)['quote_hash'];
        $result=$groupJournal->execute($workspace,$values['idempotency_key'],$scope->actor_id,'takeaway.checkout',['values'=>$values],[],
            fn()=>app(WorkspaceSales::class)->checkout($values,$actor,[],true));return [$values,$result];
    });
};
[$aValues,$aResult]=$groupSale($actorA);
$aIntent=WorkspaceContext::run($actorA,fn()=>$groupLedger->read($workspace,$aValues['idempotency_key'],10,'f:100'))[0];
check($aIntent['actor_id']===10&&$aIntent['enrollment_id']===$printIdentity,'original branch actor A owns its real committed sale and immutable shared print effect');
$aClaim=['effect_id'=>$aIntent['effect_id'],'claim_token'=>(string)Str::uuid(),'native_session_id'=>(string)Str::uuid()];
$aPrepared=WorkspaceContext::run($actorA,fn()=>$groupEffects->prepare($workspace,$aClaim));
$aAttempt=$aClaim+array_intersect_key($aPrepared,array_flip(['enrollment_id','origin_command_id','actor_id','branch','revision','snapshot_sha256','render_sha256']))+['invocation_id'=>(string)Str::uuid()];
$aBurn=WorkspaceContext::run($actorA,fn()=>$groupEffects->burn($workspace,$aAttempt));
$unknownBefore=(array)DB::table('fasakhansta_dashboard_effects.print_ledger_attempts')->where('effect_id',$aIntent['effect_id'])->first();
WorkspaceContext::run($actorB,function()use($workspace,$groupEffects,$aClaim,$aAttempt,$aIntent){
    $list=$groupEffects->listing($workspace,['include_manual'=>true,'native_session_id'=>(string)Str::uuid()]);
    check(!in_array($aIntent['effect_id'],array_column($list['effects'],'effect_id'),true),'owner B cannot inherit branch A original native print effect');
    check($list['attention']>0,'unknown print attention remains global across account switches');
    denied(fn()=>$groupEffects->prepare($workspace,$aClaim),403,'even owner B cannot acquire or replace actor A claim');
    denied(fn()=>$groupEffects->review($workspace,array_diff_key($aAttempt,array_flip(['snapshot_sha256','render_sha256']))+['reason'=>'foreign-review']),403,'owner B cannot release actor A unknown burn');
});
check($unknownBefore===(array)DB::table('fasakhansta_dashboard_effects.print_ledger_attempts')->where('effect_id',$aIntent['effect_id'])->first(),
    'switching accounts preserves every byte of the original unknown attempt');
[$bValues,$bResult]=$groupSale($actorB);
$bIntent=WorkspaceContext::run($actorB,fn()=>$groupLedger->read($workspace,$bValues['idempotency_key'],1,'f:100'))[0];
check($bIntent['actor_id']===1&&$bIntent['effect_id']!==$aIntent['effect_id']&&$bIntent['enrollment_id']===$aIntent['enrollment_id'],
    'owner B creates its own real sale in the same database and physical print namespace');
check(DB::table('takeaway_orders')->whereIn('id',[$aResult['receipt']['id'],$bResult['receipt']['id']])->count()===2,
    'both accounts observe the same committed local business state');
$bClaim=['effect_id'=>$bIntent['effect_id'],'claim_token'=>(string)Str::uuid(),'native_session_id'=>(string)Str::uuid()];
$bPrepared=WorkspaceContext::run($actorB,fn()=>$groupEffects->prepare($workspace,$bClaim));
$bAttempt=$bClaim+array_intersect_key($bPrepared,array_flip(['enrollment_id','origin_command_id','actor_id','branch','revision','snapshot_sha256','render_sha256']))+['invocation_id'=>(string)Str::uuid()];
$bBurn=WorkspaceContext::run($actorB,fn()=>$groupEffects->burn($workspace,$bAttempt));
$bOutcome=array_diff_key($bAttempt,array_flip(['snapshot_sha256','render_sha256']))+['permit_hash'=>hash('sha256',$bBurn['permit']),'state'=>'invoked','reason'=>'accepted'];
$bReceipt=WorkspaceContext::run($actorB,fn()=>$groupEffects->outcome($workspace,$bOutcome));
DB::table('users')->where('id',10)->update(['status'=>'disabled']);
check($groupLedger->bind($workspace)===$printIdentity,'disabled enrollment custodian does not disable the physical print ledger or impersonate another member');
WorkspaceContext::run($actorB,fn()=>$groupEffects->listing($workspace,['include_manual'=>true]));
$global=WorkspaceContext::rootControl($workspace,fn()=>$groupEffects->pendingOutcomes($workspace));
check(in_array($bIntent['effect_id'],array_column($global['outcomes'],'effect_id'),true),'native root outcome queue retains actor B outcome independently of current login');
$auditBefore=WorkspaceContext::rootControl($workspace,fn()=>$groupEffects->audit($workspace));
$aAudit=collect($auditBefore['audit'])->firstWhere('effect_id',$aIntent['effect_id']);
check($aAudit['state']==='burnt'&&$aAudit['outcome']===null,'retired actor A unknown burn remains held and visible to private root audit');
$ack=array_intersect_key($bReceipt,array_flip(['effect_id','invocation_id','state','receipt_sha256']));
WorkspaceContext::rootControl($workspace,fn()=>$groupEffects->acknowledge($workspace,$ack));
check(!in_array($bIntent['effect_id'],array_column(WorkspaceContext::rootControl($workspace,fn()=>$groupEffects->pendingOutcomes($workspace))['outcomes'],'effect_id'),true),
    'root metadata acknowledgement clears only the exact original durable outcome');
$previousControlToken=getenv('DESKTOP_DASHBOARD_CONTROL_TOKEN');$previousControlHeader=$_SERVER['HTTP_X_FASAKHANSTA_CONTROL']??null;
putenv('DESKTOP_DASHBOARD_CONTROL_TOKEN='.str_repeat('9',64));$_SERVER['HTTP_X_FASAKHANSTA_CONTROL']=str_repeat('9',64);
$nativeControl=app(\App\Services\Dashboard\DesktopDashboardWorkspaceControl::class);
$rootPending=$nativeControl->dispatch(['action'=>'pending'],$group['token']);
check($rootPending['print_state']===['prepared'=>0,'uncertain'=>1,'unacknowledged'=>0],
    'shared native control dispatcher returns complete root print facts without choosing a user');
auth('admin')->setUser(User::withoutGlobalScopes()->findOrFail(1));
$nativeCapability=app(\App\Services\Dashboard\DesktopDashboardWorkspaceNativeSessions::class)->issue($local->currentScope(auth('admin')->user()));
auth()->forgetGuards();
$controlled=$nativeControl->dispatch(['action'=>'print-list','include_manual'=>true,'actor_id'=>1,'native_session'=>$nativeCapability],$group['token']);
check(!in_array($aIntent['effect_id'],array_column($controlled['effects'],'effect_id'),true)&&auth('admin')->getUser()===null,
    'shared native actor dispatch uses the actual password-session capability and restores the empty guard');
denied(fn()=>$nativeControl->dispatch(['action'=>'print-list','actor_id'=>10,'native_session'=>$nativeCapability],$group['token']),403,
    'command dispatch cannot substitute the enrollment custodian for the authenticated user');
// The recent audit page is presentation only: an older A burn must remain counted
// even when more than one page of legitimate B reprint claims was prepared later.
for($groupPrintIndex=0;$groupPrintIndex<101;$groupPrintIndex++){
    $reprint=WorkspaceContext::run($actorB,fn()=>$groupEffects->resolve($workspace,[
        'path'=>'/admin/takeaway/receipts/'.$bResult['receipt']['id'].'/print','query'=>[],
        'request_id'=>(string)Str::uuid(),'explicit_reprint'=>true]));
    WorkspaceContext::run($actorB,fn()=>$groupEffects->prepare($workspace,[
        'effect_id'=>$reprint['effect_id'],'claim_token'=>(string)Str::uuid(),'native_session_id'=>(string)Str::uuid()]));
}
$printing=WorkspaceContext::rootControl($workspace,fn()=>$groupEffects->workspaceState($workspace));
check($printing===['prepared'=>101,'uncertain'=>1,'unacknowledged'=>0],
    'physical print summary counts every actor and reprint beyond the bounded recent audit');
$auditBefore=WorkspaceContext::rootControl($workspace,fn()=>$groupEffects->audit($workspace));
check(count($auditBefore['audit'])===100&&!in_array($aIntent['effect_id'],array_column($auditBefore['audit'],'effect_id'),true),
    'hidden older A uncertainty remains counted when it is outside the latest audit page');
foreach([$aValues['idempotency_key'],$bValues['idempotency_key']]as$acceptedCommand)
    $groupJournal->acknowledge($workspace,$acceptedCommand,['device_id'=>$workspace,'command_id'=>$acceptedCommand,'committed'=>true]);
WorkspaceContext::rootControl($workspace,function()use($workspace){
    denied(fn()=>app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->begin($workspace,(string)Str::uuid(),bin2hex(random_bytes(32))),409,
        'generation fence atomically refuses old foreign uncertainty and all prepared print claims');
});
check(DB::table('desktop_dashboard_local_state')->where('device_id',$workspace)->value('state')==='ready',
    'rejected print fence never changes the active generation state');
// A retained permanent effect must survive physical generation replacement and source removal.
$sourceDatabase=DB::connection()->getDatabaseName();$replacement='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));
$pdo->exec('CREATE DATABASE `'.$replacement.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$replacement.'`'));
foreach($pdo->query('SHOW TABLES FROM `'.$sourceDatabase.'`')->fetchAll(PDO::FETCH_COLUMN)as$table){
    $pdo->exec('CREATE TABLE `'.$replacement.'`.`'.$table.'` LIKE `'.$sourceDatabase.'`.`'.$table.'`');
    $pdo->exec('INSERT INTO `'.$replacement.'`.`'.$table.'` SELECT * FROM `'.$sourceDatabase.'`.`'.$table.'`');
}
config(['database.connections.mysql.database'=>$replacement]);DB::purge();$record['database']=$replacement;$sealFixture();
$pdo->exec('DROP DATABASE `'.$sourceDatabase.'`');
$auditAfter=WorkspaceContext::rootControl($workspace,fn()=>$groupEffects->audit($workspace));
check($auditBefore===$auditAfter,'all-actor immutable print audit and unknown burns survive generation replacement and original database removal');
check($groupLedger->bind($workspace)===$printIdentity,'a replacement generation keeps the original shared print namespace');
putenv($previousControlToken===false?'DESKTOP_DASHBOARD_CONTROL_TOKEN':'DESKTOP_DASHBOARD_CONTROL_TOKEN='.$previousControlToken);
if($previousControlHeader===null)unset($_SERVER['HTTP_X_FASAKHANSTA_CONTROL']);else $_SERVER['HTTP_X_FASAKHANSTA_CONTROL']=$previousControlHeader;
putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL');
