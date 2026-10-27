<?php
// Actual Laravel, original 106-table source/FK fixture and private disposable MySQL databases.
use Illuminate\Support\Facades\{DB,Crypt};
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardWorkspaceAuthority as Authority,DesktopDashboardWorkspaceBootstrap as Bootstrap,
    DesktopDashboardWorkspaceImport as Import,DesktopDashboardWorkspaceLocalAuthority as LocalAuthority,DesktopDashboardBootstrap as Json};

$application=realpath($argv[1]??'');$port=(int)($argv[2]??0);
if(!$application||$port<1024||$port>65535)throw new RuntimeException('Usage: workspace-snapshot.php application private-MySQL-port');
$password=getenv('DESKTOP_WORKSPACE_TEST_DB_PASSWORD')?:'';
$profile=sys_get_temp_dir().'/desktop-workspace-snapshot-'.bin2hex(random_bytes(8));
foreach(['app/public','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache','private'] as $dir)mkdir($profile.'/'.$dir,0700,true);
$names=['source'=>'desktop_workspace_test_'.bin2hex(random_bytes(8))];
foreach(['stage','retired','missing','zero','reject'] as $name)$names[$name]='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach($names as $name)$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$completed=false;$checks=0;
register_shutdown_function(function()use($pdo,$names,$profile,&$completed){
    foreach($names as $name)$pdo->exec('DROP DATABASE IF EXISTS `'.$name.'`');
    if(is_dir($profile)){$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($profile,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($profile);}
    if(!$completed){fwrite(STDERR,"WORKSPACE_SNAPSHOT_FIXTURE_DID_NOT_COMPLETE\n");exit(1);}
});
$serverKey='base64:'.base64_encode(random_bytes(32));$nativeKey='base64:'.base64_encode(random_bytes(32));
foreach(['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_ENABLED'=>'true','DESKTOP_DASHBOARD_WORKSPACE_ENABLED'=>'true',
    'DESKTOP_DASHBOARD_STORAGE'=>$profile,'APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>$serverKey,
    'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,'DB_DATABASE'=>$names['source'],'DB_USERNAME'=>'root','DB_PASSWORD'=>$password,
    'CACHE_DRIVER'=>'array','SESSION_DRIVER'=>'array','APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php',
    'APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php','APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php',
    'APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;}
$app=require $application.'/desktop/bootstrap.php';
set_exception_handler(function(Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);});
$check=function(bool $yes,string $message)use(&$checks){if(!$yes)throw new RuntimeException($message);$checks++;echo 'PASS '.$message.PHP_EOL;};
$reject=function(callable $work,string $message)use($check){try{$work();}catch(Throwable $error){$check(true,$message);return;}throw new RuntimeException($message.' was accepted');};
$switch=function(string $database,bool $local)use($serverKey,$nativeKey){
    config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>$local,'app.key'=>$local?$nativeKey:$serverKey]);
    DB::purge('mysql');app()->forgetInstance('encrypter');Crypt::clearResolvedInstance('encrypter');
    putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL='.($local?'true':'false'));
};
require __DIR__.'/full-schema.php';$report=json_decode(file_get_contents(__DIR__.'/fixtures/deployed-schema-20261008.json'),true,512,JSON_THROW_ON_ERROR);
DesktopFullSchemaFixture::create($report['tables']);
require_once $application.'/database/migrations/2026_10_08_130000_create_desktop_dashboard_journal.php';(new CreateDesktopDashboardJournal)->up();
require_once $application.'/database/migrations/2026_10_08_210000_create_desktop_dashboard_remote_attempts.php';(new CreateDesktopDashboardRemoteAttempts)->up();
require_once $application.'/database/migrations/2026_10_20_000002_create_desktop_dashboard_created_scopes.php';(new CreateDesktopDashboardCreatedScopes)->up();
require_once $application.'/database/migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php';(new CreateDesktopDashboardWorkspaces)->up();
DB::statement('SET FOREIGN_KEY_CHECKS=0');
foreach([[1,'admin','accepted'],[10,'vendor','accepted'],[11,'vendor','accepted'],[20,'user','accepted'],[30,'admin','accepted'],[40,'vendor','disabled']] as [$id,$type,$status])
    DB::table('users')->insert(['id'=>$id,'added_by'=>1,'name'=>'Workspace actor '.$id,'email'=>'workspace'.$id.'@test.invalid','mobile'=>(string)(1000000000+$id),
        'password'=>password_hash('Workspace-fixture-'.$id,PASSWORD_BCRYPT,['cost'=>4]),'remember_token'=>'PRIVATE_RECALLER_'.$id,
        'account_type'=>$type,'status'=>$status,'app_scope'=>'fasakhansta','balance'=>0]);
DB::table('users')->where('id',10)->update(['partner_auth_email'=>'PRIVATE_PARTNER_ALIAS']);
foreach([[100,10],[101,11],[999,40]] as [$id,$actor])DB::table('resturants')->insert(['id'=>$id,'added_by'=>1,'user_id'=>$actor,'name'=>'Workspace branch '.$id,'status'=>'opened']);
foreach((new ReflectionClass(\App\Models\GeneralSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property){
    $value=$property->getName()==='site_name'?'Workspace fixture':($property->getName()==='app_balance'?'12345.67':($property->getType()?->getName()==='bool'?true:'0'));
    DB::table('settings')->insert(['group'=>'general','name'=>$property->getName(),'locked'=>false,'payload'=>json_encode($value,JSON_UNESCAPED_UNICODE)]);
}
DB::table('settings')->insert(['group'=>'private','name'=>'server-credential','locked'=>true,'payload'=>'{"private_key":"PRIVATE_SERVER_KEY"}']);
DB::statement('SET FOREIGN_KEY_CHECKS=1');$switch($names['source'],false);
$id=(string)\Illuminate\Support\Str::uuid();$authority=app(Authority::class);
$link=$authority->enroll(['device_id'=>$id,'name'=>'One physical workspace','nonce'=>bin2hex(random_bytes(32))],User::withoutGlobalScopes()->findOrFail(10));
$snapshot=app(Bootstrap::class)->export($authority->authenticate($link['token']));
$check($snapshot['format']===2&&$snapshot['kind']==='initial-workspace-data'&&count($snapshot['tables'])===106,'group snapshot uses all original inspected production table validators');
$check($snapshot['actor_id']===10&&array_column($snapshot['workspace_manifest']['members'],'actor_id')===[1,10,11,30],'branch custody does not select or impersonate the other cached members');
$check(isset($snapshot['coverage']['modules']['accounts'],$snapshot['coverage']['modules']['settings'])
    &&$snapshot['coverage']['full_dashboard']===false,'complete source administration emits only specific account and settings proofs');
$check($snapshot['coverage']['modules']['settings']['structural_branches']===['f:100','f:101','f:999'],
    'module structural cohort retains the disabled branch identity independently of cached sign-in members');
$partial=DB::transaction(function()use($authority,$link,$snapshot){
    $audience=$authority->snapshotAudience($authority->authenticate($link['token']));$data=app(\App\Services\Dashboard\DesktopDashboardWorkspaceData::class);
    $queries=$data->queries($audience);$queries['users']=DB::table('users')->where('id',1);
    return $data->moduleCoverage($audience,$queries,$snapshot['tables']);
});
$check($partial===[],'a global witness cannot certify a filtered account query as a complete source dataset');
$userRows=array_column($snapshot['tables']['users']['rows'],null,'id');
foreach([1,10,11,30] as $actor)$check($userRows[$actor]['password']===DB::table('users')->where('id',$actor)->value('password'),'member '.$actor.' keeps its own valid password verifier');
foreach([20,40] as $actor)$check(str_starts_with($userRows[$actor]['password'],'!desktop-disabled'),'nonmember '.$actor.' never receives an offline verifier');
$check(!str_contains(Json::json($snapshot),'PRIVATE_'),'ordinary private secret/alias/recaller redaction is reused by the group snapshot');
$balance=collect($snapshot['tables']['settings']['rows'])->firstWhere('name','app_balance');
$check($balance['payload']==='"12345.67"','Owner balance is retained when Owner is a member despite branch custody');
$expected=['workspace_id'=>$id,'root_fingerprint'=>$snapshot['workspace_manifest']['root_fingerprint'],'revision'=>$snapshot['workspace_manifest']['revision'],
    'roster_sha256'=>$snapshot['workspace_manifest']['roster_sha256'],'manifest_sha256'=>hash('sha256',Authority::canonical($snapshot['workspace_manifest'])),'token_hash'=>hash('sha256',$link['token'])];
$check($snapshot['workspace_enrollment']['enrollment_hash']!==$expected['root_fingerprint'],'nonce hash and compound physical root fingerprint remain distinct');
require_once $application.'/desktop/snapshot-row-hashes.php';$wire=Json::json($snapshot);$hashes=DesktopSnapshotRowHashes::fromJson($wire);$decoded=json_decode($wire,true,512,JSON_THROW_ON_ERROR);unset($wire);
$switch($names['stage'],true);config(['desktop_dashboard.device_id'=>$id]);putenv('DESKTOP_DASHBOARD_DEVICE_ID='.$id);
$receipt=app(Import::class)->importWorkspace($decoded,$expected,$hashes);
$check($receipt['coverage']['catalog_scopes']===1,'typed group import restores the exact initial catalog overlay without changing financial BASE');
$check($serverKey!==$nativeKey&&$receipt['workspace']['manifest_sha256']===$expected['manifest_sha256'],'typed staging import installs a local MAC without the server signing key');
$verified=app(Import::class)->verifyWorkspace($receipt,$expected);
$check($verified['verified']===true&&$verified['actor_id']===10,'independent actual SQL/source/media verification retains the physical custodian');
$scope=app(LocalAuthority::class)->scopeForActor(11);
$check($scope->actor_id===11&&(int)$scope->root()->custodian_actor_id===10&&$scope->id===$id,'B receives its own scope over A shared physical database');
$check(password_verify('Workspace-fixture-11',User::withoutGlobalScopes()->findOrFail(11)->password),'B offline password verifier authenticates after A prepared once');
$saved=DB::table('users')->where('id',11)->value('password');DB::table('users')->where('id',11)->update(['password'=>password_hash('tampered',PASSWORD_BCRYPT,['cost'=>4])]);
$reject(fn()=>app(Import::class)->verifyWorkspace($receipt,$expected),'actual member verifier tampering prevents activation');DB::table('users')->where('id',11)->update(['password'=>$saved]);
$badExpected=$expected;$badExpected['revision']++;
$reject(fn()=>app(Import::class)->verifyWorkspace($receipt,$badExpected),'native expected revision cannot be ignored during independent verification');
$switch($names['reject'],true);$bad=$expected;$bad['token_hash']=str_repeat('e',64);
$reject(fn()=>app(Import::class)->importWorkspace($decoded,$bad,$hashes),'snapshot cannot supply its own trusted root bearer hash');
$check(DB::select('SHOW TABLES')===[],'rejected private expectation creates no business or authority tables');
foreach(['table','structural','witness'] as $change){
    $badSnapshot=$decoded;
    if($change==='table')$badSnapshot['coverage']['modules']['accounts']['tables']['users']['sha256']=str_repeat('e',64);
    elseif($change==='structural')$badSnapshot['coverage']['modules']['settings']['structural_branches'][]='f:404';
    else $badSnapshot['coverage']['modules']['accounts']['witness_actor_id']=10;
    $reject(fn()=>app(Import::class)->importWorkspace($badSnapshot,$expected,$hashes),'module '.$change.' tampering is rejected before staging DDL');
    $check(DB::select('SHOW TABLES')===[],'rejected module '.$change.' proof leaves the staging database untouched');
}
// The real original remote reservation engine retains B and fences every other actor.
$switch($names['source'],false);$root=app(Authority::class)->authenticate($link['token']);$roster=app(Authority::class)->manifest($root);
$members=array_column($roster['members'],null,'actor_id');$scopeB=app(Authority::class)->scope($root,11,$members[11]['grant'],$roster['revision']);
$remote=app(\App\Services\Dashboard\DesktopDashboardWorkspaceRemoteAttempts::class);
$attemptB=['id'=>(string)\Illuminate\Support\Str::uuid(),'method'=>'POST','path'=>'/admin/read/all/notification'];
$reservation=$remote->decideWorkspace($scopeB,$attemptB,false);
$check($reservation['actor_id']===11&&$reservation['device_id']===$id&&$reservation['status']==='ready','actual original remote reservation keeps B under branch-A physical custody');
$scopeA=app(Authority::class)->scope($root,10,$members[10]['grant'],$roster['revision']);$attemptA=$attemptB;$attemptA['id']=(string)\Illuminate\Support\Str::uuid();
$reject(fn()=>$remote->decideWorkspace($scopeA,$attemptA,false),'B unknown result fences another account on the same physical root');
$proofB=['actor_id'=>11,'manifest_revision'=>$roster['revision'],'grant'=>$members[11]['grant']];
$check($remote->settleWorkspace($root,$proofB,$attemptB)['status']==='cancelled','root authenticated terminal settlement cancels an unexecuted B reservation');
$remote->decideWorkspace($scopeA,$attemptA,false);$proofA=['actor_id'=>10,'manifest_revision'=>$roster['revision'],'grant'=>$members[10]['grant']];
DB::table('users')->whereIn('id',[1,10])->update(['status'=>'disabled']);app(Authority::class)->manifest($root);
$terminal=$remote->settleWorkspace($root,$proofA,$attemptA);
$check($terminal['actor_id']===10&&$terminal['status']==='cancelled'&&$terminal['capability']===null
    &&$terminal['workspace']['manifest_revision']===$proofA['manifest_revision'],'retired A terminal recovery preserves its original grant revision and cannot mint an execution capability');
$lost=$attemptA;$lost['id']=(string)\Illuminate\Support\Str::uuid();
$check($remote->settleWorkspace($root,$proofA,$lost)['status']==='cancelled','lost reservation replies receive a durable cancellation tombstone using historical A authority');
// A disabled preparer remains audit custody while B can import/open the same root.
$retired=app(Bootstrap::class)->export($root);$retiredRows=array_column($retired['tables']['users']['rows'],null,'id');
$check($retired['actor_id']===10&&array_column($retired['workspace_manifest']['members'],'actor_id')===[11,30]
    &&str_starts_with($retiredRows[10]['password'],'!desktop-disabled'),'retired custodian audit row is retained without its login verifier');
$retiredExpected=['workspace_id'=>$id,'root_fingerprint'=>$retired['workspace_manifest']['root_fingerprint'],'revision'=>$retired['workspace_manifest']['revision'],
    'roster_sha256'=>$retired['workspace_manifest']['roster_sha256'],'manifest_sha256'=>hash('sha256',Authority::canonical($retired['workspace_manifest'])),'token_hash'=>hash('sha256',$link['token'])];
$retiredWire=Json::json($retired);$retiredHashes=DesktopSnapshotRowHashes::fromJson($retiredWire);$retired=json_decode($retiredWire,true,512,JSON_THROW_ON_ERROR);unset($retiredWire);
$switch($names['retired'],true);$retiredReceipt=app(Import::class)->importWorkspace($retired,$retiredExpected,$retiredHashes);
$check(app(Import::class)->verifyWorkspace($retiredReceipt,$retiredExpected)['verified']===true,'real shared import/print binding accepts an audit-only disabled original preparer');
$check(app(LocalAuthority::class)->scopeForActor(11)->actor_id===11,'B keeps its actual scope after the original preparer is retired');
// Create legitimate A-owned catalog evidence, then delete its target and A
// without breaking a production FK. Its immutable receipt remains audit data.
$switch($names['source'],false);DB::table('users')->where('id',10)->update(['status'=>'accepted','account_type'=>'admin']);
foreach(['resturant-list','resturant-create','resturant-edit'] as $permission){
    $permissionId=DB::table('permissions')->where('name',$permission)->where('guard_name','admin')->value('id');
    if(!$permissionId)$permissionId=DB::table('permissions')->insertGetId(['name'=>$permission,'guard_name'=>'admin']);
    DB::table('model_has_permissions')->insert(['model_type'=>User::class,'model_id'=>10,'permission_id'=>$permissionId]);
}
app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
$creatorManifest=app(Authority::class)->manifest($root);$creatorMember=array_column($creatorManifest['members'],null,'actor_id')[10];
$creatorScope=app(Authority::class)->scope($root,10,$creatorMember['grant'],$creatorManifest['revision']);
require_once $application.'/database/migrations/2026_10_27_030010_create_workspace_command_authorities.php';(new CreateWorkspaceCommandAuthorities)->up();
$catalogCommand=(string)\Illuminate\Support\Str::uuid();$catalogPayload=['parameters'=>[],'values'=>['idempotency_key'=>$catalogCommand],'files'=>[],'facts'=>[]];
$catalogResult=\App\Services\Dashboard\DesktopDashboardWorkspaceExecutionContext::run($creatorScope,
    fn()=>app(\App\Services\Dashboard\DesktopDashboardJournal::class)->execute($id,$catalogCommand,10,'go-stores.store',$catalogPayload,[],function()use($catalogCommand){
        DB::table('users')->insert(['id'=>1000,'added_by'=>10,'name'=>'Audit store owner','email'=>'audit-store@test.invalid','mobile'=>'1100001000',
            'password'=>password_hash('Audit-store-fixture',PASSWORD_BCRYPT,['cost'=>4]),'account_type'=>'vendor','app_scope'=>'go_partner','status'=>'accepted',
            'balance'=>0,'created_at'=>'2026-10-27 09:00:00','updated_at'=>'2026-10-27 09:00:00']);
        DB::table('go_stores')->insert(['user_id'=>1000,'name'=>'Audit store','kind'=>'supermarket','address'=>'Fixture address','revision'=>1,
            'created_at'=>'2026-10-27 09:00:00','updated_at'=>'2026-10-27 09:00:00']);
        return ['http'=>['status'=>302,'content'=>'','type'=>'text/html','location'=>'/admin/go-stores/1000'],'success'=>'created',
            'after'=>['owner'=>(array)DB::table('users')->where('id',1000)->first(['id','added_by','name','mobile','email','account_type','app_scope','status','balance','delegate_fees','created_at','updated_at','email_verified_at','pending_vendor_id','owner_resturant_id']),
                'profile'=>(array)DB::table('go_stores')->where('user_id',1000)->first(['user_id','name','kind','address','revision','created_at','updated_at'])],
            'references'=>['menu_store_owner'=>1000,'wallet_account'=>1000,'dashboard_user'=>1000],
            'created_scope'=>['kind'=>'gs','entity'=>'menu_store_owner','id'=>1000,'command_id'=>$catalogCommand]];
    }));
$catalog=app(\App\Services\Dashboard\DesktopDashboardWorkspaceCreatedScopes::class);
DB::transaction(fn()=>$catalog->captureCentral($creatorScope,
    ['route_name'=>'go-stores.store','command_id'=>$catalogCommand,'local_result'=>$catalogResult,'local_references'=>$catalogResult['references']],
    $catalogPayload,$catalogResult,$catalogResult['references']));
DB::transaction(fn()=>$catalog->catalog($creatorScope,'gs:1000',$creatorScope->actor()));
$check(DB::table('desktop_dashboard_created_scopes')->count()===1
    &&DB::table('desktop_dashboard_ws_command_auth')->where('command_id',$catalogCommand)->value('actor_id')==10,
    'actual creator A catalog receipt retains the original accepted command authority');
DB::table('go_stores')->where('user_id',1000)->update(['created_at'=>'2021-01-01 00:00:00']);
$reject(fn()=>DB::transaction(fn()=>$catalog->catalog($creatorScope,'gs:1000',$creatorScope->actor())),'current catalog access still validates the real target identity and timestamp');
DB::table('go_stores')->where('user_id',1000)->update(['created_at'=>'2026-10-27 09:00:00']);
$currentB=app(Authority::class)->scope($root,11,array_column($creatorManifest['members'],null,'actor_id')[11]['grant'],$creatorManifest['revision']);
$reject(fn()=>DB::transaction(fn()=>$catalog->catalog($currentB,'gs:1000',$currentB->actor())),'shared catalog history does not add catalog permissions to B');
DB::table('go_stores')->where('user_id',1000)->delete();DB::table('users')->where('id',1000)->delete();
DB::table('resturants')->where('user_id',10)->update(['user_id'=>11]);DB::table('users')->where('id',10)->delete();
$missing=app(Bootstrap::class)->export($root);$missingRows=array_column($missing['tables']['users']['rows'],null,'id');
$check($missing['actor_id']===10&&!isset($missingRows[10])&&count($missing['created_scopes'])===1
    &&$missing['created_scopes'][0]['descriptor']['actor_id']===10,'deleted creator and custodian stays audit-only without a synthesized user');
$check($missing['workspace_manifest']['root_fingerprint']===$expected['root_fingerprint'],'deleting A cannot rewrite the physical root fingerprint');
$missingExpected=['workspace_id'=>$id,'root_fingerprint'=>$missing['workspace_manifest']['root_fingerprint'],'revision'=>$missing['workspace_manifest']['revision'],
    'roster_sha256'=>$missing['workspace_manifest']['roster_sha256'],'manifest_sha256'=>hash('sha256',Authority::canonical($missing['workspace_manifest'])),'token_hash'=>hash('sha256',$link['token'])];
$missingWire=Json::json($missing);$missingHashes=DesktopSnapshotRowHashes::fromJson($missingWire);$missing=json_decode($missingWire,true,512,JSON_THROW_ON_ERROR);unset($missingWire);
$switch($names['missing'],true);$missingReceipt=app(Import::class)->importWorkspace($missing,$missingExpected,$missingHashes);
$check(app(Import::class)->verifyWorkspace($missingReceipt,$missingExpected)['verified']===true
    &&!DB::table('users')->where('id',10)->exists(),'actual shared import and native verification accept an absent nonmember custodian');
$check(app(LocalAuthority::class)->scopeForActor(11)->actor_id===11&&DB::table('desktop_dashboard_created_scopes')->count()===1,
    'B opens normally while original A creation history remains in the shared overlay');
$switch($names['source'],false);$goneTerminal=$remote->settleWorkspace($root,$proofA,$attemptA);
$check($goneTerminal['actor_id']===10&&$goneTerminal['status']==='cancelled'&&$goneTerminal['capability']===null,
    'terminal bookkeeping validates historical A proof even after its user row is deleted');
// A valid nonfinancial administrator may prepare without any branch ledger.
$switch($names['source'],false);DB::table('users')->whereIn('id',[1,10,11])->update(['status'=>'disabled']);
$zeroId=(string)\Illuminate\Support\Str::uuid();$zeroLink=app(Authority::class)->enroll(['device_id'=>$zeroId,'name'=>'Nonfinancial workspace','nonce'=>bin2hex(random_bytes(32))],User::withoutGlobalScopes()->findOrFail(30));
$zero=app(Bootstrap::class)->export(app(Authority::class)->authenticate($zeroLink['token']));
$check($zero['branches']===[]&&array_column($zero['workspace_manifest']['members'],'actor_id')===[30],'nonfinancial membership has an exact empty financial union');
$zeroExpected=['workspace_id'=>$zeroId,'root_fingerprint'=>$zero['workspace_manifest']['root_fingerprint'],'revision'=>$zero['workspace_manifest']['revision'],
    'roster_sha256'=>$zero['workspace_manifest']['roster_sha256'],'manifest_sha256'=>hash('sha256',Authority::canonical($zero['workspace_manifest'])),'token_hash'=>hash('sha256',$zeroLink['token'])];
$zeroWire=Json::json($zero);$zeroHashes=DesktopSnapshotRowHashes::fromJson($zeroWire);$zero=json_decode($zeroWire,true,512,JSON_THROW_ON_ERROR);unset($zeroWire);
$switch($names['zero'],true);config(['desktop_dashboard.device_id'=>$zeroId]);putenv('DESKTOP_DASHBOARD_DEVICE_ID='.$zeroId);
$zeroReceipt=app(Import::class)->importWorkspace($zero,$zeroExpected,$zeroHashes);
$check(app(Import::class)->verifyWorkspace($zeroReceipt,$zeroExpected)['verified']===true,'strict protocol two imports and verifies an empty branch union using the original SQL/FK engine');
$check(DB::table('desktop_dashboard_devices')->where('id',$zeroId)->value('branches')==='[]','legacy compatible physical enrollment stores the empty union without inventing a branch');
$completed=true;echo $checks.' shared workspace snapshot/import checks passed using original Laravel and disposable MySQL'.PHP_EOL;
