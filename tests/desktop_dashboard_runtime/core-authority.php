<?php
// Original core controller receipts under independent MariaDB permission and BASE revocations.
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardWalletCash,WalletCashOperations};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\{Role,Permission};
use Spatie\Permission\PermissionRegistrar;

$application=realpath($argv[1]);$port=(int)$argv[2];
if(!$application||!is_file($application.'/desktop/bootstrap.php')||$port<1024)throw new RuntimeException('Use core-authority.php prepared-application disposable-mariadb-port');
$profile=sys_get_temp_dir().'/desktop-core-authority-'.bin2hex(random_bytes(8));
foreach(['app/public','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache','private'] as $dir)mkdir($profile.'/'.$dir,0700,true);
$database='desktop_core_authority_'.bin2hex(random_bytes(8));
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
foreach(['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_STORAGE'=>$profile,'APP_ENV'=>'desktop','APP_DEBUG'=>'false','APP_URL'=>'http://127.0.0.1:43144',
    'APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,'DB_DATABASE'=>$database,'DB_USERNAME'=>'root','DB_PASSWORD'=>'',
    'CACHE_DRIVER'=>'file','SESSION_DRIVER'=>'file','APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php','APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php',
    'APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $name=>$value){putenv($name.'='.$value);$_ENV[$name]=$value;$_SERVER[$name]=$value;}
$app=require $application.'/desktop/bootstrap.php';
set_exception_handler(function(Throwable $error){while(DB::transactionLevel()>0)DB::rollBack();fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);});
$count=0;function verify($condition,$message){global $count;if(!$condition)throw new RuntimeException($message);$count++;echo 'PASS '.$message.PHP_EOL;}
function rejectWallet(callable $work,int $status,string $message){try{$work();}catch(\Illuminate\Validation\ValidationException $e){verify($status===422,$message);return;}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){verify($e->getStatusCode()===$status,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
require __DIR__.'/full-schema.php';$report=json_decode(file_get_contents(__DIR__.'/fixtures/deployed-schema-20261008.json'),true,512,JSON_THROW_ON_ERROR);DesktopFullSchemaFixture::create($report['tables']);
require $application.'/database/migrations/2026_10_08_130000_create_desktop_dashboard_journal.php';(new CreateDesktopDashboardJournal)->up();
DB::statement('SET FOREIGN_KEY_CHECKS=0');
foreach([
    [1,'admin','accepted','0.00'],[10,'vendor','accepted','100.00'],[20,'user','disabled','99.00'],[30,'admin','accepted','0.00'],
] as [$id,$type,$status,$balance])DB::table('users')->insert(['id'=>$id,'added_by'=>1,'name'=>'Fixture '.$id,'email'=>'wallet'.$id.'@test.invalid','mobile'=>'010000000'.str_pad((string)$id,2,'0',STR_PAD_LEFT),
    'password'=>password_hash('Fixture123',PASSWORD_BCRYPT),'account_type'=>$type,'status'=>$status,'app_scope'=>'fasakhansta','balance'=>$balance,'min_wallet'=>'200.00','created_at'=>now(),'updated_at'=>now()]);
DB::table('resturants')->insert(['id'=>100,'added_by'=>1,'user_id'=>10,'name'=>'الفرع الأصلي','status'=>'opened','address'=>'المنصورة']);
DB::table('resturants')->insert(['id'=>101,'added_by'=>1,'user_id'=>10,'name'=>'فرع آخر','status'=>'opened','address'=>'المنصورة']);
foreach((new ReflectionClass(\App\Models\GeneralSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property){
    $value=$property->getType()?->getName()==='bool'?true:'0';DB::table('settings')->insert(['group'=>'general','name'=>$property->getName(),'locked'=>false,'payload'=>json_encode($value)]);
}
DB::statement('SET FOREIGN_KEY_CHECKS=1');
use App\Services\Dashboard\{DesktopDashboardCoreAuthority,DesktopDashboardDevices,DesktopDashboardReconciliation,DesktopDashboardRemoteAttempts};
use Illuminate\Support\Facades\Crypt;
foreach(['2026_10_08_160000_create_desktop_dashboard_local_state.php'=>'CreateDesktopDashboardLocalState','2026_10_08_210000_create_desktop_dashboard_remote_attempts.php'=>'CreateDesktopDashboardRemoteAttempts'] as $file=>$class){require_once $application.'/database/migrations/'.$file;(new $class)->up();}
DB::table('users')->insert(['id'=>12,'added_by'=>1,'name'=>'Core operator','email'=>'core12@test.invalid','mobile'=>'01000000012','account_type'=>'admin','status'=>'accepted','app_scope'=>'fasakhansta']);
$permission=Permission::findOrCreate('order-list','admin');$edit=Permission::findOrCreate('order-edit','admin');$operator=Role::create(['name'=>'Core operator','guard_name'=>'admin']);
$morph=(new User)->getMorphClass();$direct=['permission_id'=>$permission->id,'model_id'=>12,'model_type'=>$morph];$grant=['permission_id'=>$permission->id,'role_id'=>$operator->id];$assignment=['role_id'=>$operator->id,'model_id'=>12,'model_type'=>$morph];
$restore=function(string $source='role')use($permission,$operator,$direct,$grant,$assignment){
    DB::table('users')->where('id',12)->update(['status'=>'accepted','account_type'=>'admin','owner_resturant_id'=>null]);
    DB::table('model_has_permissions')->where('model_id',12)->delete();DB::table('model_has_roles')->where('model_id',12)->delete();DB::table('role_has_permissions')->where('role_id',$operator->id)->delete();
    DB::table('permissions')->where('id',$permission->id)->update(['name'=>'order-list']);
    if($source==='direct')DB::table('model_has_permissions')->insert($direct);else{DB::table('model_has_roles')->insert($assignment);DB::table('role_has_permissions')->insert($grant);}
    app(PermissionRegistrar::class)->forgetCachedPermissions();
};$restore();
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-20T05:00:00Z'));
config(['desktop_dashboard.local'=>false,'desktop_dashboard.enabled'=>true,'desktop_dashboard.device_id'=>'']);
$devices=app(DesktopDashboardDevices::class);$reconcile=app(DesktopDashboardReconciliation::class);$attempts=app(DesktopDashboardRemoteAttempts::class);$authority=app(DesktopDashboardCoreAuthority::class);
$link=$devices->enroll(['device_id'=>(string)Str::uuid(),'name'=>'CORE fresh grants fixture','nonce'=>bin2hex(random_bytes(32))],User::withoutGlobalScopes()->findOrFail(12));$device=$devices->device($link['token']);$original=(array)$device;
DB::table('desktop_dashboard_local_state')->insert(['device_id'=>$device->id,'actor_id'=>12,'snapshot_id'=>(string)Str::uuid(),'schema_hash'=>str_repeat('a',64),'branches'=>'["f:100","f:999"]','coverage'=>'{}','state'=>'ready','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$envelope=function(){$command=(string)Str::uuid();return ['command_id'=>$command,'actor_id'=>12,'route_name'=>'delivery-companies.save',
    'payload'=>['parameters'=>[],'values'=>['idempotency_key'=>$command,'branch'=>'f:100','name'=>'Core company '.$command,'phone'=>'01010000001','active'=>true],'files'=>[]],
    'local_result'=>['success'=>true],'local_references'=>[],'dependencies'=>[],'occurred_at'=>now('UTC')->toIso8601String()];};
$first=$envelope();$receipt=$reconcile->ingest($device,$first);
verify($receipt['committed']&&DB::table('branch_delivery_companies')->where('name',$first['payload']['values']['name'])->count()===1,'current CORE authority permits the actual original delivery company service');
$business=fn()=>[DB::table('branch_delivery_companies')->count(),DB::table('branch_operation_commands')->count(),DB::table('desktop_dashboard_commands')->count(),DB::table('wallets')->count()];$before=$business();
verify($reconcile->ingest($device,$first)===$receipt&&$business()===$before,'the unchanged current actor receives its exact ordinary saved receipt without rerunning business work');
$nativeValues=$first['payload']['values'];$nativeValues['idempotency_key']=(string)Str::uuid();$nativeValues['name']='Native core '.$nativeValues['idempotency_key'];
$route=app('router')->getRoutes()->getByName('delivery-companies.save');$path='/'.$route->uri();
$native=function(array $proof,array $values)use($attempts,$path){
    $route=clone app('router')->getRoutes()->getByName('delivery-companies.save');$request=Request::create(url($path),'POST',$values);$route->bind($request);$request->setRouteResolver(fn()=>$route);
    $request->headers->set('X-Fasakhansta-Remote-Attempt',$proof['id']);$request->headers->set('X-Fasakhansta-Remote-Capability',$proof['capability']);
    $old=app('request');app()->instance('request',$request);\Illuminate\Support\Facades\Facade::clearResolvedInstance('request');app('url')->setRequest($request);
    try{return $attempts->handle($request,fn($r)=>app('router')->prepareResponse($r,$route->run()))->getStatusCode();}
    finally{app()->instance('request',$old);\Illuminate\Support\Facades\Facade::clearResolvedInstance('request');app('url')->setRequest($old);}
};
auth('admin')->setUser(User::withoutGlobalScopes()->findOrFail(12));$reservation=['id'=>(string)Str::uuid(),'method'=>'POST','path'=>$path];$proof=$attempts->decide($device,$reservation,false);
verify($native($proof,$nativeValues)===200&&$native($proof,$nativeValues)===200,'the current original native controller commits and recovers its exact response');
verify($attempts->decide($device,$reservation,false)['status']==='committed','native committed recovery uses its encrypted exact CORE authority context');
$row=DB::table('desktop_dashboard_remote_attempts')->where('id',$proof['id'])->first();$saved=json_decode(Crypt::decryptString($row->response_cipher),true,512,JSON_THROW_ON_ERROR);$unbound=$saved;unset($unbound['core_authority']);
DB::table('desktop_dashboard_remote_attempts')->where('id',$proof['id'])->update(['response_cipher'=>Crypt::encryptString(json_encode($unbound))]);
rejectWallet(fn()=>$attempts->decide($device,$reservation,false),503,'a historical committed CORE response without exact authority context is retained for review');
rejectWallet(fn()=>$native($proof,$nativeValues),503,'an old native CORE response without exact authority context cannot be returned as a current success');
DB::table('desktop_dashboard_remote_attempts')->where('id',$proof['id'])->update(['response_cipher'=>$row->response_cipher]);
$localValues=$first['payload']['values'];$localValues['idempotency_key']=(string)Str::uuid();$localValues['name']='Local core '.$localValues['idempotency_key'];
config(['desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>$device->id]);
$localResult=app(DesktopDashboardJournal::class)->execute($device->id,$localValues['idempotency_key'],12,'delivery-companies.save',['values'=>$localValues],[],fn()=>app(\App\Services\Dashboard\DeliveryCompanies::class)->save($localValues,User::withoutGlobalScopes()->findOrFail(12)));
verify($localResult['success']&&app(DesktopDashboardJournal::class)->execute($device->id,$localValues['idempotency_key'],12,'delivery-companies.save',['values'=>$localValues],[],fn()=>throw new RuntimeException('Saved local CORE reentered business work'))===$localResult,'the local ordinary Journal checks current BASE authority before recovering its exact cached result');
config(['desktop_dashboard.local'=>false,'desktop_dashboard.device_id'=>'']);
$spawn=function(string $action,array $extra=[])use($application,$device,$first,$reservation,$proof,$nativeValues,$localValues){
    $env=getenv();$env['DB_DATABASE']=DB::connection()->getDatabaseName();$process=proc_open([PHP_BINARY,__DIR__.'/core-authority-worker.php',$application],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env,['bypass_shell'=>true]);
    if(!is_resource($process))throw new RuntimeException('Cannot start independent CORE authority worker');
    fwrite($pipes[0],json_encode(['action'=>$action,'device'=>$device,'envelope'=>$first,'reservation'=>$reservation,'proof'=>$proof,'native_values'=>$nativeValues,'local_values'=>$localValues]+$extra,JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_timeout($pipes[1],15);
    $line=trim((string)fgets($pipes[1]));if(!str_starts_with($line,'WARM '))throw new RuntimeException('CORE worker failed to warm: '.$line.' '.stream_get_contents($pipes[2]));
    return [$process,$pipes,json_decode(substr($line,5),true,32,JSON_THROW_ON_ERROR)];
};
$collect=function(array $job){[$process,$pipes]=$job;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)throw new RuntimeException('CORE worker failed: '.$out.' '.$err);return json_decode(trim($out),true,512,JSON_THROW_ON_ERROR);};
$await=function(array $job)use($pdo){$warm=$job[2];$waiting=false;$query=null;for($i=0;$i<40;$i++){$query=$pdo->query('SELECT trx_state,trx_query FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id='.(int)$warm['connection'])->fetch(PDO::FETCH_ASSOC);if($query&&$query['trx_state']==='LOCK WAIT'&&str_contains($query['trx_query'],'desktop_dashboard_devices')){$waiting=true;break;}usleep(100000);}if(!$waiting)throw new RuntimeException('CORE worker did not wait on current device: '.json_encode([$warm,$query]));return $warm;};
foreach(['disabled','direct','role-assignment','role-permission','permission-catalog','base-branch','actor-branch'] as $revocation)foreach(['ordinary','native-decide','native-handle','local'] as $action){
    $restore($revocation==='direct'?'direct':'role');DB::table('desktop_dashboard_devices')->where('id',$device->id)->update(['branches'=>$original['branches']]);$before=$business();
    DB::beginTransaction();DB::table('desktop_dashboard_devices')->where('id',$device->id)->lockForUpdate()->first();$job=$spawn($action);$warm=$await($job);
    verify($warm['isolation']==='REPEATABLE-READ'&&$warm['status']==='accepted'&&$warm['permission']===true,$revocation.' '.$action.' waits after an actual old RR view and warmed positive Spatie authority');
    if($revocation==='disabled')DB::table('users')->where('id',12)->update(['status'=>'disabled']);
    elseif($revocation==='direct')DB::table('model_has_permissions')->where($direct)->delete();
    elseif($revocation==='role-assignment')DB::table('model_has_roles')->where($assignment)->delete();
    elseif($revocation==='role-permission')DB::table('role_has_permissions')->where($grant)->delete();
    elseif($revocation==='permission-catalog')DB::table('permissions')->where('id',$permission->id)->update(['name'=>'revoked-order-list']);
    elseif($revocation==='base-branch')DB::table('desktop_dashboard_devices')->where('id',$device->id)->update(['branches'=>'[]']);
    else DB::table('users')->where('id',12)->update(['owner_resturant_id'=>101]);
    DB::commit();$reply=$collect($job);verify($reply['status']===($revocation==='actor-branch'?404:403),$revocation.' denies '.$action.' cached success using current locked grants and BASE facts');
    verify($business()===$before&&DB::table('desktop_dashboard_remote_attempts')->where('id',$reservation['id'])->value('status')==='committed',$revocation.' '.$action.' denial preserves original business rows and the committed native outcome');
}
$restore();DB::table('desktop_dashboard_devices')->where('id',$device->id)->update(['branches'=>$original['branches']]);
foreach(['direct','role'] as $source)foreach(['ordinary','native-decide','native-handle','local'] as $action){
    $restore($source);if($source==='direct')DB::table('model_has_permissions')->where($direct)->delete();else DB::table('role_has_permissions')->where($grant)->delete();app(PermissionRegistrar::class)->forgetCachedPermissions();
    $before=$business();DB::beginTransaction();DB::table('desktop_dashboard_devices')->where('id',$device->id)->lockForUpdate()->first();$job=$spawn($action);$warm=$await($job);
    verify($warm['isolation']==='REPEATABLE-READ'&&$warm['permission']===false,$source.' '.$action.' waits under an old RR view and warmed missing grant');
    if($source==='direct')DB::table('model_has_permissions')->insert($direct);else DB::table('role_has_permissions')->insert($grant);
    DB::commit();$reply=$collect($job);verify($reply['status']===200,$source.' new current grant permits '.$action.' cached recovery despite the stale negative view');verify($business()===$before,$source.' '.$action.' positive recovery repeats no business effect');
}
$restore();
// Compare the shared boundary to actual original services, including stricter
// minimum/shift/profile policies that the coarse native route map does not express.
$policyCases=[
    'takeaway.settings'=>fn($a)=>app(\App\Services\Dashboard\TakeawayAccess::class)->permissions($a)['can_manage'],
    'employees.save'=>fn($a)=>app(\App\Services\Dashboard\BranchPayroll::class)->canManageEmployees($a),
    'branch-stock.minimum-save'=>fn($a)=>app(\App\Services\Dashboard\BranchStockMinimums::class)->canManage($a),
    'branch-stock.recipe-save'=>fn($a)=>app(\App\Services\Dashboard\BranchInventory::class)->canManage($a),
    'branch-stock.basic-apply'=>fn($a)=>app(\App\Services\Dashboard\BranchBasicRecipes::class)->canApply($a),
    'branch-shifts.close'=>fn($a)=>app(\App\Services\Dashboard\BranchShiftClosing::class)->canClose($a),
    'branch-expenses.review'=>fn($a)=>app(\App\Services\Dashboard\BranchExpenses::class)->permissions($a)['can_approve'],
    'branch-expenses.categorySave'=>fn($a)=>app(\App\Services\Dashboard\ExpenseCategories::class)->canCreate($a),
    'employees.attendance-rules'=>fn($a)=>app(\App\Services\Dashboard\BranchPayroll::class)->canManageAttendance($a),
    'employees.entry'=>fn($a)=>app(\App\Services\Dashboard\BranchPayroll::class)->canAddBonus($a),
];
foreach([['vendor',null],['resturant_owner',100],['admin',100],['admin',null]] as [$type,$parent]){
    DB::table('users')->where('id',10)->update(['account_type'=>$type,'owner_resturant_id'=>$parent]);
    if($type==='admin'&&$parent===null)DB::table('model_has_permissions')->insert(['permission_id'=>$permission->id,'model_id'=>10,'model_type'=>$morph]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();$current=User::withoutGlobalScopes()->findOrFail(10);
    foreach($policyCases as $route=>$originalPolicy){
        $expected=$originalPolicy($current);$allowed=true;
        try{DB::transaction(fn()=>$authority->authorize($route,['branch'=>'f:100','kind'=>'bonus'],(object)['actor_id'=>10,'branches'=>'["f:100"]']));}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){if($error->getStatusCode()!==403)throw $error;$allowed=false;}
        verify($allowed===$expected,$type.' '.($parent??'central').' '.$route.' matches the actual original service permission');
    }
}
DB::table('model_has_permissions')->where('model_id',10)->delete();DB::table('users')->where('id',10)->update(['account_type'=>'vendor','owner_resturant_id'=>null]);
DB::transaction(function()use($authority){
    verify($authority->authorize('branch-expenses.categorySave',[],(object)['actor_id'=>1,'branches'=>'[]'])->id===1,'the original primary owner branchless legacy category retry retains its authorization without inventing BASE scope');
    verify($authority->authorize('dashboard-inbox.notifications.read',[],(object)['actor_id'=>10,'branches'=>'[]'])->id===10,'own notification reads retain their original branchless authority');
});
// The current-facts bridge is private, scoped, one-shot and exception-safe. It
// changes no unscoped TakeawayAccess authorization or cached grant behavior.
DB::transaction(function()use($authority,$device){
    $outer=$authority->authorize('delivery-companies.save',['branch'=>'f:100'],$device);
    $observed=$authority->withControllerFacts($outer,function()use($authority,$device,$outer){
        verify($authority->currentControllerFacts($outer)['actor']===$outer,'original controller middleware sees its freshly locked current actor facts');
        $inner=$authority->authorize('delivery-companies.save',['branch'=>'f:100'],$device);
        try{$authority->withControllerFacts($inner,fn()=>throw new RuntimeException('nested controller failure'));}
        catch(RuntimeException $error){verify($error->getMessage()==='nested controller failure'&&$authority->currentControllerFacts($outer)['actor']===$outer,'a nested middleware exception restores the outer current facts exactly');}
        return true;
    });
    verify($observed&&$authority->currentControllerFacts($outer)===null,'the original unscoped access service is restored after the current middleware boundary');
    rejectWallet(fn()=>$authority->withControllerFacts($outer,fn()=>true),503,'consumed current facts cannot be reused later as an unvalidated permission capability');
});
foreach(['commit','rollback'] as $ending){
    $escaped=null;
    try{DB::transaction(function()use($authority,$device,$ending,&$escaped){$escaped=$authority->authorize('delivery-companies.save',['branch'=>'f:100'],$device);if($ending==='rollback')throw new RuntimeException('rollback current facts');});}
    catch(RuntimeException $error){if($error->getMessage()!=='rollback current facts')throw $error;}
    DB::transaction(fn()=>rejectWallet(fn()=>$authority->withControllerFacts($escaped,fn()=>true),503,'unconsumed actor proof cannot escape a completed '.$ending.' transaction into a new one'));
}
// The actual original notification form uses a raw POST and PUT override; it
// remains branchless and its committed recovery still requires the exact context.
$notification=(string)Str::uuid();DB::table('notifications')->insert(['id'=>$notification,'type'=>'Fixture','notifiable_type'=>$morph,'notifiable_id'=>12,'data'=>'{}','read_at'=>null,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$noticeReservation=['id'=>(string)Str::uuid(),'method'=>'POST','path'=>'/admin/read/'.$notification];$noticeProof=$attempts->decide($device,$noticeReservation,false);$noticeCommand=(string)Str::uuid();
$notice=function()use($attempts,$noticeReservation,$noticeProof,$noticeCommand,$notification){
    Request::enableHttpMethodParameterOverride();$request=Request::create(url($noticeReservation['path']),'POST',['_method'=>'PUT','_desktop_command'=>$noticeCommand,'desktop_notification_ids'=>json_encode([$notification])]);
    $route=clone app('router')->getRoutes()->getByName('read_notify');$route->bind($request);$request->setRouteResolver(fn()=>$route);$request->getMethod();
    $request->headers->set('X-Fasakhansta-Remote-Attempt',$noticeProof['id']);$request->headers->set('X-Fasakhansta-Remote-Capability',$noticeProof['capability']);
    $old=app('request');app()->instance('request',$request);\Illuminate\Support\Facades\Facade::clearResolvedInstance('request');app('url')->setRequest($request);
    try{return $attempts->handle($request,fn($r)=>app('router')->prepareResponse($r,$route->run()))->getStatusCode();}
    finally{app()->instance('request',$old);\Illuminate\Support\Facades\Facade::clearResolvedInstance('request');app('url')->setRequest($old);}
};
verify($notice()===302&&$notice()===302&&DB::table('notifications')->where('id',$notification)->value('read_at')!==null,'the actual branchless POST/PUT notification form commits and recovers its original redirect');
verify($attempts->decide($device,$noticeReservation,false)['status']==='committed','raw POST notification recovery resolves the exact original PUT route authority');
$noticeRow=DB::table('desktop_dashboard_remote_attempts')->where('id',$noticeProof['id'])->first();$noticeSaved=json_decode(Crypt::decryptString($noticeRow->response_cipher),true,512,JSON_THROW_ON_ERROR);unset($noticeSaved['core_authority']);
DB::table('desktop_dashboard_remote_attempts')->where('id',$noticeProof['id'])->update(['response_cipher'=>Crypt::encryptString(json_encode($noticeSaved))]);
rejectWallet(fn()=>$attempts->decide($device,$noticeReservation,false),503,'raw POST recovery cannot evade current CORE context checks through the original PUT override');
DB::table('desktop_dashboard_remote_attempts')->where('id',$noticeProof['id'])->update(['response_cipher'=>$noticeRow->response_cipher]);
echo 'CORE_CURRENT_AUTHORITY_CHECKS_COMPLETE '.$count.PHP_EOL;
