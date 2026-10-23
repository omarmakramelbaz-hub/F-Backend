<?php
// Actual Laravel/MariaDB, original Blade/JS in Edge, private photo transfer and local replay.
// All accounts, employees and photograph bytes below are synthetic fixtures.
// Usage: php employee-identity-http.php application maria-port artifacts revision [local-only-manifest]
use Illuminate\Support\Facades\{DB,Crypt,Schema};
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardBootstrap,DesktopDashboardImport,
    DesktopDashboardMedia,DesktopDashboardJournal,DesktopDashboardReconciliation,EmployeeIdentity,EmployeeRegulation};

(function()use($argv){
    $fixture=__DIR__;$source=dirname($fixture,2);$application=realpath($argv[1]??'');$artifact=$argv[3]??'';
    if(!$application||!is_dir($artifact))throw new RuntimeException('Employee identity browser requires an application and existing private artifact directory.');
    $command=function(array $args)use($source): string{
        $process=proc_open($args,[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,$source,null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new RuntimeException('Cannot start employee proof helper.');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($process)!==0)throw new RuntimeException('Employee proof helper failed: '.$err);return $out;
    };
    $localManifest=isset($argv[5]);
    if(getenv('CI')==='true'&&$localManifest)throw new RuntimeException('CI Employee identity browser cannot substitute a working-tree manifest.');
    $manifestPath=$localManifest?$argv[5]:dirname($application).'/manifest.json';
    $manifest=json_decode(file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
    if(($manifest['fullDashboard']??null)!==false)throw new RuntimeException('Employee identity browser requires a preview manifest with fullDashboard false.');
    $revision=$argv[4]??$manifest['sourceRevision'];
    if($revision!==$manifest['sourceRevision']||($localManifest?$revision!=='working-tree':!preg_match('/^[a-f0-9]{40}$/D',$revision)))throw new RuntimeException('Employee identity browser source revision differs.');
    $expected=$manifest['sourceFingerprint'];
    $fingerprint=function()use($command,$source,$application): array{
        return json_decode($command(['node','-e','require(process.argv[1]).fingerprint(process.argv[2]).then(x=>process.stdout.write(JSON.stringify(x))).catch(e=>{console.error(e);process.exitCode=1})',$source.'/desktop-pos/src/dashboard-source.cjs',$application]),true,512,JSON_THROW_ON_ERROR);
    };
    $verifySource=function()use($application,$manifest,$expected,$fingerprint): bool{
        foreach($manifest['sourceHashes']??[] as $file=>$hash)if(!is_file($application.'/'.$file)||hash_file('sha256',$application.'/'.$file)!==$hash)return false;
        return $fingerprint()===$expected;
    };
    if(!$verifySource()||file_exists($application.'/.env'))throw new RuntimeException('Employee identity browser application bytes differ from the frozen private source.');
    $helpers=['tests/desktop_dashboard_runtime/employee-identity-http.php','tests/desktop_dashboard_runtime/employee-identity-browser.cjs',
        'tests/desktop_dashboard_runtime/employee-identity-server-router.php','tests/desktop_dashboard_runtime/server-router.php',
        'tests/desktop_dashboard_runtime/fixtures/identity-front.png','tests/desktop_dashboard_runtime/fixtures/identity-replacement.jpg','tests/desktop_dashboard_runtime/legacy.php','tests/desktop_dashboard_runtime/full-schema.php',
        'tests/desktop_dashboard_runtime/fixtures/deployed-schema-20261008.json','desktop-pos/src/dashboard-source.cjs'];
    $helperHashes=[];foreach($helpers as $file){$hash=hash_file('sha256',$source.'/'.$file);$helperHashes[$file]=$hash;
        if(getenv('CI')==='true'&&hash('sha256',$command(['git','show',$revision.':'.$file]))!==$hash)throw new RuntimeException('Employee identity helper is not bound to the exact runtime revision: '.$file);
    }
    $receipt=['format'=>1,'kind'=>'original-employee-identity-browser','sourceRevision'=>$revision,'fixtureRevision'=>$revision,
        'sourceFingerprint'=>$expected,'sourceFilesVerified'=>count($manifest['sourceHashes']),'sourceBinding'=>$localManifest?'local-working-tree-fingerprint':'runtime-manifest',
        'helperBinding'=>getenv('CI')==='true'?'exact-git-revision':'local-working-tree','fixtureHashes'=>$helperHashes,
        'sourceManifestSha256'=>hash_file('sha256',$manifestPath),'baseRevision'=>$manifest['baseRevision']??$revision,
        'schemaInventory'=>106,'fullDashboard'=>false,'providersReplaced'=>false,'domInjection'=>false,'completed'=>false];
    $legacy=file_get_contents($fixture.'/legacy.php');$boundary=strpos($legacy,'$actor=User::withoutGlobalScopes()->findOrFail(10);');
    if(!$boundary)throw new RuntimeException('Original full schema seed boundary missing.');
    eval(str_replace('__DIR__',var_export($fixture,true),substr($legacy,5,$boundary-5)));
    $fixtureCompleted=true;$identityCompleted=false;$webs=[];$browserStopped=true;
    $localProfile=$profile.'-local';
    foreach(['app/public','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache','private'] as $dir)mkdir($localProfile.'/'.$dir,0700,true);
    register_shutdown_function(function()use(&$receipt,&$identityCompleted,&$webs,&$browserStopped,$artifact,$pdo,$database,$stage,$helperHashes,$source,$verifySource){
        $receipt['helpersVerifiedAfter']=true;foreach($helperHashes as $file=>$hash)if(hash_file('sha256',$source.'/'.$file)!==$hash)$receipt['helpersVerifiedAfter']=false;
        $receipt['sourceVerifiedAfter']=$verifySource();$receipt['browserStopped']=$browserStopped;$receipt['httpStopped']=true;
        foreach($webs as $web)if(is_resource($web)&&proc_get_status($web)['running'])$receipt['httpStopped']=false;
        $receipt['privateDatabasesDropped']=(int)$pdo->query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME IN ('.$pdo->quote($database).','.$pdo->quote($stage).')')->fetchColumn()===0;
        $receipt['completed']=$identityCompleted&&$receipt['helpersVerifiedAfter']&&$receipt['sourceVerifiedAfter']&&$receipt['httpStopped']&&$browserStopped&&$receipt['privateDatabasesDropped'];
        $receipt['checks']=$GLOBALS['count']??0;file_put_contents($artifact.'/receipt.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL);
        if(!$receipt['completed']){fwrite(STDERR,'Original employee identity fixture did not complete.'.PHP_EOL);exit(1);}
    });
    $receipt['phpVersion']=PHP_VERSION;$receipt['laravel']=\Illuminate\Foundation\Application::VERSION;$receipt['mariaVersion']=$pdo->query('SELECT VERSION()')->fetchColumn();
    // Apply the original migration paths required by today's employee editor.
    foreach([
        ['2026_10_06_140000_add_employee_wallet_phone.php','AddEmployeeWalletPhone',!Schema::hasColumn('branch_employees','wallet_phone')],
        ['2026_10_18_160000_add_employee_part_time_schedule.php','AddEmployeePartTimeSchedule',true],
        ['2026_10_18_180000_add_employee_pay_hours.php','AddEmployeePayHours',true],
        ['2026_10_23_200000_add_employee_identity_and_regulation.php','AddEmployeeIdentityAndRegulation',true],
    ] as [$file,$class,$needed])if($needed){require_once $application.'/database/migrations/'.$file;(new $class)->up();}
    verify(EmployeeIdentity::ready(),'the original nullable identity migration installs all nine columns');
    (new \AddEmployeeIdentityAndRegulation)->up();verify(EmployeeIdentity::ready(),'repeating the original migration preserves its installed columns');
    $actor=User::withoutGlobalScopes()->findOrFail(1);
    $role=\Spatie\Permission\Models\Role::findOrCreate('Super Admin','admin');$actor->assignRole($role);
    foreach([7=>'f:100',8=>'f:101'] as $employee=>$branch){
        DB::table('branch_employees')->insert(['id'=>$employee,'branch'=>$branch,'name'=>'موظف تجريبي '.$employee,'phone'=>'01012345678','job_title'=>'اختبار','shift'=>'صباحي','hired_on'=>'2026-10-01','active'=>true,'revision'=>1,'actor_id'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        DB::table('branch_employee_salaries')->insert(['employee_id'=>$employee,'branch'=>$branch,'effective_month'=>'2026-10','amount_cents'=>300000,'actor_id'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    config(['desktop_dashboard.local'=>false]);
    $png=$fixture.'/fixtures/identity-front.png';$jpg=$fixture.'/fixtures/identity-replacement.jpg';
    verify((new finfo(FILEINFO_MIME_TYPE))->file($png)==='image/png'&&(new finfo(FILEINFO_MIME_TYPE))->file($jpg)==='image/jpeg'
        &&getimagesize($png)!==false&&getimagesize($jpg)!==false,'synthetic color swatches are real PNG and JPEG images');
    app(EmployeeIdentity::class)->upload(['branch'=>'f:101','employee_id'=>8,'side'=>'front'],new \Illuminate\Http\UploadedFile($png,'synthetic.png','image/png',null,true),$actor);
    $php=getenv('DESKTOP_TEST_PHP_EXECUTABLE')?:PHP_BINARY;
    if(getenv('CI')==='true'&&$php!==PHP_BINARY)throw new RuntimeException('CI employee fixture must use the captured Windows PHP executable.');
    $startWeb=function(string $name,string $db,string $storage,bool $local,string $device='',string $token='')use(&$webs,$application,$fixture,$artifact,$php): array{
        $reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$reservation)throw new RuntimeException('Cannot reserve private employee HTTP port.');
        $port=(int)substr(strrchr(stream_socket_get_name($reservation,false),':'),1);fclose($reservation);$origin='http://127.0.0.1:'.$port;$env=getenv();
        foreach(['DB_DATABASE'=>$db,'APP_URL'=>$origin,'DESKTOP_TEST_APPLICATION'=>$application,'DESKTOP_DASHBOARD_ENABLED'=>'true',
            'DESKTOP_DASHBOARD_STORAGE'=>$storage,'DESKTOP_DASHBOARD_DEVICE_ID'=>$device,'DESKTOP_DASHBOARD_LOCAL'=>'true',
            'DESKTOP_DASHBOARD_ORIGIN'=>$origin,'DESKTOP_DASHBOARD_TOKEN'=>$token,'DESKTOP_DASHBOARD_CONTROL_TOKEN'=>bin2hex(random_bytes(32)),
            'APP_CONFIG_CACHE'=>$storage.'/bootstrap/cache/config.php','APP_PACKAGES_CACHE'=>$storage.'/bootstrap/cache/packages.php',
            'APP_SERVICES_CACHE'=>$storage.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$storage.'/bootstrap/cache/routes.php'] as $key=>$value)$env[$key]=$value;
        $router=$local?$application.'/desktop/router.php':$fixture.'/employee-identity-server-router.php';
        $web=proc_open([$php,'-S','127.0.0.1:'.$port,'-t',$application.'/public',$router],[['pipe','r'],['file',$artifact.'/'.$name.'-http.log','a'],['file',$artifact.'/'.$name.'-http.log','a']],$pipes,$application,$env);
        if(!is_resource($web))throw new RuntimeException('Cannot start private employee HTTP server.');fclose($pipes[0]);$webs[]=$web;
        for($n=0;$n<100;$n++){$socket=@fsockopen('127.0.0.1',$port,$errno,$error,.1);if($socket){fclose($socket);return [$origin,$env];}if(!proc_get_status($web)['running'])break;usleep(50000);}
        throw new RuntimeException('Private employee HTTP server did not start.');
    };
    $runBrowser=function(string $phase,string $origin,array $env,string $token='')use($fixture,$artifact,$application,$revision,$png,$jpg,&$browserStopped): array{
        $browserStopped=false;$browser=proc_open(['node',$fixture.'/employee-identity-browser.cjs'],[['pipe','r'],['file',$artifact.'/'.$phase.'-browser.log','w'],['file',$artifact.'/'.$phase.'-browser-errors.log','w']],$pipes,$application,$env);
        if(!is_resource($browser))throw new RuntimeException('Cannot start actual employee browser.');
        fwrite($pipes[0],json_encode(['phase'=>$phase,'origin'=>$origin,'token'=>$token,'sourceCommit'=>$revision,'fixtureRevision'=>$revision,'artifacts'=>$artifact,
            'png'=>$png,'jpg'=>$jpg,'regulation'=>['version'=>EmployeeRegulation::VERSION,'sha256'=>EmployeeRegulation::sha256(),'title'=>EmployeeRegulation::TITLE]],JSON_UNESCAPED_UNICODE));fclose($pipes[0]);
        $deadline=microtime(true)+150;do{$status=proc_get_status($browser);if(!$status['running'])break;usleep(100000);}while(microtime(true)<$deadline);
        if($status['running'])proc_terminate($browser);$exit=proc_close($browser);$browserStopped=!$status['running'];if($exit<0&&!$status['running'])$exit=$status['exitcode'];
        $output=file_get_contents($artifact.'/'.$phase.'-browser.log');echo $output;fwrite(STDERR,file_get_contents($artifact.'/'.$phase.'-browser-errors.log'));
        if($exit!==0){fwrite(STDERR,substr(file_get_contents($artifact.'/'.$phase.'-http.log'),-15000));throw new RuntimeException('Actual employee browser failed in '.$phase);}
        preg_match_all('/^EMPLOYEE_IDENTITY_BROWSER_PROOF (\{[^\r\n]+\})$/m',$output,$matches);if(count($matches[1])!==1)throw new RuntimeException('Employee browser completion proof is missing or repeated.');
        $proof=json_decode($matches[1][0],true,512,JSON_THROW_ON_ERROR);
        verify($proof['sourceCommit']===$revision&&$proof['fixtureRevision']===$revision&&$proof['phase']===$phase&&$proof['newPageErrors']===0
            &&$proof['domInjection']===false&&$proof['providersReplaced']===false,'the '.$phase.' browser executes exact original Blade and JavaScript without injected DOM or providers');
        return $proof;
    };
    $http=function(string $url,?string $bearer=null): array{
        $headers=['Accept: application/json'];if($bearer!==null)$headers[]='Authorization: Bearer '.$bearer;
        $context=stream_context_create(['http'=>['header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>30,'follow_location'=>0]]);
        $bytes=@file_get_contents($url,false,$context);$reply=$http_response_header??[];preg_match('/^HTTP\/\S+ (\d+)/',$reply[0]??'',$status);
        return [(int)($status[1]??0),$bytes,$reply];
    };
    try{
        [$centralOrigin,$centralEnv]=$startWeb('central',$database,$profile,false);
        $receipt['browserProof']['central']=$central=$runBrowser('central',$centralOrigin,$centralEnv);
        foreach(['regulationRendered','staleConsentRejected','firstAcceptanceDatePreserved','withdrawal','realUpload','immutableABA','branchReadOnly','outOfScopeDenied','privatePublicUrlDenied'] as $flag)
            verify(($central[$flag]??false)===true,'central browser confirms '.$flag);
        $employee=DB::table('branch_employees')->where('id',7)->first();$beforePhoto=['front'=>$employee->id_front_path,'back'=>$employee->id_back_path];
        verify($employee->regulation_version===EmployeeRegulation::VERSION&&$employee->regulation_accepted_at!==null&&(int)$employee->regulation_accepted_by===1,'actual central employee row records the displayed regulation and original authenticated actor');
        $pngHash=hash_file('sha256',$png);$jpgHash=hash_file('sha256',$jpg);
        verify($employee->id_front_hash===$pngHash&&$employee->id_back_hash===$jpgHash&&$employee->id_front_mime==='image/png'&&$employee->id_back_mime==='image/jpeg','actual multipart controllers persist the byte hashes and detected PNG/JPEG types');
        $replaced=EmployeeIdentity::PREFIX.'7/front-'.$jpgHash.'.jpg';
        verify(is_file($profile.'/app/'.$replaced)&&hash_file('sha256',$profile.'/app/'.$replaced)===$jpgHash
            &&hash_file('sha256',$profile.'/app/'.$employee->id_front_path)===$pngHash,'A to B to A retains both immutable files and the current front photo');
        verify(!file_exists($profile.'/app/public/'.$employee->id_front_path)&&!file_exists($profile.'/app/public/'.$employee->id_back_path),'both identity photographs stay outside the public disk');
        $device=(string)\Illuminate\Support\Str::uuid();$link=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$device,'name'=>'synthetic employee identity browser','nonce'=>bin2hex(random_bytes(32))],$actor);
        $remoteDevice=app(DesktopDashboardDevices::class)->device($link['token']);
        $snapshot=app(DesktopDashboardBootstrap::class)->export($remoteDevice);
        $private=array_values(array_filter($snapshot['media'],fn($part)=>($part['area']??'public')==='private'));
        verify($snapshot['coverage']['media']===true&&count($private)===3&&count($snapshot['media'])===3,'real bootstrap includes the three scoped referenced private identity photos and excludes replaced history');
        $branchLink=app(DesktopDashboardDevices::class)->enroll(['device_id'=>(string)\Illuminate\Support\Str::uuid(),'name'=>'synthetic branch image scope','nonce'=>bin2hex(random_bytes(32))],User::withoutGlobalScopes()->findOrFail(10));
        $branchSnapshot=app(DesktopDashboardBootstrap::class)->export(app(DesktopDashboardDevices::class)->device($branchLink['token']));
        verify(count($branchSnapshot['media'])===2&&array_column($branchSnapshot['tables']['branch_employees']['rows'],'id')===[7],'branch snapshot carries only its own employee and two private images');
        foreach($private as $part){
            $url=$centralOrigin.'/api/desktop-dashboard/media?ticket='.rawurlencode($part['ticket']);
            [$status,$bytes,$headers]=$http($url,$link['token']);
            verify($status===200&&hash('sha256',$bytes)===$part['sha256']&&strlen($bytes)===$part['bytes'],'authenticated original media HTTP transfers the exact private photo bytes');
            verify($http($url)[0]===401,'private photo HTTP requires the enrolled device credential');
            if(str_contains($part['path'],'/8/'))verify($http($url,$branchLink['token'])[0]===403,'another branch credential cannot reuse the foreign employee photo capability');
            $target=$localProfile.'/app/'.$part['path'];if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);file_put_contents($target,$bytes);chmod($target,0600);
            verify(!is_file($localProfile.'/app/public/'.$part['path']),'downloaded photo is written only to the fresh device private disk');
        }
        $receipt['privateMediaHttpVerified']=true;
        // Original importer runs against a genuinely separate database and storage directory.
        $app->useStoragePath($localProfile);$sourceStorage=str_replace('\\','/',$profile);
        foreach(config('filesystems.disks') as $name=>$disk){
            $root=str_replace('\\','/',$disk['root']??'');
            if(str_starts_with($root,$sourceStorage.'/'))config(['filesystems.disks.'.$name.'.root'=>$localProfile.substr($root,strlen($sourceStorage))]);
        }
        $app->make('filesystem')->forgetDisk(array_keys(config('filesystems.disks')));
        config(['database.connections.mysql.database'=>$stage,'desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>$device]);DB::purge();
        $import=app(DesktopDashboardImport::class)->import($snapshot);verify(app(DesktopDashboardImport::class)->verify($import)['verified'],'original importer independently verifies the fresh local database and all downloaded private photographs');
        $receipt['verifiedImport']=true;$receipt['importedSnapshotTables']=count($snapshot['tables']);
        $token=bin2hex(random_bytes(32));[$localOrigin,$localEnv]=$startWeb('local',$stage,$localProfile,true,$device,$token);
        $receipt['browserProof']['local']=$local=$runBrowser('local',$localOrigin,$localEnv,$token);
        foreach(['regulationRendered','privatePhotos','imagesDecoded','displayedPhotoSourcesBound','onlineUploadExplanation','consentJournal','privatePublicUrlDenied'] as $flag)verify(($local[$flag]??false)===true,'local browser confirms '.$flag);
        $rows=DB::table('desktop_dashboard_commands')->orderBy('sequence')->get();verify($rows->count()===2,'only two accepted local regulation edits enter the encrypted command journal');
        $envelopes=[];
        foreach($rows as $index=>$row){
            $payload=json_decode(Crypt::decryptString($row->command_cipher),true,512,JSON_THROW_ON_ERROR);$result=json_decode(Crypt::decryptString($row->local_result_cipher),true,512,JSON_THROW_ON_ERROR);
            verify($row->route_name==='employees.save'&&$row->command_id===$local['writes'][$index]['payload']['idempotency_key']&&$payload['values']['regulation_accepted']===($index===1),'encrypted local journal retains the exact browser UUID and explicit approval or withdrawal');
            if($index===1)verify($payload['values']['regulation_version']===EmployeeRegulation::VERSION&&$payload['values']['regulation_hash']===EmployeeRegulation::sha256(),'queued approval retains the displayed policy version and digest without substitution');
            verify(!str_contains($row->command_cipher,'موظف تجريبي')&&!str_contains($row->command_cipher,EmployeeRegulation::sha256()),'account values and consent inputs are encrypted at rest');
            $envelopes[]=['command_id'=>$row->command_id,'actor_id'=>(int)$row->actor_id,'route_name'=>$row->route_name,'payload'=>$payload,'local_result'=>$result['result'],'local_references'=>$result['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String()];
        }
        $receipt['consentJournalVerified']=true;
        config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();$app->useStoragePath($profile);
        $commandCount=DB::table('branch_operation_commands')->count();$replies=[];
        foreach($envelopes as $index=>$envelope){
            if($index===1){
                $stale=$envelope;$stale['command_id']=(string)\Illuminate\Support\Str::uuid();$stale['payload']['values']['idempotency_key']=$stale['command_id'];$stale['payload']['values']['regulation_hash']=str_repeat('0',64);
                $beforeRejected=(array)DB::table('branch_employees')->where('id',7)->first();$beforeCommands=DB::table('branch_operation_commands')->count();$rejected=false;
                try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$stale);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){$rejected=$error->getStatusCode()===422&&$error->getMessage()==='نسخة لائحة العمل تغيرت؛ افتح اللائحة الحالية واقرأها قبل تسجيل الموافقة.';}
                verify($rejected&&(array)DB::table('branch_employees')->where('id',7)->first()===$beforeRejected&&DB::table('branch_operation_commands')->count()===$beforeCommands,'a queued approval for an obsolete policy fails with fixed 422 before employee or business receipt writes');
                $receipt['staleQueuedConsentRejected']=true;
            }
            $reply=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$envelope);
            verify(($reply['committed']??false)===true&&$reply['command_id']===$envelope['command_id'],'original reconciliation commits the local consent command using its captured policy identity');
            verify(app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$envelope)===$reply,'retrying the identical UUID returns its committed receipt without another employee mutation');$replies[]=$reply;
        }
        $remote=DB::table('branch_employees')->where('id',7)->first();
        verify(DB::table('branch_operation_commands')->count()===$commandCount+2&&$remote->regulation_version===EmployeeRegulation::VERSION&&(int)$remote->regulation_accepted_by===1,'server applies exactly one withdrawal and approval and records the original employee acceptance actor');
        verify($remote->id_front_path===$beforePhoto['front']&&$remote->id_back_path===$beforePhoto['back'],'offline consent replay preserves both centrally uploaded private photograph references');
        $receipt['consentReplayVerified']=true;$receipt['duplicateReplayVerified']=true;
        config(['database.connections.mysql.database'=>$stage,'desktop_dashboard.local'=>true]);DB::purge();
        foreach($envelopes as $index=>$envelope)app(DesktopDashboardJournal::class)->acknowledge($device,$envelope['command_id'],$replies[$index]);
        verify(DB::table('desktop_dashboard_commands')->where('status','acknowledged')->count()===2&&app(DesktopDashboardJournal::class)->pending($device)===[],'local journal keeps both encrypted receipts and has no resendable consent operation after acknowledgment');
        $identityCompleted=true;
        echo 'ORIGINAL_EMPLOYEE_IDENTITY_BROWSER_COMPLETE '.json_encode(['sourceRevision'=>$revision,'checks'=>$GLOBALS['count']??0,'fullDashboard'=>false]).PHP_EOL;
    }finally{
        foreach($webs as $i=>$web)if(is_resource($web)){proc_terminate($web);for($n=0;$n<100;$n++){if(!proc_get_status($web)['running'])break;usleep(50000);}proc_close($web);$webs[$i]=null;}
    }
})();
