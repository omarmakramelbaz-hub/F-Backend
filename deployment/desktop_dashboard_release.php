<?php
/** Private, pinned deployment checks. Never display settings, SQL or records. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start();
$mode = $argv[1] ?? '';
$project = $argv[2] ?? '';
$private = $argv[3] ?? '';
$tables = [
    'desktop_dashboard_commands'=>['sequence','device_id','command_id','actor_id','route_name','request_hash','command_cipher','local_result_cipher','dependencies','status','attempts','last_error','server_result_cipher','acknowledged_at','created_at','updated_at'],
    'desktop_dashboard_entities'=>['id','device_id','entity','local_id','command_id','created_at','updated_at'],
    'desktop_dashboard_devices'=>['id','actor_id','name','enrollment_hash','token_hash','enabled','branches','enrolled_at','created_at','updated_at'],
    'desktop_dashboard_local_state'=>['device_id','actor_id','snapshot_id','schema_hash','branches','coverage','state','refresh_id','refresh_token_hash','fenced_sequence','created_at','updated_at'],
    'desktop_dashboard_refreshes'=>['refresh_id','device_id','token_hash','state','result','created_at','updated_at'],
    'desktop_dashboard_archived_commands'=>['device_id','command_id','actor_id','route_name','request_hash','source_database'],
    'desktop_dashboard_remote_attempts'=>['id','device_id','actor_id','method','path','status','request_hash','response_cipher','created_at','updated_at','operation_id'],
    'desktop_dashboard_media_manifest'=>['device_id','snapshot_id','sha256','files'],
    'desktop_dashboard_source_manifest'=>['device_id','snapshot_id','sha256','source'],
];
$migrationTables = [
    '2026_10_08_130000_create_desktop_dashboard_journal'=>['desktop_dashboard_commands','desktop_dashboard_entities','desktop_dashboard_devices'],
    '2026_10_08_160000_create_desktop_dashboard_local_state'=>['desktop_dashboard_local_state','desktop_dashboard_refreshes'],
    '2026_10_08_210000_create_desktop_dashboard_archived_commands'=>['desktop_dashboard_archived_commands'],
    '2026_10_08_210000_create_desktop_dashboard_remote_attempts'=>['desktop_dashboard_remote_attempts'],
    '2026_10_08_220000_create_desktop_dashboard_media_manifest'=>['desktop_dashboard_media_manifest'],
    '2026_10_08_230000_create_desktop_dashboard_source_manifest'=>['desktop_dashboard_source_manifest'],
];
try {
    if (PHP_VERSION_ID < 80200 || realpath($project) !== $project || realpath($private) !== $private
        || !is_dir($private) || !is_writable($private) || str_starts_with($private,$project.'/')) throw new RuntimeException();
    // Framework discovery never writes the production packages/services cache.
    foreach (['APP_SERVICES_CACHE'=>'private-services.php','APP_PACKAGES_CACHE'=>'private-packages.php'] as $key=>$name) {
        $value=$private.'/'.$name; putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value;
    }
    $loader=require $project.'/vendor/autoload.php';
    $app=require $project.'/bootstrap/app.php';
    $kernel=$app->make(Illuminate\Contracts\Console\Kernel::class); $kernel->bootstrap();
    $db=Illuminate\Support\Facades\DB::connection();
    $schema=Illuminate\Support\Facades\Schema::class;
    if ($db->getDriverName()!=='mysql' || $app->version()!=='8.83.29' || !$schema::hasTable('migrations')) throw new RuntimeException();
    $checkSchema=function(bool $required)use($schema,$db,$tables,$migrationTables):void {
        foreach($migrationTables as $migration=>$members) {
            $records=$db->table('migrations')->where('migration',$migration)->get(['migration','batch']);
            $present=array_filter($members,fn($table)=>$schema::hasTable($table));
            if(count($records)>1 || (count($records)!==0 && count($present)!==count($members))
                || (count($records)===0 && count($present)!==0) || ($required && count($records)!==1)) throw new RuntimeException();
            foreach($present as $table) {
                $columns=$schema::getColumnListing($table);
                if($columns!==$tables[$table])throw new RuntimeException();
                $row=$db->selectOne('SELECT ENGINE engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$db->getDatabaseName(),$table]);
                if(!$row || $row->engine!=='InnoDB')throw new RuntimeException();
            }
        }
        $unique=[
            'desktop_dashboard_commands'=>[['sequence'],['device_id','command_id']],
            'desktop_dashboard_entities'=>[['id'],['device_id','entity','local_id']],
            'desktop_dashboard_devices'=>[['id'],['token_hash']],
            'desktop_dashboard_local_state'=>[['device_id']], 'desktop_dashboard_refreshes'=>[['refresh_id']],
            'desktop_dashboard_archived_commands'=>[['device_id','command_id']],
            'desktop_dashboard_remote_attempts'=>[['id'],['device_id','operation_id']],
            'desktop_dashboard_media_manifest'=>[['device_id']], 'desktop_dashboard_source_manifest'=>[['device_id']],
        ];
        foreach($unique as $table=>$wanted)if($schema::hasTable($table)) {
            $indexes=[]; foreach($db->select('SHOW INDEX FROM `'.$table.'`')as$i)if((int)$i->Non_unique===0)$indexes[$i->Key_name][(int)$i->Seq_in_index]=$i->Column_name;
            foreach($indexes as&$index){ksort($index);$index=array_values($index);}unset($index);
            foreach($wanted as$index)if(!in_array($index,array_values($indexes),true))throw new RuntimeException();
        }
    };
    $result=[];
    if($mode==='preflight') {
        if(config('desktop_dashboard.local',false) || config('desktop_dashboard.enabled',false))throw new RuntimeException();
        $checkSchema(false);
        foreach(['desktop_pos_devices','desktop_pos_snapshots','desktop_pos_orders','desktop_pos_operations']as$table)if(!$schema::hasTable($table))throw new RuntimeException();
        if($db->table('migrations')->where('migration','2026_10_07_210000_create_desktop_pos')->count()!==1)throw new RuntimeException();
        $paths=$loader->getPrefixesPsr4()['App\\']??[];
        if($loader->isClassMapAuthoritative() || !in_array($project.'/app',array_map('realpath',$paths),true))throw new RuntimeException();
        $route=$app->getCachedRoutesPath();
        if(!in_array($route,[$project.'/bootstrap/cache/routes.php',$project.'/bootstrap/cache/routes-v7.php'],true))throw new RuntimeException();
        if($app->getCachedConfigPath()!==$project.'/bootstrap/cache/config.php')throw new RuntimeException();
        $size=$db->selectOne('SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=?',[$db->getDatabaseName()]);
        $result=['route_cache'=>substr($route,strlen($project)+1),'config_cache'=>'bootstrap/cache/config.php','database_estimated_bytes'=>(int)$size->n];
    } elseif($mode==='backup') {
        $binary=null; foreach(['/usr/bin/mariadb-dump','/usr/bin/mysqldump','/usr/local/bin/mysqldump']as$candidate)if(is_executable($candidate)){$binary=$candidate;break;}
        if(!$binary)throw new RuntimeException();
        $config=$db->getConfig();$database=$db->getDatabaseName();
        if(!preg_match('/\A[A-Za-z0-9_$-]+\z/D',$database))throw new RuntimeException();
        $settings=['user'=>$config['username']??'','password'=>$config['password']??''];
        if(!empty($config['unix_socket']))$settings['socket']=$config['unix_socket'];
        else{$settings['host']=$config['host']??'127.0.0.1';$settings['port']=$config['port']??3306;}
        $escape=fn($value)=>'"'.strtr((string)$value,['\\'=>'\\\\','"'=>'\\"',"\n"=>'\\n',"\r"=>'\\r',"\t"=>'\\t']).'"';
        $ini="[client]\n";foreach($settings as$key=>$value){if(!is_scalar($value))throw new RuntimeException();$ini.=$key.'='.$escape($value)."\n";}
        $defaults=$private.'/database-client.cnf';$stream=fopen($defaults,'xb');if(!$stream)throw new RuntimeException();
        try {
            if(fwrite($stream,$ini)!==strlen($ini))throw new RuntimeException();fflush($stream);fclose($stream);$stream=null;chmod($defaults,0600);
            $mixed=$db->selectOne("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'",[$database]);
            $args=[$binary,'--defaults-extra-file='.$defaults,'--quick','--hex-blob','--default-character-set=utf8mb4','--routines','--events','--triggers','--no-tablespaces'];
            if((int)$mixed->n===0){$args[]='--single-transaction';$args[]='--skip-lock-tables';}else{$args[]='--lock-all-tables';}
            array_push($args,'--databases',$database);
            $dump=$private.'/database.sql';if(file_exists($dump)||is_link($dump))throw new RuntimeException();
            $process=proc_open($args,[0=>['file','/dev/null','r'],1=>['file',$dump,'x'],2=>['file',$private.'/database-dump.log','x']],$pipes);
            if(!is_resource($process)||proc_close($process)!==0||!is_file($dump)||filesize($dump)===0)throw new RuntimeException();
            chmod($dump,0600);$result=['database_backup_sha256'=>hash_file('sha256',$dump),'database_backup_bytes'=>filesize($dump),'consistency'=>(int)$mixed->n===0?'innodb-consistent-snapshot':'global-read-lock'];
        } finally {if(is_resource($stream))fclose($stream);if(is_file($defaults))unlink($defaults);}
    } elseif($mode==='config') {
        $path=$project.'/bootstrap/cache/config.php';$output=$private.'/proposed-config.php';
        if(!is_file($path)||is_link($path)||file_exists($output))throw new RuntimeException();
        $values=require $path;if(!is_array($values))throw new RuntimeException();
        $values['desktop_dashboard']=array_replace($values['desktop_dashboard']??[],['enabled'=>true,'local'=>false,'protocol'=>1]);
        $content='<?php return '.var_export($values,true).';'.PHP_EOL;
        $stream=fopen($output,'xb');if(!$stream||fwrite($stream,$content)!==strlen($content))throw new RuntimeException();fflush($stream);fclose($stream);chmod($output,0600);
        $result=['configuration_ready'=>true];
    } elseif($mode==='blade') {
        $relative=$argv[4]??'';
        if(!preg_match('#\Aresources/views/[A-Za-z0-9_./-]+\.blade\.php\z/D',$relative)||str_contains($relative,'..'))throw new RuntimeException();
        $path=$project.'/'.$relative;if(realpath($path)!==$path||is_link($path))throw new RuntimeException();
        $compiler=app('blade.compiler');$target=$compiler->getCompiledPath($path);
        if(!preg_match('#\A'.preg_quote($project,'#').'/storage/framework/views/[a-f0-9]{40}\.php\z/D',$target))throw new RuntimeException();
        $output=$private.'/compiled-'.hash('sha256',$relative).'.php';$compiled=$compiler->compileString(file_get_contents($path));
        $stream=fopen($output,'xb');if(!$stream||fwrite($stream,$compiled)!==strlen($compiled))throw new RuntimeException();fflush($stream);fclose($stream);chmod($output,0600);
        $result=['target'=>substr($target,strlen($project)+1),'compiled'=>basename($output)];
    } elseif($mode==='verify') {
        if(!config('desktop_dashboard.enabled')||config('desktop_dashboard.local'))throw new RuntimeException();
        $checkSchema(true);
        $expected=json_decode(file_get_contents($argv[4]),true,512,JSON_THROW_ON_ERROR);
        $source=app(App\Services\Dashboard\DesktopDashboardSource::class)->fingerprint();
        if(count($source)!==count($expected['after_source']))throw new RuntimeException();
        foreach($source as$key=>$value)if(!array_key_exists($key,$expected['after_source'])||$expected['after_source'][$key]!==$value)throw new RuntimeException();
        foreach(['desktop-dashboard.enroll','desktop-dashboard.session']as$name){$route=app('router')->getRoutes()->getByName($name);if(!$route||!in_array('IsAdmin',$route->gatherMiddleware(),true))throw new RuntimeException();}
        $http=$app->make(Illuminate\Contracts\Http\Kernel::class);
        foreach([['GET','bootstrap'],['POST','commands'],['POST','remote-attempts'],['GET','media']]as[$method,$suffix]){
            $request=Illuminate\Http\Request::create('/api/desktop-dashboard/'.$suffix,$method,[],[],[],['HTTP_ACCEPT'=>'application/json']);
            $response=$http->handle($request);$http->terminate($request,$response);if($response->getStatusCode()!==401)throw new RuntimeException();
        }
        $result=['source'=>$source,'authentication_checked'=>4,'enabled'=>true,'local'=>false];
    } else throw new RuntimeException();
    while(ob_get_level()>0)ob_end_clean();echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch(Throwable $error) {
    while(ob_get_level()>0)ob_end_clean();fwrite(STDERR,"DESKTOP_RELEASE_CHECK_FAILED\n");exit(1);
}
