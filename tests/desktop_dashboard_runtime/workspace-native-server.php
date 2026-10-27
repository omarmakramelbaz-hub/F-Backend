<?php
// Only private disposable fixture databases; real source, HTTP controllers, password provider and journal.
require __DIR__.'/workspace-native-fixture.php';
use Illuminate\Support\Facades\{DB,Schema};
use WorkspaceNativeServerFixture as Fixture;

Fixture::need(PHP_SAPI==='cli');$action=$argv[1]??'';
if($action==='prepare'){
    Fixture::need(count($argv)===7);$application=realpath($argv[2]);$port=filter_var($argv[3],FILTER_VALIDATE_INT);
    $root=Fixture::root($argv[4]);$origin=$argv[5];Fixture::origin($origin);$revision=$argv[6];
    Fixture::need(is_string($application)&&is_file($application.'/desktop/bootstrap.php')&&is_int($port)&&$port>=1024&&$port<=65535
        &&(bool)preg_match('/^[a-f0-9]{40}$/D',$revision)&&!file_exists($root.'/server-config.json')&&!file_exists($root.'/server-storage'));
    $application=Fixture::path($application);$storage=$root.'/server-storage';
    foreach(['app/public','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache','private'] as $directory)
        Fixture::need(mkdir($storage.'/'.$directory,0700,true));
    $database='desktop_workspace_native_'.substr(basename($root),-16);
    $environment=['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_ENABLED'=>'true','DESKTOP_DASHBOARD_WORKSPACE_ENABLED'=>'true',
        'DESKTOP_DASHBOARD_WORKSPACE_LOCAL'=>'false','DESKTOP_DASHBOARD_STORAGE'=>$storage,'APP_ENV'=>'testing','APP_DEBUG'=>'false',
        'APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'APP_URL'=>$origin,'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1',
        'DB_PORT'=>(string)$port,'DB_DATABASE'=>$database,'DB_USERNAME'=>'root','DB_PASSWORD'=>getenv('DESKTOP_WORKSPACE_TEST_DB_PASSWORD')?:'',
        'CACHE_DRIVER'=>'array','SESSION_DRIVER'=>'file','SESSION_SECURE_COOKIE'=>'true','SESSION_DOMAIN'=>'null',
        'QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'log','APP_CONFIG_CACHE'=>$storage.'/bootstrap/cache/config.php',
        'APP_PACKAGES_CACHE'=>$storage.'/bootstrap/cache/packages.php','APP_SERVICES_CACHE'=>$storage.'/bootstrap/cache/services.php',
        'APP_ROUTES_CACHE'=>$storage.'/bootstrap/cache/routes.php','VIEW_COMPILED_PATH'=>$storage.'/framework/views'];
    $config=['format'=>2,'kind'=>'go-erp-workspace-native-server','root'=>$root,'markerSha256'=>hash('sha256',Fixture::MARKER),
        'application'=>$application,'database'=>$database,'dbPort'=>$port,'serverStorage'=>$storage,'origin'=>$origin,
        'sourceRevision'=>$revision,'syntheticPublicationSequence'=>1,'credentials'=>[],'environment'=>$environment];
    $pdo=Fixture::pdo($config,false);$query=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$query->execute([$database]);
    Fixture::need((int)$query->fetchColumn()===0,'WORKSPACE_NATIVE_DATABASE_ALREADY_EXISTS');
    $pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try{
        Fixture::boot($config);
        DB::statement('CREATE TABLE go_erp_workspace_native_fixture (root_sha256 CHAR(64) NOT NULL,marker_sha256 CHAR(64) NOT NULL,source_revision CHAR(40) NOT NULL) ENGINE=InnoDB');
        DB::table('go_erp_workspace_native_fixture')->insert(['root_sha256'=>hash('sha256',$root),'marker_sha256'=>hash('sha256',Fixture::MARKER),'source_revision'=>$revision]);
        require __DIR__.'/full-schema.php';$report=json_decode(file_get_contents(__DIR__.'/fixtures/deployed-schema-20261008.json'),true,512,JSON_THROW_ON_ERROR);
        DesktopFullSchemaFixture::create($report['tables']);
        foreach([
            '2026_10_08_130000_create_desktop_dashboard_journal.php'=>'CreateDesktopDashboardJournal',
            '2026_10_08_210000_create_desktop_dashboard_remote_attempts.php'=>'CreateDesktopDashboardRemoteAttempts',
            '2026_10_17_000001_create_crm_customer_directory.php'=>'CreateCrmCustomerDirectory',
            '2026_10_20_000002_create_desktop_dashboard_created_scopes.php'=>'CreateDesktopDashboardCreatedScopes',
            '2026_10_27_030000_create_desktop_dashboard_workspaces.php'=>'CreateDesktopDashboardWorkspaces',
            '2026_10_27_030010_create_workspace_command_authorities.php'=>'CreateWorkspaceCommandAuthorities',
        ] as $file=>$class){require_once $application.'/database/migrations/'.$file;(new $class)->up();}
        DB::statement('ALTER TABLE branch_operation_commands ADD UNIQUE KEY branch_operation_request_unique (branch,actor_id,request_key)');
        DB::statement('ALTER TABLE branch_customers ADD UNIQUE KEY branch_customer_phone_unique (branch,phone_key)');
        // Seed only synthetic credentials and original relationships. Foreign keys remain enabled.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach([[1,'admin',null,'all'],[10,'vendor',null,'f:100'],[11,'resturant_owner',100,'f:100'],[12,'vendor',null,'f:101'],[30,'admin',null,'all']] as [$id,$role,$parent,$branch]){
            $email='workspace'.$id.'@test.invalid';$password='Workspace-fixture-'.$id;
            DB::table('users')->insert(['id'=>$id,'added_by'=>1,'name'=>'Workspace native '.$id,'email'=>$email,'mobile'=>(string)(1000000000+$id),
                'password'=>password_hash($password,PASSWORD_BCRYPT,['cost'=>4]),'account_type'=>$role,'app_scope'=>'fasakhansta','status'=>'accepted',
                'owner_resturant_id'=>$parent,'balance'=>0,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            $config['credentials'][]=['actorId'=>$id,'email'=>$email,'password'=>$password,'role'=>$role,'branch'=>$branch];
        }
        foreach([[100,10],[101,12]] as [$id,$owner])DB::table('resturants')->insert(['id'=>$id,'added_by'=>1,'user_id'=>$owner,'name'=>'Workspace native branch '.$id,
            'status'=>'opened','parent_id'=>null,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        foreach(['order-list','order-create','order-edit','order-delete','resturant-list','resturant-create','resturant-edit','setting-list','admin-list','admin-edit'] as $index=>$name)
            DB::table('permissions')->insert(['id'=>$index+1,'name'=>$name,'guard_name'=>'admin']);
        DB::table('roles')->insert(['id'=>1,'name'=>'Super Admin','guard_name'=>'admin']);
        foreach([1,30] as $id)DB::table('model_has_roles')->insert(['model_type'=>\App\Models\User::class,'model_id'=>$id,'role_id'=>1]);
        foreach(DB::table('permissions')->pluck('id') as $id)DB::table('role_has_permissions')->insert(['role_id'=>1,'permission_id'=>$id]);
        foreach((new ReflectionClass(\App\Models\GeneralSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property){
            $type=$property->getType()?->getName();$name=$property->getName();$value=$name==='site_name'?'Workspace native fixture':($name==='app_balance'?'12345.67':($type==='bool'?false:($type==='int'?0:($type==='float'?0.0:'0'))));
            DB::table('settings')->insert(['group'=>'general','name'=>$name,'locked'=>false,'payload'=>json_encode($value,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)]);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $config['sourceFingerprint']=app(\App\Services\Dashboard\DesktopDashboardSource::class)->fingerprint();
        $handle=fopen($root.'/server-config.json','xb');Fixture::need(is_resource($handle));@chmod($root.'/server-config.json',0600);
        try{$bytes=json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
            Fixture::need(fwrite($handle,$bytes)===strlen($bytes)&&fflush($handle));}finally{fclose($handle);}
        echo json_encode(['ok'=>true,'config'=>$root.'/server-config.json','database'=>$database,'sourceFingerprint'=>$config['sourceFingerprint']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    }catch(Throwable $error){$pdo->exec('DROP DATABASE `'.$database.'`');Fixture::removeStorage($config);throw $error;}
    exit;
}
Fixture::need(count($argv)===3&&in_array($action,['inspect','cleanup'],true));$config=Fixture::config($argv[2]);
$pdo=Fixture::pdo($config);Fixture::assertDatabase($pdo,$config);
if($action==='cleanup'){
    $pdo=null;$admin=Fixture::pdo($config,false);$admin->exec('DROP DATABASE `'.$config['database'].'`');Fixture::removeStorage($config);
    echo json_encode(['ok'=>true,'database_removed'=>true,'storage_removed'=>true],JSON_THROW_ON_ERROR).PHP_EOL;exit;
}
Fixture::boot($config);$workspaces=[];
foreach(DB::table('desktop_dashboard_workspaces')->orderBy('id')->get() as $row)$workspaces[]=['workspace_id'=>$row->id,
    'custodian_actor_id'=>(int)$row->custodian_actor_id,'manifest_revision'=>(int)$row->manifest_revision,
    'root_fingerprint'=>app(\App\Services\Dashboard\DesktopDashboardWorkspaceAuthority::class)->rootFingerprint($row)];
echo json_encode(['format'=>2,'kind'=>'go-erp-workspace-native-server-inspection','workspaceCount'=>count($workspaces),
    'custodianActorId'=>count($workspaces)===1?$workspaces[0]['custodian_actor_id']:null,'workspaces'=>$workspaces,
    'commands'=>DB::table('desktop_dashboard_commands')->orderBy('sequence')->get(['command_id','actor_id','sequence','status','route_name'])->map(fn($r)=>
        ['command_id'=>$r->command_id,'actor_id'=>(int)$r->actor_id,'sequence'=>(int)$r->sequence,'status'=>$r->status,'route_name'=>$r->route_name])->all(),
    'customers'=>DB::table('branch_customers')->orderBy('id')->get(['id','actor_id','phone_key','name','branch'])->map(fn($r)=>
        ['id'=>(int)$r->id,'actor_id'=>(int)$r->actor_id,'phone_key'=>$r->phone_key,'name'=>$r->name,'branch'=>$r->branch])->all(),
    'sourceRevision'=>$config['sourceRevision'],'sourceFingerprint'=>$config['sourceFingerprint']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
