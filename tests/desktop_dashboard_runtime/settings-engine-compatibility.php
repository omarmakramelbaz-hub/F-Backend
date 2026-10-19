<?php
// Original settings migration and GeneralSettings::save(), real private InnoDB.
use Illuminate\Support\Facades\DB;
use App\Models\GeneralSettings;
$application=realpath($argv[1]??'');$port=(int)($argv[2]??0);
if(!$application||$port<1024||$port>65535)throw new RuntimeException('Usage: settings-engine-compatibility.php application private-maria-port');
$profile=sys_get_temp_dir().'/desktop-settings-engine-'.bin2hex(random_bytes(8));$database='settings_engine_'.bin2hex(random_bytes(8));
foreach(['private','logs','framework/cache/data','framework/views','framework/sessions','bootstrap/cache'] as $directory)mkdir($profile.'/'.$directory,0700,true);
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$completed=false;$checks=0;
register_shutdown_function(function()use($pdo,$database,$profile,&$completed){
    $pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($profile,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($profile);
    if(!$completed){fwrite(STDERR,'Original settings engine compatibility did not complete.'.PHP_EOL);exit(1);}
});
foreach(['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_STORAGE'=>$profile,'APP_ENV'=>'testing','APP_DEBUG'=>'false',
    'APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,
    'DB_DATABASE'=>$database,'DB_USERNAME'=>'root','DB_PASSWORD'=>'','CACHE_DRIVER'=>'array','SESSION_DRIVER'=>'array','SETTINGS_CACHE_ENABLED'=>'false',
    'APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php','APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php',
    'APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;}
try{
    $app=require $application.'/desktop/bootstrap.php';
    $check=function(bool $condition,string $message)use(&$checks){if(!$condition)throw new RuntimeException($message);$checks++;echo 'PASS '.$message.PHP_EOL;};
    require_once __DIR__.'/runtime-engine.php';$engine=DesktopRuntimeEngineProof::expected($application);
    require $application.'/database/migrations/2022_08_09_145144_create_settings_table.php';(new CreateSettingsTable)->up();
    $indexes=DB::select('SHOW INDEX FROM settings');$unique=array_values(array_filter($indexes,fn($index)=>(int)$index->Non_unique===0));
    $check(count($unique)===1&&$unique[0]->Key_name==='PRIMARY','the actual captured settings table has only its original ID primary key');
    $original=[];foreach((new ReflectionClass(GeneralSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property){
        $name=$property->getName();$value=$property->getType()?->getName()==='bool'?true:'original-'.$name;
        $original[$name]=$value;DB::table('settings')->insert(['group'=>'general','name'=>$name,'locked'=>$name==='app_balance','payload'=>json_encode($value),'created_at'=>'2020-01-01 00:00:00','updated_at'=>'2020-01-01 00:00:00']);
    }
    DB::table('settings')->insert(['group'=>'private','name'=>'sentinel','locked'=>true,'payload'=>json_encode(['keep'=>'unchanged']),'created_at'=>'2020-01-01 00:00:00','updated_at'=>'2020-01-01 00:00:00']);
    $rows=fn()=>array_map(fn($row)=>(array)$row,DB::table('settings')->orderBy('id')->get()->all());$before=$rows();
    if($engine==='12.70.0'){
        DB::beginTransaction();
        $upstream=new \Spatie\LaravelSettings\SettingsRepositories\DatabaseSettingsRepository(config('settings.repositories.database'));
        $upstream->updatePropertiesPayload('general',['site_name'=>'upstream negative control','site_active'=>false]);
        $check(DB::table('settings')->count()===count($before)+2&&DB::table('settings')->where('group','general')->where('name','site_name')->count()===2,
            'the unmodified newer upstream repository reproduces duplicate-row insertion on the exact original nonunique InnoDB schema');
        DB::rollBack();$check($rows()===$before,'the isolated upstream negative control rolls back every inserted row and payload');
    }
    $identity=fn(array $values)=>array_map(fn($row)=>array_intersect_key($row,array_flip(['id','group','name','locked','created_at'])),$values);
    $settings=app(GeneralSettings::class);$check($settings->site_name===$original['site_name'],'the actual GeneralSettings class loads every original property before mutation');
    $repository=$settings->getRepository();
    $expected=$engine==='12.70.0'?'FasakhanstaDesktopLegacySettingsRepository':\Spatie\LaravelSettings\SettingsRepositories\DatabaseSettingsRepository::class;
    $check(get_class($repository)===$expected,'each engine resolves its explicit original-compatible repository without changing production dependencies');
    $settings->site_name='فسخانستا — original settings update';$settings->site_active=false;$settings->app_balance='must-not-replace-locked-value';$settings->save();
    $after=$rows();$check(count($after)===count($before)&&$identity($after)===$identity($before),'GeneralSettings::save retains every original row ID, property identity, lock flag and creation timestamp');
    $check(DB::table('settings')->where('group','general')->where('name','site_name')->value('payload')===json_encode($settings->site_name),'the original selected name updates its existing JSON property');
    $check(json_decode(DB::table('settings')->where('group','general')->where('name','site_active')->value('payload'),true)===false,'the original boolean property retains its JSON boolean type');
    $check(json_decode(DB::table('settings')->where('group','general')->where('name','app_balance')->value('payload'),true)===$original['app_balance'],'the actual settings package preserves its locked original property');
    foreach($after as $index=>$row)if(!in_array($row['name'],['site_name','site_active'],true))$check($row['payload']===$before[$index]['payload'],'saving original settings preserves the exact unrelated payload: '.$row['group'].'.'.$row['name']);
    $settings->site_name='second actual save';$settings->save();$again=$rows();
    $check(count($again)===count($before)&&$identity($again)===$identity($before),'repeated original saves cannot upsert duplicate rows into the captured nonunique schema');
    if(method_exists($repository,'updatePropertiesPayload'))$repository->updatePropertiesPayload('general',['missing_property'=>'must-not-insert']);
    else $repository->updatePropertyPayload('general','missing_property','must-not-insert');
    $check(DB::table('settings')->where('name','missing_property')->doesntExist()&&DB::table('settings')->count()===count($before),'the runtime bridge retains the original UPDATE-only missing-property behavior');
    $beforeRollback=$rows();DB::beginTransaction();$settings->site_name='rolled back original save';$settings->save();DB::rollBack();
    $check($rows()===$beforeRollback,'a surrounding real InnoDB rollback restores every original settings row and payload');
    $settings->refresh();$check($settings->site_name==='second actual save','the real package refresh hook reloads the committed original setting after rollback');
    $completed=true;echo 'SETTINGS_ENGINE_COMPATIBILITY_COMPLETE '.json_encode(['checks'=>$checks,'runtimeFramework'=>$engine,'properties'=>count($original),'rows'=>count($before),'originalMigration'=>true,'originalGeneralSettings'=>true,'duplicates'=>0],JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);}
