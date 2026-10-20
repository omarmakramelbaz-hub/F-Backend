<?php
// Standalone original Laravel/MariaDB cash-form fixture; never connects to a live system.
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardWalletCash,WalletCashOperations};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\{Role,Permission};
use Spatie\Permission\PermissionRegistrar;

$application=realpath($argv[1]);$port=(int)$argv[2];
if(!$application||!is_file($application.'/desktop/bootstrap.php')||$port<1024)throw new RuntimeException('Use wallet-cash.php prepared-application disposable-mariadb-port');
$profile=sys_get_temp_dir().'/desktop-wallet-cash-'.bin2hex(random_bytes(8));
foreach(['app/public','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache','private'] as $dir)mkdir($profile.'/'.$dir,0700,true);
$database='desktop_wallet_cash_'.bin2hex(random_bytes(8));
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
foreach(['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_STORAGE'=>$profile,'APP_ENV'=>'desktop','APP_DEBUG'=>'false','APP_URL'=>'http://127.0.0.1:43144',
    'APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,'DB_DATABASE'=>$database,'DB_USERNAME'=>'root','DB_PASSWORD'=>'',
    'CACHE_DRIVER'=>'file','SESSION_DRIVER'=>'file','APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php','APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php',
    'APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $name=>$value)putenv($name.'='.$value);
$app=require $application.'/desktop/bootstrap.php';
set_exception_handler(function(Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);});
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
foreach((new ReflectionClass(\App\Models\GeneralSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property){
    $value=$property->getType()?->getName()==='bool'?true:'0';DB::table('settings')->insert(['group'=>'general','name'=>$property->getName(),'locked'=>false,'payload'=>json_encode($value)]);
}
DB::statement('SET FOREIGN_KEY_CHECKS=1');
$actor=User::withoutGlobalScopes()->findOrFail(1);$adapter=app(DesktopDashboardWalletCash::class);$service=app(WalletCashOperations::class);$journal=app(DesktopDashboardJournal::class);
$device=(string)Str::uuid();config(['desktop_dashboard.local'=>true,'desktop_dashboard.enabled'=>true,'desktop_dashboard.device_id'=>'']);
// Exercise persisted immutable BASE evidence, as the real prepared/native paths do.
function walletFixtureEnrollment(int $actorId,array $branches,?string $id=null): object
{
    $id=$id??(string)Str::uuid();DB::table('desktop_dashboard_devices')->insert(['id'=>$id,'actor_id'=>$actorId,'name'=>'Actual wallet fixture enrollment',
        'enrollment_hash'=>hash('sha256','wallet-fixture-enrollment:'.$id),'token_hash'=>hash('sha256','wallet-fixture-token:'.$id),
        'branches'=>json_encode($branches),'enabled'=>true,'enrolled_at'=>now('UTC'),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    return DB::table('desktop_dashboard_devices')->where('id',$id)->first();
}
$scope=walletFixtureEnrollment(1,['f:100'],$device);
$request=function(string $name,array $values)use($adapter): Request{
    $route=clone app('router')->getRoutes()->getByName($name);$request=Request::create(url($route->uri()),'POST',$values);
    $route->bind($request);$request->setRouteResolver(fn()=>$route);$request->headers->set('X-Fasakhansta-Command',$values['idempotency_key']);return $request;
};
$payload=function(string $name,array $values,?array $saved=null)use($adapter,$actor,$scope,$request): array{
    $req=$request($name,$values);$req->request->remove('idempotency_key');$req->request->set('_desktop_command',$values['idempotency_key']);
    return DB::transaction(fn()=>$adapter->payload($name,$req,$values['idempotency_key'],$actor,$scope,$saved));
};
$fingerprint=function(): string{
    $out=[];foreach(['users','wallets','notifications','desktop_dashboard_commands','desktop_dashboard_entities'] as $table){
        $rows=DB::table($table)->get()->map(fn($row)=>json_encode((array)$row))->all();sort($rows,SORT_STRING);$out[$table]=$rows;
    }
    return app(DesktopDashboardJournal::class)->fingerprint($out);
};
$credit=['to_user'=>20,'from_user'=>'','type'=>'transfer','amount'=>'1.01','idempotency_key'=>(string)Str::uuid()];
$p=$payload('wallets.transfer',$credit);$before=$fingerprint();
$result=$journal->execute($device,$credit['idempotency_key'],1,'wallets.transfer',$p,[],fn()=>$adapter->execute('wallets.transfer',$p,$actor));
verify($result['wallet']['balance_before']==='99.00'&&$result['wallet']['balance_after']==='100.01'&&$result['wallet']['status_after']==='accepted',
    'the original cash top-up controller credits exact decimal money and preserves the threshold reactivation rule');
verify(DB::table('wallets')->count()===1&&DB::table('notifications')->count()===1&&DB::table('desktop_dashboard_commands')->count()===1,
    'cash ledger, balance notification and durable journal commit together');
verify(DB::table('wallets')->value('transfer_reference')===$result['wallet']['transfer_reference']&&strlen($result['wallet']['transfer_reference'])===35,
    'all UUID bits fit the real thirty-six-character ledger reference without silent truncation');
$after=$fingerprint();$saved=$journal->savedFacts($device,$credit['idempotency_key'],1,'wallets.transfer');
$retryPayload=$payload('wallets.transfer',$credit,$saved);
$retry=$journal->execute($device,$credit['idempotency_key'],1,'wallets.transfer',$retryPayload,[],fn()=>throw new RuntimeException('A saved operation reentered its controller'));
verify($retry===$result&&$fingerprint()===$after,'a lost cash top-up reply recovers its immutable result without another ledger entry');
$changed=$credit;$changed['amount']='2.00';$changedPayload=$payload('wallets.transfer',$changed,$saved);
rejectWallet(fn()=>$journal->execute($device,$credit['idempotency_key'],1,'wallets.transfer',$changedPayload,[],fn()=>[]),409,'a cash UUID cannot be reused with a changed amount');
verify($fingerprint()===$after,'changed UUID input has no balance, ledger, notification or journal effects');
$withdraw=['from_user'=>20,'to_user'=>'','type'=>'withdraw','amount'=>'50.01','idempotency_key'=>(string)Str::uuid()];
$wp=$payload('wallets.withdraw.store',$withdraw);$withdrawResult=$journal->execute($device,$withdraw['idempotency_key'],1,'wallets.withdraw.store',$wp,[],fn()=>$adapter->execute('wallets.withdraw.store',$wp,$actor));
verify($withdrawResult['wallet']['balance_after']==='50.00'&&DB::table('wallets')->count()===2,'the original withdrawal controller commits the exact debit and one completed row');
$insufficient=$withdraw;$insufficient['amount']='50.01';$insufficient['idempotency_key']=(string)Str::uuid();$ip=$payload('wallets.withdraw.store',$insufficient);$unchanged=$fingerprint();
rejectWallet(fn()=>$journal->execute($device,$insufficient['idempotency_key'],1,'wallets.withdraw.store',$ip,[],fn()=>$adapter->execute('wallets.withdraw.store',$ip,$actor)),409,
    'insufficient funds reject before a completed withdrawal row or notification exists');
verify($fingerprint()===$unchanged,'insufficient funds preserve every financial and journal row');
foreach(['-1','0','0.99','5000.01','1.001','1e2'] as $bad){$v=$credit;$v['amount']=$bad;rejectWallet(fn()=>$service->apply('wallets.transfer',$v,$actor),422,'invalid cash amount '.$bad.' is rejected before money moves');}
foreach(['status'=>'completed','payment'=>'card','transfer_reference'=>'attacker','order_id'=>1,'from_user'=>10] as $field=>$value){$v=$credit;$v[$field]=$value;rejectWallet(fn()=>$service->apply('wallets.transfer',$v,$actor),422,'caller cannot override cash ledger field '.$field);}
$admin=$credit;$admin['to_user']=30;$admin['idempotency_key']=(string)Str::uuid();rejectWallet(fn()=>$service->apply('wallets.transfer',$admin,$actor),422,'the original non-admin target selection is enforced server-side');
$branch=User::withoutGlobalScopes()->findOrFail(10);rejectWallet(fn()=>$service->apply('wallets.transfer',$credit,$branch),403,'a branch operator cannot mint an administrative wallet top-up');
// A real second MariaDB connection commits after a repeatable-read snapshot.
$race=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$database.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$stale=$credit;$stale['idempotency_key']=(string)Str::uuid();$stale['amount']='1.00';$sp=$payload('wallets.transfer',$stale);
DB::beginTransaction();
try{
    DB::table('users')->where('id',20)->first();$race->exec('UPDATE users SET balance=49.00 WHERE id=20');
    rejectWallet(fn()=>$adapter->execute('wallets.transfer',$sp,$actor),409,'locked wallet before-facts see a concurrent balance commit beneath an older repeatable-read snapshot');
}finally{DB::rollBack();$race->exec('UPDATE users SET balance=50.00 WHERE id=20');}
$race->exec("INSERT INTO wallets (from_user,to_user,amount,type,payment,status,created_at,updated_at) VALUES (20,NULL,0,'withdraw','wallet','completed',NOW(),NOW())");
rejectWallet(fn()=>$adapter->execute('wallets.transfer',$sp,$actor),409,'same balance with a changed financial ledger still requires reconciliation');
$race->exec('DELETE FROM wallets WHERE amount=0');
// Roll back a failure after both original controller and financial work completed.
$rollback=$credit;$rollback['idempotency_key']=(string)Str::uuid();$rp=$payload('wallets.transfer',$rollback);$beforeRollback=$fingerprint();
try{$journal->execute($device,$rollback['idempotency_key'],1,'wallets.transfer',$rp,[],function()use($adapter,$rp,$actor){$adapter->execute('wallets.transfer',$rp,$actor);throw new RuntimeException('forced ledger rollback');});throw new RuntimeException('Rollback probe unexpectedly succeeded');}
catch(RuntimeException $error){verify($error->getMessage()==='forced ledger rollback','the failure probe ran after the original cash controller');}
verify($fingerprint()===$beforeRollback,'an exception after the controller rolls back the exact DECIMAL balance, ledger, notification and journal');
// Typed identities use real differently numbered wallet rows without conflating accounts/orders.
$mapped=$adapter->inputs($wp,fn($entity,$id)=>['$desktop_ref'=>['entity'=>$entity,'local_id'=>(int)$id,'command_id'=>(string)Str::uuid()]]);
$adapter->validateReferences($mapped);verify($mapped['values']['from_user']['$desktop_ref']['entity']==='wallet_account'
    &&$mapped['facts']['wallet_cash_before']['ledger'][0]['id']['$desktop_ref']['entity']==='wallet_cash_movement','cash accounts and ledger rows have distinct explicit reference kinds');
$wrong=$mapped;$wrong['values']['from_user']['$desktop_ref']['entity']='legacy_order';rejectWallet(fn()=>$adapter->validateReferences($wrong),422,'a colliding order ID cannot be used as a wallet account reference');
$batched=$mapped;$batched['facts']['wallet_cash_before']['ledger'][0]['id']['$desktop_ref']['entity']='wallet_cash_movement.0';$adapter->validateReferences($batched);
verify(true,'an explicitly typed movement slot from another reviewed wallet-producing operation retains its wallet table identity');
verify($adapter->outputs($withdrawResult)===['wallet_cash_movement'=>$withdrawResult['wallet']['wallet_id'],'wallet_account'=>20],'cash results expose their actual saved ledger identity and account');
$adapter->verifyAfter($withdrawResult,$withdrawResult);$tampered=$withdrawResult;$tampered['wallet']['balance_after']='51.00';
rejectWallet(fn()=>$adapter->verifyAfter($tampered,$withdrawResult),409,'a mismatching server financial result cannot acknowledge the local cash command');
// Current permissions are checked from locked pivots, independently of Spatie's warmed cache.
$permission=Permission::findOrCreate('wallet-create','admin');$orderPermission=Permission::findOrCreate('order-list','admin');
$role=Role::create(['name'=>'Super Admin','guard_name'=>'admin']);$role->givePermissionTo([$permission,$orderPermission]);
$manager=User::withoutGlobalScopes()->findOrFail(30);$manager->assignRole($role);app(PermissionRegistrar::class)->forgetCachedPermissions();
verify($manager->can('wallet-create'),'the fixture warms the original role grant cache');$scope30=walletFixtureEnrollment(30,['f:100']);
verify(DB::transaction(fn()=>$adapter->authorize('wallets.transfer',$retryPayload,$manager,$scope30))->id===30,
    'a current full-administration wallet actor passes the exact positive authority control before grant revocation');
DB::beginTransaction();try{
    DB::table('role_has_permissions')->count();$race->exec('DELETE FROM role_has_permissions WHERE role_id='.$role->id.' AND permission_id='.$permission->id);
    rejectWallet(fn()=>$adapter->authorize('wallets.transfer',$retryPayload,$manager,$scope30),403,'a revoked warmed role grant rejects an already saved wallet reply under older repeatable-read');
}finally{DB::rollBack();DB::table('role_has_permissions')->insert(['role_id'=>$role->id,'permission_id'=>$permission->id]);}
DB::beginTransaction();try{
    DB::table('role_has_permissions')->count();$race->exec('DELETE FROM role_has_permissions WHERE role_id='.$role->id.' AND permission_id='.$orderPermission->id);
    rejectWallet(fn()=>$adapter->authorize('wallets.transfer',$retryPayload,$manager,$scope30),403,'revoked current enrollment order authority rejects a warmed cached wallet receipt');
}finally{DB::rollBack();DB::table('role_has_permissions')->insert(['role_id'=>$role->id,'permission_id'=>$orderPermission->id]);}
$manager->givePermissionTo($permission);DB::table('role_has_permissions')->where('permission_id',$permission->id)->delete();app(PermissionRegistrar::class)->forgetCachedPermissions();
verify($manager->can('wallet-create'),'the fixture warms an actual direct wallet grant');
DB::beginTransaction();try{
    DB::table('model_has_permissions')->count();$race->exec('DELETE FROM model_has_permissions WHERE model_id=30 AND permission_id='.$permission->id);
    rejectWallet(fn()=>$adapter->authorize('wallets.transfer',$retryPayload,$manager,$scope30),403,'a revoked warmed direct grant rejects an already saved wallet reply under older repeatable-read');
}finally{DB::rollBack();}
$disabled=clone $scope;$disabled->enabled=false;rejectWallet(fn()=>$adapter->authorize('wallets.transfer',$retryPayload,$actor,$disabled),401,'disabled device scope rejects an old cash result');
DB::table('users')->where('id',1)->update(['owner_resturant_id'=>100]);rejectWallet(fn()=>$adapter->authorize('wallets.transfer',$retryPayload,$actor,$scope),403,'a central owner reassigned to a branch cannot recover administrative wallet replies');
DB::table('users')->where('id',1)->update(['owner_resturant_id'=>null]);
DB::table('users')->where('id',20)->update(['mobile'=>'01099999999']);rejectWallet(fn()=>$adapter->authorize('wallets.transfer',$retryPayload,$actor,$scope),409,'a changed canonical account identity rejects old cash result disclosure');
DB::table('users')->where('id',20)->update(['mobile'=>'01000000020']);
DB::table('users')->where('id',20)->update(['created_at'=>now()->addDay()]);rejectWallet(fn()=>$adapter->authorize('wallets.transfer',$retryPayload,$actor,$scope),409,
    'a recreated same-number account cannot inherit disclosure of an older wallet result');
DB::table('users')->where('id',20)->update(['created_at'=>$retryPayload['facts']['wallet_cash_before']['user']['created_at']]);
verify(auth('admin')->getUser()===null,'original cash replay restores the enclosing authentication context');
DB::table('users')->where('id',20)->update(['balance'=>'100.00']);$ledgerCount=DB::table('wallets')->count();$notificationCount=DB::table('notifications')->count();
$gate=$profile.'/withdraw-workers-go';$workers=[];$env=getenv();$env['WALLET_FIXTURE_KEY']=config('app.key');
for($i=0;$i<2;$i++){
    $command=(string)Str::uuid();$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/wallet-cash-worker.php',$application,(string)$port,$database,$profile,$gate,$command],
        [['pipe','r'],['pipe','w'],['file',$profile.'/worker-'.$i.'.log','a']],$pipes,$application,$env);
    verify(is_resource($process),'independent withdrawal worker '.$i.' starts its own real MariaDB connection');
    fclose($pipes[0]);$workers[]=[$process,$pipes[1],$command];
}
for($n=0;$n<500;$n++){
    if(count(glob($gate.'.*.ready'))===2)break;usleep(10000);
}
verify(count(glob($gate.'.*.ready'))===2,'both independent withdrawals wait before racing the same wallet');file_put_contents($gate,'go');$statuses=[];
foreach($workers as [$process,$stdout]){
    $result=json_decode(stream_get_contents($stdout),true,512,JSON_THROW_ON_ERROR);fclose($stdout);verify(proc_close($process)===0,'independent wallet worker returns normally');$statuses[]=$result['status'];
}
sort($statuses);verify($statuses===[200,409]&&\App\Services\GoServices\Money::minor(DB::table('users')->where('id',20)->value('balance'))===4000,
    'concurrent sixty-pound withdrawals serialize and only one can debit a hundred-pound wallet');
verify(DB::table('wallets')->count()===$ledgerCount+1&&DB::table('notifications')->count()===$notificationCount+1,
    'the losing concurrent withdrawal creates neither a completed row nor a notification');
require __DIR__.'/wallet-cash-reconciliation.php';
echo 'WALLET_CASH_CHECKS='.$count.PHP_EOL;
if(getenv('DESKTOP_TEST_BOARD_COMPONENT')==='1')require __DIR__.'/order-board-transitions.php';
