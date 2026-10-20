<?php
// An actual engine/alias/calendar proof. It uses an isolated in-memory SQLite
// database and private caches; it never loads the application's .env.
$application=realpath($argv[1]??'');
$expectedEngine=$argv[2]??'';
if(!$application||!is_file($application.'/desktop/bootstrap.php')||!in_array($expectedEngine,['8.83.29','12.70.0'],true))throw new RuntimeException('Usage: engine-compatibility.php application reviewed-engine');
$profile=sys_get_temp_dir().'/desktop-engine-proof-'.bin2hex(random_bytes(8));
foreach(['private','logs','framework/cache/data','framework/sessions','framework/views','bootstrap/cache'] as $directory)mkdir($profile.'/'.$directory,0700,true);
$environment=['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_STORAGE'=>$profile,'APP_ENV'=>'testing','APP_DEBUG'=>'false',
    'APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:',
    'CACHE_DRIVER'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'log',
    'APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php','APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php',
    'APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'];
foreach($environment as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;}
$completed=false;$checks=0;
register_shutdown_function(function()use(&$completed,$profile){
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($profile,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($profile);
    if(!$completed){fwrite(STDERR,'Engine compatibility proof did not complete.'.PHP_EOL);exit(1);}
});
try{
    $app=require $application.'/desktop/bootstrap.php';
    $check=function(bool $condition,string $message)use(&$checks){if(!$condition)throw new RuntimeException($message);$checks++;echo 'PASS '.$message.PHP_EOL;};
    require_once __DIR__.'/runtime-engine.php';$engine=DesktopRuntimeEngineProof::expected($application);
    $check($engine===$expectedEngine,'this CI cohort executes its explicit independent engine');
    $check($app->version()===$engine,'booted engine equals independent installed and locked metadata');
    $check(realpath((new ReflectionClass(\App\Services\Dashboard\HomeOverview::class))->getFileName())===realpath($application.'/app/Services/Dashboard/HomeOverview.php'),'calendar proof executes the captured HomeOverview class');
    $source=app(\App\Services\Dashboard\DesktopDashboardSource::class)->fingerprint();
    $check($source['framework']==='8.83.29','source binding identifies the captured contract while retaining the actual engine separately');
    $contract=json_decode(file_get_contents($application.'/desktop/source-contract.json'),true,16,JSON_THROW_ON_ERROR);
    $check(in_array($engine,$contract['supportedRuntimeFrameworks'],true),'actual engine belongs to the explicit compatibility contract');
    $kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);$aliases=$app['router']->getMiddleware();
    foreach(['permission'=>'PermissionMiddleware','role'=>'RoleMiddleware','role_or_permission'=>'RoleOrPermissionMiddleware'] as $alias=>$class){
        $expected='Spatie\\Permission\\Middlewares\\'.$class;
        $check(($aliases[$alias]??null)===$expected&&class_exists($expected),'original '.$alias.' middleware resolves before native CLI reconciliation');
    }
    $proxy=new ReflectionProperty(\App\Http\Middleware\TrustProxies::class,'headers');$proxy->setAccessible(true);
    $check($proxy->getValue(app(\App\Http\Middleware\TrustProxies::class))===94,'proxy compatibility preserves the exact captured trusted-header mask');
    $trend=new ReflectionMethod(\App\Services\Dashboard\HomeOverview::class,'trend');$trend->setAccessible(true);$overview=app(\App\Services\Dashboard\HomeOverview::class);
    foreach([['2026-10-05 06:00:00',1,'hour',24,'06:00','05:00'],['2026-10-05 06:00:00',7,'day',7,'05/10','11/10'],['2026-10-01 06:00:00',92,'month',4,'10/2026','01/2027']] as [$start,$days,$mode,$points,$first,$last]){
        $from=\Carbon\Carbon::parse($start,'Africa/Cairo');$result=$trend->invoke($overview,$from,$from->copy()->addDays($days));$rows=array_values($result['points']);
        $check($result['mode']===$mode&&count($rows)===$points&&$rows[0]['label']===$first&&end($rows)['label']===$last,'actual calendar trend retains '.$mode.' buckets and the original operating-day boundaries');
    }
    \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
    require_once $application.'/database/migrations/2022_08_05_174522_create_permission_tables.php';(new CreatePermissionTables)->up();
    foreach(['permissions','roles','model_has_permissions','model_has_roles','role_has_permissions'] as $table)$check(\Illuminate\Support\Facades\Schema::hasTable($table),'original permission migration creates '.$table.' with the actual installed permission engine');
    $check(\Illuminate\Support\Facades\Schema::hasColumn('role_has_permissions','permission_id')&&\Illuminate\Support\Facades\Schema::hasColumn('role_has_permissions','role_id'),'permission migration retains the original pivot column names');
    $role=\Spatie\Permission\Models\Role::create(['name'=>'engine-proof','guard_name'=>'admin']);
    $permissions=[];foreach(['ordinary-permission','7.5','7e0'] as $name)$permissions[]=\Spatie\Permission\Models\Permission::create(['name'=>$name,'guard_name'=>'admin']);
    $selections=new ReflectionMethod(\App\Http\Controllers\Dashboard\RolesController::class,'permissionSelections');$selections->setAccessible(true);
    $mapped=$selections->invoke(app(\App\Http\Controllers\Dashboard\RolesController::class),[(string)$permissions[0]->id,'ordinary-permission','7.5','7e0'],$role);
    $check($mapped[0]===$permissions[0]->id,'canonical string checkbox selections retain their exact integer permission identity');
    $check($mapped[1] instanceof \Spatie\Permission\Models\Permission&&$mapped[1]->id===$permissions[0]->id,'named selections resolve the actual permission within the original role guard');
    $check($mapped[2]->name==='7.5'&&$mapped[3]->name==='7e0','decimal and exponent permission names cannot be truncated into unrelated integer IDs');
    $role->syncPermissions($mapped);$names=$role->permissions()->pluck('name')->all();sort($names,SORT_STRING);
    $check($names===['7.5','7e0','ordinary-permission'],'the actual installed permission engine assigns the exact intended permission set once');
    $locale=$app->getLocale();$app->setLocale('ar');
    try{
        $check(is_string(trans('validation.array')),'the original Arabic array-validation message remains a string on both engines');
        try{app(\App\Services\Dashboard\CategoryOrdering::class)->values(['order'=>[['id'=>1,'position'=>1,'other'=>'unreviewed']]]);throw new RuntimeException('Unreviewed ordering fields were accepted.');}
        catch(\Illuminate\Validation\ValidationException $error){$check(isset($error->errors()['order.0']),'unreviewed category-order array fields retain original validation rejection rather than a framework formatting error');}
    }finally{$app->setLocale($locale);}
    (new CreatePermissionTables)->down();$completed=true;
    echo 'ENGINE_COMPATIBILITY_COMPLETE '.json_encode(['checks'=>$checks,'runtimeFramework'=>$engine,'sourceFramework'=>$source['framework'],'privateDatabase'=>true,'productionEnvironmentLoaded'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);}
