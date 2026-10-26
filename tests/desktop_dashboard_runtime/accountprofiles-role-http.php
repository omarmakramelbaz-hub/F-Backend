<?php
/** Actual PHP/Laravel/MariaDB proof; every account, credential and row is synthetic.
 * Usage: php accountprofiles-role-http.php generated-application private-maria-port source-revision
 * No legacy fixture, replacement provider, test route or permission bypass is loaded.
 */
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardBootstrap,DesktopDashboardImport,DesktopDashboardSource,DesktopDashboardPrintLedgerSchema};

/** Restore only an explicitly opted-in, byte-bound fixture archive after env sanitization. */
function desktopAccountProfilesCompactAuthority(string $application,array $manifest,bool $enabled,$requested): ?string
{
    if(!$enabled)return null;
    $marker=$manifest['compactVendor']??null;$archive=$application.'/google-services.phar';$canonical=realpath($archive);
    if(!is_array($marker)||($marker['kind']??null)!=='go-erp-compact-vendor'
        ||($marker['sourceRevision']??null)!==($manifest['sourceRevision']??null)
        ||!is_string($marker['archiveSha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$marker['archiveSha256'])
        ||!is_int($marker['archiveBytes']??null)||$marker['archiveBytes']<1||$marker['archiveBytes']>4*1024**3
        ||!is_string($requested)||!is_string($canonical)||!is_file($archive)||is_link($archive)||is_link($requested)
        ||realpath($requested)!==$canonical||filesize($archive)!==$marker['archiveBytes'])
        throw new RuntimeException('Compact fixture archive authority is invalid.');
    $actual=hash_file('sha256',$archive);
    if(!is_string($actual)||!hash_equals($marker['archiveSha256'],$actual))
        throw new RuntimeException('Compact fixture archive bytes differ.');
    return $canonical;
}

if(($argv[1]??null)==='--compact-vendor-authority-self-test'){
    $root=sys_get_temp_dir().'/desktop-compact-authority-'.bin2hex(random_bytes(8));mkdir($root,0700);
    $file=$root.'/google-services.phar';$other=$root.'/other.phar';$bytes='synthetic compact fixture only';
    file_put_contents($file,$bytes);file_put_contents($other,'unrelated fixture');$checks=0;
    try{
        $manifest=['sourceRevision'=>str_repeat('a',40),'compactVendor'=>['kind'=>'go-erp-compact-vendor',
            'sourceRevision'=>str_repeat('a',40),'archiveSha256'=>hash('sha256',$bytes),'archiveBytes'=>strlen($bytes)]];
        if(desktopAccountProfilesCompactAuthority($root,$manifest,false,$other)!==null)throw new RuntimeException('Disabled compact authority was adopted.');$checks++;
        if(desktopAccountProfilesCompactAuthority($root,$manifest,true,$file)!==realpath($file))throw new RuntimeException('Bound compact authority was rejected.');$checks++;
        $badHash=$manifest;$badHash['compactVendor']['archiveSha256']=str_repeat('f',64);
        $badKind=$manifest;$badKind['compactVendor']['kind']='go-erp-compact-vendor-pins';
        $badSource=$manifest;$badSource['compactVendor']['sourceRevision']=str_repeat('b',40);
        $badSize=$manifest;$badSize['compactVendor']['archiveBytes']++;
        foreach([[$manifest,false],[$manifest,$other],[$badHash,$file],[$badKind,$file],[$badSource,$file],[$badSize,$file]] as [$value,$requested]){
            $rejected=false;try{desktopAccountProfilesCompactAuthority($root,$value,true,$requested);}catch(RuntimeException $error){$rejected=true;}
            if(!$rejected)throw new RuntimeException('Unbound compact fixture authority was accepted.');$checks++;
        }
    }finally{unlink($file);unlink($other);rmdir($root);}
    echo 'COMPACT_VENDOR_AUTHORITY_SELF_TEST_COMPLETE '.$checks.PHP_EOL;exit;
}

(function()use($argv){
    $application=realpath($argv[1]??'');$port=filter_var($argv[2]??null,FILTER_VALIDATE_INT);$revision=$argv[3]??'';
    if(!$application||!$port||$port<1024||$port>65535||!preg_match('/^[a-f0-9]{40}$/D',$revision))
        throw new RuntimeException('Expected generated application, disposable loopback MariaDB port and exact source revision.');
    $source=dirname(__DIR__,2);$manifestPath=dirname($application).'/manifest.json';
    $manifest=json_decode(file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
    if(($manifest['sourceRevision']??null)!==$revision||($manifest['fullDashboard']??null)!==false
        ||!is_array($manifest['sourceHashes']??null)||!$manifest['sourceHashes']||file_exists($application.'/.env'))
        throw new RuntimeException('Requires the exact environment-free preview runtime manifest.');
    $compactEnabled=($argv[4]??null)==='--compact-vendor';
    if(isset($argv[4])&&!$compactEnabled||count($argv)>5)throw new RuntimeException('Unknown account profile fixture option.');
    $compactArchive=desktopAccountProfilesCompactAuthority($application,$manifest,$compactEnabled,getenv('DESKTOP_DASHBOARD_VENDOR_PHAR'));
    $command=static function(array $arguments)use($source): string{
        $process=proc_open($arguments,[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,$source,null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new RuntimeException('Cannot start exact-source verification.');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($process)!==0)throw new RuntimeException('Exact-source verification failed: '.$err);return $out;
    };
    $verifySource=static function()use($application,$manifest): bool{
        $boundary=rtrim($application,'/\\').DIRECTORY_SEPARATOR;
        foreach($manifest['sourceHashes'] as $name=>$hash){
            if(!is_string($name)||$name===''||preg_match('//u',$name)!==1||str_contains($name,'\\')||str_contains($name,':')
                ||preg_match('/\p{Cc}/u',$name)||array_filter(explode('/',$name),static fn($part)=>$part===''||$part==='.'||$part==='..'))return false;
            $file=$application.'/'.$name;$resolved=realpath($file);
            if(!$resolved||!str_starts_with($resolved,$boundary)||!is_file($file)||is_link($file)||hash_file('sha256',$file)!==$hash)return false;
        }
        return !file_exists($application.'/.env');
    };
    if(!$verifySource())throw new RuntimeException('Captured application bytes differ before the account proof.');
    $helperHashes=[];
    foreach(['tests/desktop_dashboard_runtime/accountprofiles-role-http.php','tests/desktop_dashboard_runtime/full-schema.php',
        'tests/desktop_dashboard_runtime/fixtures/deployed-schema-20261008.json'] as $name){
        $helperHashes[$name]=hash_file('sha256',$source.'/'.$name);
        if(getenv('CI')==='true'&&hash('sha256',$command(['git','show',$revision.':'.$name]))!==$helperHashes[$name])
            throw new RuntimeException('Account proof helper differs from the exact source revision.');
    }
    $profile=sys_get_temp_dir().'/go-erp-accountprofiles-'.bin2hex(random_bytes(8));
    $central='desktop_accountprofiles_'.bin2hex(random_bytes(8));$databases=[];$devices=[];$webs=[];$pdo=null;$app=null;
    $effects='fasakhansta_dashboard_effects';$effectsOwned=false;$effectsBefore=null;$checks=0;$completed=false;$cleaned=false;
    $cleanupFlags=['httpServersStopped'=>false,'privateDatabasesDropped'=>false,'privateStorageRemoved'=>false,'existingEffectsPreserved'=>false];
    $check=static function(bool $condition,string $label)use(&$checks): void{if(!$condition)throw new RuntimeException($label);$checks++;};
    $rowProof=static function(PDO $pdo,string $database,array $tables): array{
        $proof=[];sort($tables,SORT_STRING);
        foreach($tables as $table){
            if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D',$table))throw new RuntimeException('Unsafe fixture table identifier.');
            $rows=$pdo->query('SELECT * FROM `'.$database.'`.`'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as &$row)ksort($row,SORT_STRING);unset($row);
            $encoded=array_map(static fn($row)=>json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$rows);sort($encoded,SORT_STRING);
            $proof[$table]=['rows'=>count($rows),'sha256'=>hash('sha256',json_encode($encoded,JSON_THROW_ON_ERROR))];
        }
        return ['tables'=>count($proof),'rows'=>array_sum(array_column($proof,'rows')),'sha256'=>hash('sha256',json_encode($proof,JSON_THROW_ON_ERROR))];
    };
    $remove=static function(string $root)use(&$remove): void{
        if(is_link($root)||is_file($root)){if(!unlink($root))throw new RuntimeException('Cannot remove private fixture file.');return;}
        if(!is_dir($root))return;
        foreach(new FilesystemIterator($root,FilesystemIterator::SKIP_DOTS) as $entry)$remove($entry->getPathname());
        if(!rmdir($root))throw new RuntimeException('Cannot remove private fixture directory.');
    };
    $cleanup=static function()use(&$cleaned,&$cleanupFlags,&$webs,&$pdo,&$app,&$databases,&$devices,&$effectsOwned,&$effectsBefore,$effects,$profile,$remove,$rowProof): void{
        if($cleaned)return;$cleaned=true;$stopped=true;
        foreach($webs as &$web){
            if(!is_resource($web))continue;
            $state=proc_get_status($web);
            if($state['running'])proc_terminate($web);
            for($attempt=0;$attempt<100&&$state['running'];$attempt++){usleep(20000);$state=proc_get_status($web);}
            if($state['running']){proc_terminate($web,9);for($attempt=0;$attempt<100&&$state['running'];$attempt++){usleep(20000);$state=proc_get_status($web);}}
            if($state['running']){$stopped=false;continue;}
            proc_close($web);$web=null;
        }unset($web);
        $cleanupFlags['httpServersStopped']=$stopped;
        if(!$stopped)throw new RuntimeException('Private HTTP child did not stop before database cleanup.');
        $errors=[];
        if($app){foreach(DB::getConnections() as $connection){
            try{if($connection->transactionLevel())$connection->rollBack(0);$connection->disconnect();}
            catch(Throwable $error){$errors[]='Application connection cleanup failed.';}
        }}
        if($pdo){
            try{
                if($effectsOwned){$pdo->exec('DROP DATABASE IF EXISTS `'.$effects.'`');$cleanupFlags['existingEffectsPreserved']=true;}
                elseif($effectsBefore!==null){
                    if($devices){$slots=implode(',',array_fill(0,count($devices),'?'));
                        foreach(['print_ledger_intents','print_ledger_commands','print_ledger_reprints','print_ledger_scopes'] as $table){
                            $statement=$pdo->prepare('DELETE FROM `'.$effects.'`.`'.$table.'` WHERE device_id IN ('.$slots.')');$statement->execute($devices);
                        }
                    }
                    $tables=$pdo->query('SHOW TABLES FROM `'.$effects.'`')->fetchAll(PDO::FETCH_COLUMN);
                    $cleanupFlags['existingEffectsPreserved']=$rowProof($pdo,$effects,$tables)===$effectsBefore;
                }else $cleanupFlags['existingEffectsPreserved']=true;
            }catch(Throwable $error){$errors[]='Private effect scope cleanup failed.';}
            foreach($databases as $database)try{$pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`');}
                catch(Throwable $error){$errors[]='Private database removal failed.';}
            try{
                $remaining=0;foreach($databases as $database){$query=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$query->execute([$database]);$remaining+=(int)$query->fetchColumn();}
                $cleanupFlags['privateDatabasesDropped']=$remaining===0;
            }catch(Throwable $error){$errors[]='Private database removal could not be verified.';}
        }else $cleanupFlags['privateDatabasesDropped']=true;
        try{$remove($profile);$cleanupFlags['privateStorageRemoved']=!file_exists($profile);}
        catch(Throwable $error){$errors[]='Private fixture storage cleanup failed.';}
        if($errors)throw new RuntimeException(implode(' ',$errors));
    };
    register_shutdown_function(static function()use(&$completed,$cleanup): void{
        if($completed)return;
        try{$cleanup();}catch(Throwable $error){fwrite(STDERR,'Account profile cleanup failed: '.$error->getMessage().PHP_EOL);}
        fwrite(STDERR,'Synthetic account profile HTTP fixture did not complete.'.PHP_EOL);exit(1);
    });
    try{
        foreach(array_keys(getenv()) as $name)if(preg_match('/^(?:APP_|DB_|SESSION_|CACHE_|DESKTOP_)/',$name)){putenv($name);unset($_ENV[$name],$_SERVER[$name]);}
        $storage=$profile.'/central/storage';
        $makeStorage=static function(string $root): void{foreach(['app/public','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache','private'] as $part)if(!mkdir($root.'/'.$part,0700,true)&&!is_dir($root.'/'.$part))throw new RuntimeException('Cannot create private account storage.');};
        $makeStorage($storage);$key='base64:'.base64_encode(random_bytes(32));
        $environment=['APP_ENV'=>'desktop','APP_DEBUG'=>'false','APP_URL'=>'http://127.0.0.1:43140','APP_KEY'=>$key,'JWT_SECRET'=>bin2hex(random_bytes(32)),
            'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,'DB_DATABASE'=>$central,'DB_USERNAME'=>'root','DB_PASSWORD'=>'',
            'CACHE_DRIVER'=>'file','SESSION_DRIVER'=>'file','SESSION_DOMAIN'=>'','SESSION_SECURE_COOKIE'=>'false','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'log',
            'DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_ENABLED'=>'true','DESKTOP_DASHBOARD_STORAGE'=>$storage,
            'DESKTOP_DASHBOARD_AUTH_STORAGE'=>$profile.'/central/auth','APP_CONFIG_CACHE'=>$storage.'/bootstrap/cache/config.php',
            'APP_PACKAGES_CACHE'=>$storage.'/bootstrap/cache/packages.php','APP_SERVICES_CACHE'=>$storage.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$storage.'/bootstrap/cache/routes.php'];
        // All inherited DESKTOP_* settings were removed above. Preserve only
        // the explicitly opted-in path bound to this exact fixture's manifest.
        if($compactArchive!==null)$environment['DESKTOP_DASHBOARD_VENDOR_PHAR']=$compactArchive;
        foreach($environment as $name=>$value){putenv($name.'='.$value);$_ENV[$name]=$_SERVER[$name]=$value;}
        $pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $exists=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$exists->execute([$effects]);$effectsOwned=(int)$exists->fetchColumn()===0;
        if(!$effectsOwned)$effectsBefore=$rowProof($pdo,$effects,$pdo->query('SHOW TABLES FROM `'.$effects.'`')->fetchAll(PDO::FETCH_COLUMN));
        $pdo->exec('CREATE DATABASE `'.$central.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$databases[]=$central;
        $app=require $application.'/desktop/bootstrap.php';
        $check(config('desktop_dashboard.local')===true&&get_class(app(DesktopDashboardSource::class))===DesktopDashboardSource::class,'Original private runtime services were replaced.');
        $check(app()->version()===($manifest['runtimeFramework']??null),'Actual Laravel engine differs from the captured native runtime.');
        if(!$effectsOwned)app(DesktopDashboardPrintLedgerSchema::class)->verify(DB::connection());
        $check(app(DesktopDashboardSource::class)->fingerprint()===$manifest['sourceFingerprint'],'Original source fingerprint differs from the captured manifest.');
        require __DIR__.'/full-schema.php';
        $report=json_decode(file_get_contents(__DIR__.'/fixtures/deployed-schema-20261008.json'),true,512,JSON_THROW_ON_ERROR);
        $check(count($report['tables'])===106,'Full inspected schema fixture is not106 tables.');DesktopFullSchemaFixture::create($report['tables']);
        $check(count(DB::select('SHOW TABLES'))===106,'Disposable central schema is not106 tables.');
        require_once $application.'/database/migrations/2026_10_08_130000_create_desktop_dashboard_journal.php';(new CreateDesktopDashboardJournal)->up();
        require_once $application.'/database/migrations/2026_10_20_000002_create_desktop_dashboard_created_scopes.php';(new CreateDesktopDashboardCreatedScopes)->up();
        $accounts=[
            1=>['label'=>'Owner','email'=>'owner@accountprofiles.test.invalid','password'=>'SyntheticOwner123','account_type'=>'admin','branches'=>['f:100','f:101'],'redirect'=>'/admin/dashboard'],
            30=>['label'=>'Admin','email'=>'admin@accountprofiles.test.invalid','password'=>'SyntheticAdmin123','account_type'=>'admin','branches'=>['f:100','f:101'],'redirect'=>'/admin/dashboard'],
            10=>['label'=>'Branch','email'=>'branch@accountprofiles.test.invalid','password'=>'SyntheticBranch123','account_type'=>'vendor','branches'=>['f:100'],'redirect'=>'/admin/applies-orders'],
            11=>['label'=>'OtherBranch','email'=>'other@accountprofiles.test.invalid','password'=>'SyntheticOther123','account_type'=>'vendor','branches'=>['f:101'],'redirect'=>'/admin/applies-orders'],
        ];
        foreach($accounts as $id=>$account)DB::table('users')->insert(['id'=>$id,'added_by'=>null,'name'=>'Synthetic '.$account['label'],'email'=>$account['email'],
            'mobile'=>'12000000'.str_pad((string)$id,2,'0',STR_PAD_LEFT),'password'=>password_hash($account['password'],PASSWORD_BCRYPT,['cost'=>(int)config('hashing.bcrypt.rounds')]),
            'remember_token'=>str_repeat((string)($id%10),60),'account_type'=>$account['account_type'],'status'=>'accepted','app_scope'=>'fasakhansta','balance'=>0,
            'created_at'=>'2000-01-01 00:00:00','updated_at'=>'2000-01-01 00:00:00']);
        DB::table('users')->update(['added_by'=>1]);
        DB::table('resturants')->insert([['id'=>100,'added_by'=>1,'user_id'=>10,'name'=>'Synthetic Branch100','status'=>'opened','address'=>'Synthetic100'],
            ['id'=>101,'added_by'=>1,'user_id'=>11,'name'=>'Synthetic Branch101','status'=>'opened','address'=>'Synthetic101']]);
        $permission=\Spatie\Permission\Models\Permission::create(['name'=>'order-list','guard_name'=>'admin']);
        $ownerRole=\Spatie\Permission\Models\Role::create(['name'=>'Super Admin','guard_name'=>'admin']);$ownerRole->givePermissionTo($permission);
        User::withoutGlobalScopes()->findOrFail(1)->assignRole($ownerRole);User::withoutGlobalScopes()->findOrFail(30)->givePermissionTo($permission);
        foreach((new ReflectionClass(\App\Models\GeneralSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property)
            DB::table('settings')->insert(['group'=>'general','name'=>$property->getName(),'locked'=>false,'payload'=>json_encode($property->getName()==='site_name'?'Synthetic Account Profiles':($property->getType()?->getName()==='bool'?true:'0'),JSON_THROW_ON_ERROR)]);
        $centralBefore=$rowProof($pdo,$central,array_keys($report['tables']));$proofs=[];
        foreach([1,30,10] as $actorId){
            $account=$accounts[$actorId];$stage='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));$actorStorage=$profile.'/actor-'.$actorId.'/storage';$makeStorage($actorStorage);
            $device=(string)\Illuminate\Support\Str::uuid();$devices[]=$device;
            config(['database.connections.mysql.database'=>$central,'desktop_dashboard.local'=>false,'desktop_dashboard.device_id'=>null]);DB::purge();
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            $actor=User::withoutGlobalScopes()->findOrFail($actorId);
            $check($actor->can('order-list')===($actorId!==10),'Original order-list permission differs before enrollment.');
            if($actorId===30)$check(!$actor->hasRole('Super Admin')&&$actor->getAllPermissions()->pluck('name')->all()===['order-list'],'Limited Admin accidentally acquired owner authority.');
            $link=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$device,'name'=>'Synthetic '.$account['label'],'nonce'=>bin2hex(random_bytes(32))],$actor);
            $snapshot=app(DesktopDashboardBootstrap::class)->export(app(DesktopDashboardDevices::class)->device($link['token']));
            $check($snapshot['actor_id']===$actorId&&$snapshot['device_id']===$device&&count($snapshot['tables'])===106,'Original enrolled snapshot identity/schema differs.');
            $branches=$snapshot['branches'];sort($branches,SORT_STRING);$check($branches===$account['branches'],'Snapshot has another account branch scope.');
            $restaurants=array_map('intval',array_column($snapshot['tables']['resturants']['rows'],'id'));sort($restaurants,SORT_NUMERIC);
            $check($restaurants===($actorId===10?[100]:[100,101]),'Snapshot restaurant rows leak or omit an enrolled branch.');
            $check($snapshot['source']===$manifest['sourceFingerprint'],'Snapshot bypassed original source binding.');
            foreach($snapshot['tables']['users']['rows'] as $user){
                $expected=$accounts[(int)$user['id']];
                $check($user['remember_token']===null,'Snapshot exposes a central remember token.');
                if((int)$user['id']===$actorId)$check(password_verify($expected['password'],$user['password']),'Enrolled actor password no longer matches original login.');
                else $check(str_starts_with($user['password'],'!desktop-disabled-')&&!password_verify($expected['password'],$user['password']),'Another actor password was not masked.');
            }
            $pdo->exec('CREATE DATABASE `'.$stage.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$databases[]=$stage;
            config(['database.connections.mysql.database'=>$stage,'desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>$device]);DB::purge();
            $app->useStoragePath($actorStorage);config(['filesystems.disks.public.root'=>$actorStorage.'/app/public']);
            $import=app(DesktopDashboardImport::class)->import($snapshot);
            $check(app(DesktopDashboardImport::class)->verify($import)['verified']===true,'Original scoped import did not verify.');
            $credentials=static fn()=>DB::table('users')->orderBy('id')->get(['id','password','remember_token'])->map(static fn($row)=>(array)$row)->all();
            $credentialBefore=$credentials();$reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
            $check(is_resource($reservation),'Cannot reserve a private HTTP port.');$httpPort=(int)substr(strrchr(stream_socket_get_name($reservation,false),':'),1);fclose($reservation);
            $origin='http://127.0.0.1:'.$httpPort;$browserToken=bin2hex(random_bytes(32));$env=getenv();
            foreach(['DB_DATABASE'=>$stage,'DESKTOP_DASHBOARD_STORAGE'=>$actorStorage,'DESKTOP_DASHBOARD_AUTH_STORAGE'=>dirname($actorStorage).'/auth',
                'DESKTOP_DASHBOARD_DEVICE_ID'=>$device,'APP_URL'=>$origin,'DESKTOP_DASHBOARD_ORIGIN'=>$origin,'DESKTOP_DASHBOARD_TOKEN'=>$browserToken,
                'DESKTOP_DASHBOARD_CONTROL_TOKEN'=>bin2hex(random_bytes(32))] as $name=>$value)$env[$name]=$value;
            foreach(['APP_CONFIG_CACHE'=>'config.php','APP_PACKAGES_CACHE'=>'packages.php','APP_SERVICES_CACHE'=>'services.php','APP_ROUTES_CACHE'=>'routes.php'] as $name=>$file)$env[$name]=$actorStorage.'/bootstrap/cache/'.$file;
            $web=proc_open([PHP_BINARY,'-d','memory_limit=256M','-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],
                [['pipe','r'],['file',dirname($actorStorage).'/http.log','a'],['file',dirname($actorStorage).'/http.log','a']],$pipes,$application,$env);
            $check(is_resource($web),'Cannot start original private PHP router.');fclose($pipes[0]);$webs[]=$web;$cookies=[];
            $http=static function(string $path,?array $form=null)use($origin,$browserToken,&$cookies): array{
                $headers=['X-Fasakhansta-Desktop: '.$browserToken,'Referer: '.$origin.'/admin/login'];
                if($cookies)$headers[]='Cookie: '.implode('; ',array_map(static fn($name,$value)=>$name.'='.$value,array_keys($cookies),$cookies));
                if($form!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
                $context=stream_context_create(['http'=>['method'=>$form===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$form===null?'':http_build_query($form),
                    'ignore_errors'=>true,'follow_location'=>0,'timeout'=>20]]);
                $body=@file_get_contents($origin.$path,false,$context);$responseHeaders=$http_response_header??[];preg_match('/^HTTP\/\S+ (\d+)/',$responseHeaders[0]??'',$status);$location=null;
                foreach($responseHeaders as $header){if(preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$header,$match))$cookies[$match[1]]=$match[2];if(preg_match('/^Location: (.+)$/i',$header,$match))$location=parse_url(trim($match[1]),PHP_URL_PATH);}
                return ['status'=>(int)($status[1]??0),'body'=>$body?:'','location'=>$location];
            };
            for($attempt=0;$attempt<100;$attempt++){if($http('/_desktop/health')['status']===200)break;usleep(50000);}
            $check($http('/_desktop/health')['status']===200,'Original private PHP router did not become healthy.');
            $login=static function()use($http,$check): string{$page=$http('/admin/login');preg_match('/name="_token" value="([^"]+)"/',$page['body'],$csrf);$check($page['status']===200&&!empty($csrf[1])&&str_contains($page['body'],'dashboard-login-form'),'Original login/CSRF form did not render.');return html_entity_decode($csrf[1],ENT_QUOTES|ENT_HTML5,'UTF-8');};
            $guestIdentity=$http('/admin/choose_type');
            $check($guestIdentity['status']===302&&$guestIdentity['location']==='/admin/login','Guest accessed the protected lightweight identity document.');
            $csrf=$login();$check($http('/admin/signin',['_token'=>str_repeat('x',40),'email'=>$account['email'],'password'=>$account['password']])['status']===419,'Wrong CSRF token reached original signin.');
            $rejected=0;
            foreach($accounts as $foreignId=>$foreign){if($foreignId===$actorId)continue;
                $csrf=$login();$reply=$http('/admin/signin',['_token'=>$csrf,'email'=>$foreign['email'],'password'=>$foreign['password']]);
                $check($reply['status']===302&&$reply['location']==='/admin/login','Masked or absent foreign actor did not fail original signin.');$login();$rejected++;
            }
            $csrf=$login();$reply=$http('/admin/signin',['_token'=>$csrf,'email'=>$account['email'],'password'=>$account['password']]);
            $check($reply['status']===302&&$reply['location']===$account['redirect'],'Original account-type signin redirect differs.');
            $identityDocument=$http('/admin/choose_type');
            preg_match_all('/<body\b[^>]*\bdata-dashboard-actor="([1-9][0-9]*):(admin|vendor|resturant_owner)"/i',$identityDocument['body'],$identityActors,PREG_SET_ORDER);
            $check($identityDocument['status']===200&&count($identityActors)===1&&(int)$identityActors[0][1]===$actorId&&$identityActors[0][2]===$account['account_type']&&!str_contains($identityDocument['body'],'home-overview-bootstrap'),'Lightweight original identity document does not retain the authenticated actor without rendering the overview.');
            $again=$http('/admin/login');$check($again['status']===302&&$again['location']===$account['redirect'],'Original session did not retain the enrolled account.');
            $overview=$http('/admin/dashboard/overview');$data=json_decode($overview['body'],true,512,JSON_THROW_ON_ERROR);$httpBranches=array_column($data['branches']??[],'value');sort($httpBranches,SORT_STRING);
            $check($overview['status']===200&&($data['success']??false)===true&&$httpBranches===$account['branches'],'Original authenticated HTTP overview crossed account branches.');
            $permissionReply=$http('/admin/orders');$check($permissionReply['status']===($actorId===10?403:200),'Original order-list HTTP permission gate differs.');
            $check($credentials()===$credentialBefore,'Original local signin changed imported password or remember-token rows.');
            $check(DB::table('desktop_dashboard_commands')->count()===0,'Read/login proof created a business journal command.');
            $proofs[]=['actorId'=>$actorId,'label'=>$account['label'],'accountType'=>$account['account_type'],'branches'=>$httpBranches,'snapshotTables'=>106,
                'originalLogin'=>true,'originalIdentityDocument'=>true,'guestIdentityRejected'=>true,'identitySkipsOverview'=>true,'signinStatus'=>$reply['status'],'signinRedirect'=>$reply['location'],'wrongCsrfStatus'=>419,'foreignLoginsRejected'=>$rejected,
                'otherPasswordsMasked'=>true,'verifiedImport'=>true,'orderListHttpStatus'=>$permissionReply['status'],'credentialRowsUnchanged'=>true,'journalCommands'=>0];
        }
        config(['database.connections.mysql.database'=>$central,'desktop_dashboard.local'=>false,'desktop_dashboard.device_id'=>null]);DB::purge();
        $centralAfter=$rowProof($pdo,$central,array_keys($report['tables']));$check($centralAfter===$centralBefore,'Enrollment/export/local HTTP changed central business or credential rows.');
        $check(DB::table('desktop_dashboard_devices')->count()===3&&count(array_unique($devices))===3,'Actors did not receive distinct original enrollments.');
        $check($verifySource()&&app(DesktopDashboardSource::class)->fingerprint()===$manifest['sourceFingerprint'],'Application source changed during real HTTP proof.');
        foreach($helperHashes as $name=>$hash)$check(hash_file('sha256',$source.'/'.$name)===$hash,'Source-bound fixture helper changed during proof.');
        $receipt=['format'=>1,'kind'=>'go-erp-accountprofiles-role-http','sourceRevision'=>$revision,'fixtureRevision'=>$revision,
            'sourceFingerprint'=>$manifest['sourceFingerprint'],'sourceManifestSha256'=>hash_file('sha256',$manifestPath),'sourceFilesVerified'=>count($manifest['sourceHashes']),
            'helperBinding'=>getenv('CI')==='true'?'exact-git-revision':'local-working-tree','fixtureHashes'=>$helperHashes,'phpVersion'=>PHP_VERSION,'laravel'=>app()->version(),
            'schemaInventory'=>106,'actors'=>$proofs,'distinctEnrollments'=>3,'distinctDatabases'=>4,'distinctStorageRoots'=>3,'actualPhpServer'=>true,'originalRouter'=>true,
            'originalAuthProvider'=>true,'providersReplaced'=>false,'permissionBypass'=>false,'syntheticFixture'=>true,'productionProfile'=>false,'productionCredentialsCopied'=>false,
            'compactVendor'=>$compactArchive!==null,
            'fullDashboard'=>false,'sourceVerifiedAfter'=>true,'helpersVerifiedAfter'=>true,'centralTablesBefore'=>$centralBefore,'centralTablesAfter'=>$centralAfter,
            'centralBusinessAndCredentialsUnchanged'=>true,'checks'=>$checks,'completed'=>false];
    }finally{$cleanup();}
    foreach($cleanupFlags as $flag=>$value)$check($value===true,'Cleanup did not complete: '.$flag);
    $receipt=array_merge($receipt,$cleanupFlags,['checks'=>$checks,'completed'=>true]);$completed=true;
    echo 'DESKTOP_ACCOUNT_PROFILES_ROLE_HTTP_COMPLETE '.json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
})();
