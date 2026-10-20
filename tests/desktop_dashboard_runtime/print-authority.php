<?php
// Real fixed-scope contention after an old RR view and cached positive role authority.
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardPrintLedger,DesktopDashboardPrintEffects,DesktopDashboardJournal,TakeawayService};
foreach(['roles','permissions'] as $table)Schema::create($table,function(Blueprint $t){$t->id();$t->string('name');$t->string('guard_name');$t->timestamps();});
Schema::create('model_has_roles',function(Blueprint $t){$t->unsignedBigInteger('role_id');$t->string('model_type');$t->unsignedBigInteger('model_id');$t->primary(['role_id','model_id','model_type']);});
Schema::create('model_has_permissions',function(Blueprint $t){$t->unsignedBigInteger('permission_id');$t->string('model_type');$t->unsignedBigInteger('model_id');$t->primary(['permission_id','model_id','model_type']);});
Schema::create('role_has_permissions',function(Blueprint $t){$t->unsignedBigInteger('permission_id');$t->unsignedBigInteger('role_id');$t->primary(['permission_id','role_id']);});
DB::table('roles')->insert(['id'=>7,'name'=>'Print operator','guard_name'=>'admin']);DB::table('permissions')->insert(['id'=>9,'name'=>'order-list','guard_name'=>'admin']);
DB::table('users')->insert(['id'=>12,'name'=>'Current print authority','account_type'=>'admin','status'=>'accepted']);
$morph=(new \App\Models\User)->getMorphClass();$role=['role_id'=>7,'model_id'=>12,'model_type'=>$morph];$direct=['permission_id'=>9,'model_id'=>12,'model_type'=>$morph];$grant=['permission_id'=>9,'role_id'=>7];
$restore=function(string $mode)use($role,$direct,$grant){
    DB::table('users')->where('id',12)->update(['status'=>'accepted']);DB::table('model_has_roles')->delete();DB::table('model_has_permissions')->delete();DB::table('role_has_permissions')->delete();
    if($mode==='direct')DB::table('model_has_permissions')->insert($direct);else{DB::table('model_has_roles')->insert($role);DB::table('role_has_permissions')->insert($grant);}
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
};
$originalDevice=(string)config('desktop_dashboard.device_id');$authorityDevice=(string)Str::uuid();$restore('role');
DB::table('desktop_dashboard_devices')->insert(['id'=>$authorityDevice,'actor_id'=>12,'name'=>'current authority contention','enrollment_hash'=>str_repeat('7',64),'token_hash'=>str_repeat('8',64),'branches'=>'["f:100"]','enabled'=>true,'enrolled_at'=>now('UTC'),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
DB::table('desktop_dashboard_local_state')->insert(['device_id'=>$authorityDevice,'actor_id'=>12,'snapshot_id'=>(string)Str::uuid(),'schema_hash'=>str_repeat('a',64),'branches'=>'["f:100"]','coverage'=>'{"print_protocol":1}','state'=>'ready','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
config(['desktop_dashboard.device_id'=>$authorityDevice]);app(DesktopDashboardPrintLedger::class)->bind($authorityDevice);
$spawnAuthority=function(string $action,array $values)use($application,$authorityDevice){
    $env=getenv();$env['DB_DATABASE']=DB::connection()->getDatabaseName();$env['DESKTOP_DASHBOARD_DEVICE_ID']=$authorityDevice;
    $process=proc_open([PHP_BINARY,__DIR__.'/print-authority-worker.php',$application],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env,['bypass_shell'=>true]);
    if(!is_resource($process))throw new RuntimeException('Cannot start current-authority worker.');
    fwrite($pipes[0],json_encode(['actor_id'=>12,'action'=>$action,'values'=>$values],JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_timeout($pipes[1],15);
    $line=trim((string)fgets($pipes[1]));if(!str_starts_with($line,'WARM '))throw new RuntimeException('Authority worker did not warm its snapshot: '.$line.' '.stream_get_contents($pipes[2]));
    return [$process,$pipes,json_decode(substr($line,5),true,32,JSON_THROW_ON_ERROR)];
};
$collectAuthority=function(array $job):array{[$process,$pipes]=$job;$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)throw new RuntimeException('Current-authority worker failed: '.$out.' '.$error);return json_decode(trim($out),true,512,JSON_THROW_ON_ERROR);};
foreach(['disabled','direct','role-assignment','role-permission'] as $revocation)foreach(['prepare','burn','journal','saved'] as $action){
    $restore($revocation==='direct'?'direct':'role');$current=\App\Models\User::withoutGlobalScopes()->findOrFail(12);$values=$sale();$values['quote_hash']=app(TakeawayService::class)->quote($values,$current)['quote_hash'];
    $effect=null;$attempt=null;
    if($action!=='journal'){
        app(DesktopDashboardJournal::class)->execute($authorityDevice,$values['idempotency_key'],12,'takeaway.checkout',['values'=>$values],[],fn()=>app(TakeawayService::class)->checkout($values,$current,[],true));
        $effect=app(DesktopDashboardPrintLedger::class)->read($authorityDevice,$values['idempotency_key'],12,'f:100')[0];
        $attempt=['effect_id'=>$effect['effect_id'],'claim_token'=>(string)Str::uuid(),'native_session_id'=>(string)Str::uuid()];
        if($action==='burn'){$descriptor=app(DesktopDashboardPrintEffects::class)->prepare($authorityDevice,$attempt);$attempt+=array_intersect_key($descriptor,array_flip(['enrollment_id','origin_command_id','actor_id','branch','revision','snapshot_sha256','render_sha256']));$attempt['invocation_id']=(string)Str::uuid();}
    }
    if($action==='saved')$attempt=null;
    $before=[DB::table('takeaway_orders')->count(),DB::table('desktop_dashboard_commands')->count(),DB::table('takeaway_tills')->value('balance_cents')];
    DB::beginTransaction();DB::table('fasakhansta_dashboard_effects.print_ledger_scopes')->where('device_id',$authorityDevice)->lockForUpdate()->first();
    $job=$spawnAuthority($action,$attempt??$values);$warm=$job[2];$waiting=false;
    for($poll=0;$poll<30;$poll++){$query=$pdo->query('SELECT trx_state,trx_query FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id='.(int)$warm['connection'])->fetch(PDO::FETCH_ASSOC);if($query&&$query['trx_state']==='LOCK WAIT'&&str_contains($query['trx_query'],'print_ledger_scopes')){$waiting=true;break;}usleep(150000);}
    if(!($warm['isolation']==='REPEATABLE-READ'&&$warm['status']==='accepted'&&$warm['permission']===true&&$waiting)){DB::rollBack();$debug=$collectAuthority($job);throw new RuntimeException('Contention fixture did not reach its boundary: '.json_encode([$revocation,$action,$warm,$query,$debug]));}
    check(true,$revocation.' '.$action.' waits on the fixed scope after a positive cached permission and an old RR snapshot');
    if($revocation==='disabled')DB::table('users')->where('id',12)->update(['status'=>'disabled']);
    elseif($revocation==='direct')DB::table('model_has_permissions')->where($direct)->delete();
    elseif($revocation==='role-assignment')DB::table('model_has_roles')->where($role)->delete();
    else DB::table('role_has_permissions')->where($grant)->delete();
    DB::commit();$reply=$collectAuthority($job);check($reply['status']===403,$revocation.' committed during scope contention denies '.$action.' despite the old RR view');
    if($effect&&$action!=='saved'){$row=DB::table('fasakhansta_dashboard_effects.print_ledger_attempts')->where('effect_id',$effect['effect_id'])->first();$metadata=json_decode(DB::table('takeaway_till_entries')->where('id',$effect['local_references']['sale_till_entry.primary'])->value('metadata'),true);
        check(($action==='prepare'?$row===null:$row&&$row->state==='claimed'&&$row->permit_hash==='')&&$metadata['receipt_print']['consumed_at']===null,$revocation.' denial creates no new claim or burnt permit and preserves the original first-print grant');}
    else check($before===[DB::table('takeaway_orders')->count(),DB::table('desktop_dashboard_commands')->count(),DB::table('takeaway_tills')->value('balance_cents')],$revocation.' '.$action.' Journal denial returns no cached success and leaves sale, outbox and cash unchanged');
}
// A newly committed grant must use the same current facts rather than a stale cached denial.
foreach(['direct','role'] as $source)foreach(['prepare','burn'] as $action){
    $restore($source);$current=\App\Models\User::withoutGlobalScopes()->findOrFail(12);$values=$sale();$values['quote_hash']=app(TakeawayService::class)->quote($values,$current)['quote_hash'];
    app(DesktopDashboardJournal::class)->execute($authorityDevice,$values['idempotency_key'],12,'takeaway.checkout',['values'=>$values],[],fn()=>app(TakeawayService::class)->checkout($values,$current,[],true));
    $effect=app(DesktopDashboardPrintLedger::class)->read($authorityDevice,$values['idempotency_key'],12,'f:100')[0];
    $attempt=['effect_id'=>$effect['effect_id'],'claim_token'=>(string)Str::uuid(),'native_session_id'=>(string)Str::uuid()];
    if($action==='burn'){$descriptor=app(DesktopDashboardPrintEffects::class)->prepare($authorityDevice,$attempt);$attempt+=array_intersect_key($descriptor,array_flip(['enrollment_id','origin_command_id','actor_id','branch','revision','snapshot_sha256','render_sha256']));$attempt['invocation_id']=(string)Str::uuid();}
    if($source==='direct')DB::table('model_has_permissions')->where($direct)->delete();else DB::table('role_has_permissions')->where($grant)->delete();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    DB::beginTransaction();DB::table('fasakhansta_dashboard_effects.print_ledger_scopes')->where('device_id',$authorityDevice)->lockForUpdate()->first();
    $job=$spawnAuthority($action,$attempt);$warm=$job[2];$waiting=false;
    for($poll=0;$poll<30;$poll++){$query=$pdo->query('SELECT trx_state,trx_query FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id='.(int)$warm['connection'])->fetch(PDO::FETCH_ASSOC);if($query&&$query['trx_state']==='LOCK WAIT'&&str_contains($query['trx_query'],'print_ledger_scopes')){$waiting=true;break;}usleep(150000);}
    if(!($warm['isolation']==='REPEATABLE-READ'&&$warm['permission']===false&&$waiting)){DB::rollBack();$debug=$collectAuthority($job);throw new RuntimeException('New-grant fixture boundary: '.json_encode([$source,$action,$warm,$query,$debug]));}
    check(true,$source.' new-grant '.$action.' waits under an old RR snapshot and cached permission denial');
    if($source==='direct')DB::table('model_has_permissions')->insert($direct);else DB::table('role_has_permissions')->insert($grant);
    DB::commit();$reply=$collectAuthority($job);check($reply['status']===200,$source.' current grant committed during scope wait permits '.$action.' without trusting the stale cached denial');
    $row=DB::table('fasakhansta_dashboard_effects.print_ledger_attempts')->where('effect_id',$effect['effect_id'])->first();$metadata=json_decode(DB::table('takeaway_till_entries')->where('id',$effect['local_references']['sale_till_entry.primary'])->value('metadata'),true);
    check($row->state===($action==='burn'?'burnt':'claimed')&&($metadata['receipt_print']['consumed_at']!==null)===($action==='burn'),$source.' new-grant transition changes only the requested claim or one-shot burn');
}
$restore('role');config(['desktop_dashboard.device_id'=>$originalDevice]);
