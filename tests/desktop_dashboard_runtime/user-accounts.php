<?php
// Real original Laravel controller/observer replay in an isolated MariaDB schema.
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardUserAccounts,DesktopDashboardJournal,DesktopDashboardReferences};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
$application=realpath($argv[1]);$port=(int)$argv[2];
if(!$application||$port<1024)throw new RuntimeException('Use user-accounts.php prepared-application disposable-mariadb-port');
$profile=sys_get_temp_dir().'/desktop-user-accounts-'.bin2hex(random_bytes(8));foreach(['app/public','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache','private'] as $dir)mkdir($profile.'/'.$dir,0700,true);
$database='desktop_user_accounts_'.bin2hex(random_bytes(8));$pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
foreach(['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_STORAGE'=>$profile,'APP_ENV'=>'desktop','APP_DEBUG'=>'false','APP_URL'=>'http://127.0.0.1:43145','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),
    'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,'DB_DATABASE'=>$database,'DB_USERNAME'=>'root','DB_PASSWORD'=>'','CACHE_DRIVER'=>'file','SESSION_DRIVER'=>'file',
    'APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php','APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php','APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
$app=require $application.'/desktop/bootstrap.php';set_exception_handler(function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
$count=0;function verified($ok,$message){global $count;if(!$ok)throw new RuntimeException($message);$count++;echo 'PASS '.$message.PHP_EOL;}
function deniedAccount(callable $work,int $status,string $message){try{$work();}catch(\Illuminate\Validation\ValidationException $e){verified($status===422,$message);return;}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){verified($e->getStatusCode()===$status,$message);return;}throw new RuntimeException('Expected denial: '.$message);}
require __DIR__.'/full-schema.php';$report=json_decode(file_get_contents(__DIR__.'/fixtures/deployed-schema-20261008.json'),true,512,JSON_THROW_ON_ERROR);DesktopFullSchemaFixture::create($report['tables']);
require $application.'/database/migrations/2026_10_08_130000_create_desktop_dashboard_journal.php';(new CreateDesktopDashboardJournal)->up();
DB::statement('SET FOREIGN_KEY_CHECKS=0');
foreach([[1,'admin','accepted'],[10,'vendor','declined'],[20,'user','accepted'],[30,'admin','accepted'],[40,'delegate','accepted'],[560,'admin','accepted']] as [$id,$type,$status])DB::table('users')->insert(['id'=>$id,'added_by'=>1,'name'=>'Fixture '.$id,'email'=>'account'.$id.'@test.invalid','mobile'=>'01000000'.str_pad((string)$id,3,'0',STR_PAD_LEFT),
    'password'=>password_hash('SecretFixture123',PASSWORD_BCRYPT),'remember_token'=>'private-fixture-token-'.$id,'account_type'=>$type,'status'=>$status,'app_scope'=>'fasakhansta','expiration_date'=>'2026-10-21','created_at'=>'2026-10-01 10:00:00','updated_at'=>'2026-10-01 10:00:00']);
DB::table('resturants')->insert(['id'=>100,'added_by'=>1,'user_id'=>10,'name'=>'فرع الاختبار','status'=>'opened','address'=>'المنصورة']);
DB::table('user_address')->insert([['id'=>300,'user_id'=>20,'address'=>'العنوان المرتبط','mobile'=>'01000000020'],['id'=>301,'user_id'=>20,'address'=>'عنوان غير مستخدم','mobile'=>'01000000020']]);
DB::table('orders')->insert(['id'=>500,'user_id'=>20,'user_address_id'=>300,'resturant_id'=>100]);
DB::statement('SET FOREIGN_KEY_CHECKS=1');
$role=Role::create(['name'=>'Super Admin','guard_name'=>'admin']);$edit=\Spatie\Permission\Models\Permission::create(['name'=>'delegate-edit','guard_name'=>'admin']);$role->givePermissionTo($edit);$admin=User::withoutGlobalScopes()->findOrFail(30);$admin->assignRole($role);
$actor=User::withoutGlobalScopes()->findOrFail(1);$adapter=app(DesktopDashboardUserAccounts::class);$journal=app(DesktopDashboardJournal::class);$device=(string)Str::uuid();config(['desktop_dashboard.local'=>true,'desktop_dashboard.enabled'=>true,'desktop_dashboard.device_id'=>'']);
$accountScope=function(int $actorId,array $branches,?string $id=null){$id=$id??(string)Str::uuid();DB::table('desktop_dashboard_devices')->insert(['id'=>$id,'actor_id'=>$actorId,'name'=>'actual account fixture binding','enrollment_hash'=>str_repeat('b',64),'token_hash'=>hash('sha256',$id),'branches'=>json_encode($branches),'enabled'=>true,'enrolled_at'=>now('UTC'),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);return DB::table('desktop_dashboard_devices')->where('id',$id)->first();};
$scope=$accountScope(1,['f:100'],$device);
$payload=function(string $name,int $id,array $values=[],?string $command=null,?array $saved=null,?User $who=null,?object $enrollment=null)use($actor,$scope,$adapter){
    $command=$command??(string)Str::uuid();$who=$who??$actor;$enrollment=$enrollment??$scope;$original=app('router')->getRoutes()->getByName($name);$parameter=$name==='users.change-status'?'user':'id';
    $request=Request::create(url(str_replace('{'.$parameter.'}',(string)$id,$original->uri())),$name==='users.change-status'?'GET':'DELETE',$values+['_desktop_command'=>$command]);
    $route=clone $original;$route->bind($request);$request->setRouteResolver(fn()=>$route);
    return DB::transaction(fn()=>$adapter->payload($name,$request,$command,$who,$enrollment,$saved));
};
$run=function(string $name,array $payload,?User $who=null)use($device,$actor,$journal,$adapter){$who=$who??$actor;return $journal->execute($device,$payload['values']['idempotency_key'],(int)$who->id,$name,$payload,[],fn()=>$adapter->execute($name,$payload,$who));};
$fingerprint=function(){return app(DesktopDashboardJournal::class)->fingerprint(['users'=>DB::table('users')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all(),'addresses'=>DB::table('user_address')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all(),'orders'=>DB::table('orders')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all(),'journal'=>DB::table('desktop_dashboard_commands')->orderBy('sequence')->get()->map(fn($r)=>(array)$r)->all()]);};
$secret=(array)DB::table('users')->where('id',10)->first(['password','remember_token']);$p=$payload('users.change-status',10,['status'=>'accepted']);
verified(!str_contains(json_encode($p),$secret['password'])&&!str_contains(json_encode($p),$secret['remember_token']),'status before-facts never contain a password hash or bearer/remember token');
$result=$run('users.change-status',$p);verified(DB::table('users')->where('id',10)->value('status')==='accepted'&&DB::table('users')->where('id',10)->value('expiration_date')===null,'original status controller accepts the vendor and clears its original expiry');
verified((array)DB::table('users')->where('id',10)->first(['password','remember_token'])===$secret,'status controller preserves both stored credentials byte for byte');
verified($result['references']===['dashboard_user'=>10,'wallet_account'=>10]&&DB::table('desktop_dashboard_entities')->where('command_id',$p['values']['idempotency_key'])->count()===2,'durable result exports only explicit proven User identity aliases');
$after=$fingerprint();$saved=$journal->savedFacts($device,$p['values']['idempotency_key'],1,'users.change-status');$retry=$payload('users.change-status',10,['status'=>'accepted'],$p['values']['idempotency_key'],$saved);
verified($run('users.change-status',$retry)===$result&&$fingerprint()===$after,'lost status reply recovers the exact saved original response without another account/journal mutation');
$changed=$retry;$changed['values']['status']='declined';deniedAccount(fn()=>$run('users.change-status',$changed),409,'one account operation UUID cannot change its requested status');
verified($fingerprint()===$after,'changed UUID status preserves all users, orders, addresses and journal rows');
foreach([1,560] as $id)deniedAccount(fn()=>$payload('users.change-status',$id,['status'=>'declined']),403,'protected account '.$id.' cannot be disabled from the offline administration form');
deniedAccount(fn()=>$payload('users.change-status',20,['status'=>'declined']),501,'customer status remains closed until all CRM observer branches are reviewed');
deniedAccount(fn()=>$payload('users.change-status',40,['status'=>'arbitrary']),422,'original status controls accept only the two reviewed exact values');
deniedAccount(fn()=>$payload('users.change-status',40,['status'=>'declined','password'=>'Attacker123']),422,'a status action cannot smuggle a password or account field mutation');
$stale=$payload('users.change-status',40,['status'=>'declined']);$race=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$database.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
DB::beginTransaction();try{DB::table('users')->where('id',40)->first();$race->exec("UPDATE users SET name='Concurrent partner update' WHERE id=40");deniedAccount(fn()=>$adapter->execute('users.change-status',$stale,$actor),409,'locked complete public account facts see concurrent changes under an older repeatable-read snapshot');}finally{DB::rollBack();}
$race->exec("UPDATE users SET name='Fixture 40' WHERE id=40");
$ap=$payload('useraddresses.destroy',301);$addressResult=$run('useraddresses.destroy',$ap);verified(!DB::table('user_address')->where('id',301)->exists()&&DB::table('user_address')->where('id',300)->exists(),'original address controller removes only the selected unused address');
$as=$journal->savedFacts($device,$ap['values']['idempotency_key'],1,'useraddresses.destroy');$ar=$payload('useraddresses.destroy',301,[],$ap['values']['idempotency_key'],$as);verified($run('useraddresses.destroy',$ar)===$addressResult,'a lost address-delete reply remains recoverable after its original row was deleted');
$before=$fingerprint();deniedAccount(fn()=>$payload('useraddresses.destroy',300),409,'an address referenced by an existing legacy order cannot cascade-delete financial history');verified($fingerprint()===$before,'referenced-address denial preserves the order and every account row');
$rollback=$payload('users.change-status',40,['status'=>'declined']);$before=$fingerprint();try{$journal->execute($device,$rollback['values']['idempotency_key'],1,'users.change-status',$rollback,[],function()use($adapter,$rollback,$actor){$adapter->execute('users.change-status',$rollback,$actor);throw new RuntimeException('forced-rollback');});}catch(RuntimeException $e){verified($e->getMessage()==='forced-rollback','forced failure occurs after the actual original account controller');}verified($fingerprint()===$before,'account status, observers and outbox roll back together after an original-controller failure');
// Status only changes availability: an enrolled GO account remains administratively recoverable.
DB::table('users')->where('id',10)->update(['app_scope'=>'go_partner']);DB::statement('SET FOREIGN_KEY_CHECKS=0');DB::table('go_stores')->insert(['user_id'=>10]);DB::statement('SET FOREIGN_KEY_CHECKS=1');
$goScope=$accountScope(1,['f:100','gs:10']);$go=$payload('users.change-status',10,['status'=>'declined'],null,null,null,$goScope);$goResult=$run('users.change-status',$go);
$goSaved=$journal->savedFacts($device,$go['values']['idempotency_key'],1,'users.change-status');$goRetry=$payload('users.change-status',10,['status'=>'declined'],$go['values']['idempotency_key'],$goSaved,null,$goScope);
verified($run('users.change-status',$goRetry)===$goResult,'declining an enrolled GO owner preserves its exact administrative lost-reply recovery');
$reactivate=$payload('users.change-status',10,['status'=>'accepted'],null,null,null,$goScope);$run('users.change-status',$reactivate);verified(DB::table('users')->where('id',10)->value('status')==='accepted','original GO account reactivation retains structural administrative scope');
DB::table('go_stores')->where('user_id',10)->delete();deniedAccount(fn()=>DB::transaction(fn()=>$adapter->authorize('users.change-status',$goRetry,$actor,$goScope)),404,'a removed enrolled GO profile still rejects cached account replies');DB::table('users')->where('id',10)->update(['app_scope'=>'fasakhansta']);
// Warm a role-bearing Eloquent model and an older read view, then revoke by another connection.
$admin->load('roles');$superScope=$accountScope(30,['f:100']);$sp=$payload('users.change-status',40,['status'=>'declined'],null,null,$admin,$superScope);
DB::table('role_has_permissions')->where('role_id',$role->id)->delete();deniedAccount(fn()=>DB::transaction(fn()=>$adapter->authorize('users.change-status',$sp,$admin,$superScope)),403,'current module edit grants remain necessary even for the complete Super Admin cohort');$role->givePermissionTo($edit);
DB::beginTransaction();try{DB::table('model_has_roles')->where('model_id',30)->get();$race->exec('DELETE FROM model_has_roles WHERE model_id=30');deniedAccount(fn()=>$adapter->authorize('users.change-status',$sp,$admin,$superScope),403,'warmed roles and old repeatable-read snapshots cannot revive revoked current Super Admin authority');}finally{DB::rollBack();}
$disabled=(object)['actor_id'=>1,'branches'=>'["f:100"]','enabled'=>false];deniedAccount(fn()=>DB::transaction(fn()=>$adapter->authorize('users.change-status',$retry,$actor,$disabled)),401,'disabled device enrollment cannot return an already successful account reply');
$foreign=$accountScope(1,['f:999']);deniedAccount(fn()=>DB::transaction(fn()=>$adapter->authorize('users.change-status',$retry,$actor,$foreign)),404,'enrolled branch membership is rechecked before saved account replies');
verified(DB::table('orders')->where('id',500)->value('user_address_id')===300,'the linked legacy order retains its exact existing address after all account operations');
require __DIR__.'/user-profiles.php';
require __DIR__.'/user-accounts-reconciliation.php';
echo 'USER_ACCOUNT_CHECKS_COMPLETE '.$count.PHP_EOL;
