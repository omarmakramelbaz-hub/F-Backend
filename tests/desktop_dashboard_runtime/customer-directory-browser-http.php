<?php
// Actual CRM Blade/JavaScript, middleware and controllers with synthetic imported rows only.
// Usage: php customer-directory-browser-http.php application maria-port artifacts [source-revision] [local-only-manifest]
use Illuminate\Support\Facades\{DB,Crypt};
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardBootstrap,DesktopDashboardImport};

(function()use($argv){
    $fixture=__DIR__;$source=dirname($fixture,2);$application=realpath($argv[1]??'');$artifact=$argv[3]??'';
    if(!$application||!is_dir($artifact))throw new RuntimeException('CRM browser requires an application and existing private artifact directory.');
    $command=function(array $args)use($source): string{
        $process=proc_open($args,[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,$source,null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new RuntimeException('Cannot start CRM proof helper.');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($process)!==0)throw new RuntimeException('CRM proof helper failed: '.$err);return $out;
    };
    $localManifest=isset($argv[5]);
    if(getenv('CI')==='true'&&$localManifest)throw new RuntimeException('CI CRM browser cannot substitute a working-tree manifest.');
    $manifestPath=$localManifest?$argv[5]:dirname($application).'/manifest.json';
    $manifest=json_decode(file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
    if(($manifest['fullDashboard']??null)!==false)throw new RuntimeException('CRM browser requires a preview manifest with fullDashboard false.');
    $revision=$argv[4]??$manifest['sourceRevision'];
    if($revision!==$manifest['sourceRevision']||($localManifest?$revision!=='working-tree':!preg_match('/^[a-f0-9]{40}$/D',$revision)))throw new RuntimeException('CRM browser source revision differs.');
    $expected=$manifest['sourceFingerprint'];
    $fingerprint=function()use($command,$source,$application): array{
        return json_decode($command(['node','-e','require(process.argv[1]).fingerprint(process.argv[2]).then(x=>process.stdout.write(JSON.stringify(x))).catch(e=>{console.error(e);process.exitCode=1})',$source.'/desktop-pos/src/dashboard-source.cjs',$application]),true,512,JSON_THROW_ON_ERROR);
    };
    $verifySource=function()use($application,$manifest,$expected,$fingerprint): bool{
        foreach($manifest['sourceHashes']??[] as $file=>$hash)if(!is_file($application.'/'.$file)||hash_file('sha256',$application.'/'.$file)!==$hash)return false;
        return $fingerprint()===$expected;
    };
    if(!$verifySource()||file_exists($application.'/.env'))throw new RuntimeException('CRM browser application bytes differ from the frozen private source.');
    $helpers=['tests/desktop_dashboard_runtime/customer-directory-browser-http.php','tests/desktop_dashboard_runtime/customer-directory-browser.cjs',
        'tests/desktop_dashboard_runtime/browser-sound.cjs','tests/desktop_dashboard_runtime/legacy.php','tests/desktop_dashboard_runtime/full-schema.php',
        'tests/desktop_dashboard_runtime/fixtures/deployed-schema-20261008.json','desktop-pos/src/dashboard-source.cjs'];
    $helperHashes=[];foreach($helpers as $file){$hash=hash_file('sha256',$source.'/'.$file);$helperHashes[$file]=$hash;
        if(getenv('CI')==='true'&&hash('sha256',$command(['git','show',$revision.':'.$file]))!==$hash)throw new RuntimeException('CRM helper is not bound to the exact runtime revision: '.$file);
    }
    $receipt=['format'=>1,'kind'=>'original-customer-directory-browser','sourceRevision'=>$revision,'fixtureRevision'=>$revision,
        'sourceFingerprint'=>$expected,'sourceFilesVerified'=>count($manifest['sourceHashes']),'sourceBinding'=>$localManifest?'local-working-tree-fingerprint':'runtime-manifest',
        'helperBinding'=>getenv('CI')==='true'?'exact-git-revision':'local-working-tree','fixtureHashes'=>$helperHashes,
        'sourceManifestSha256'=>hash_file('sha256',$manifestPath),'baseRevision'=>$manifest['baseRevision']??$revision,
        'schemaInventory'=>106,'fullDashboard'=>false,'providersReplaced'=>false,'domInjection'=>false,'completed'=>false];
    // Reuse the unchanged original 106-table schema and synthetic login rows before enrollment.
    $legacy=file_get_contents($fixture.'/legacy.php');$boundary=strpos($legacy,'$actor=User::withoutGlobalScopes()->findOrFail(10);');
    if(!$boundary)throw new RuntimeException('Original full schema seed boundary missing.');
    eval(str_replace('__DIR__',var_export($fixture,true),substr($legacy,5,$boundary-5)));
    // The reused seed prefix is complete; this standalone proof has its own guard.
    $fixtureCompleted=true;$crmCompleted=false;
    $httpStopped=false;$browserStopped=false;
    register_shutdown_function(function()use(&$receipt,&$crmCompleted,&$httpStopped,&$browserStopped,$artifact,$pdo,$database,$stage,$helperHashes,$source,$verifySource){
        $receipt['helpersVerifiedAfter']=true;foreach($helperHashes as $file=>$hash)if(hash_file('sha256',$source.'/'.$file)!==$hash)$receipt['helpersVerifiedAfter']=false;
        $receipt['sourceVerifiedAfter']=$verifySource();$receipt['httpStopped']=$httpStopped;$receipt['browserStopped']=$browserStopped;
        $receipt['privateDatabasesDropped']=(int)$pdo->query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME IN ('.$pdo->quote($database).','.$pdo->quote($stage).')')->fetchColumn()===0;
        $receipt['completed']=$crmCompleted&&$receipt['helpersVerifiedAfter']&&$receipt['sourceVerifiedAfter']&&$httpStopped&&$browserStopped&&$receipt['privateDatabasesDropped'];
        $receipt['checks']=$GLOBALS['count']??0;file_put_contents($artifact.'/receipt.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL);
        if(!$receipt['completed']){fwrite(STDERR,'Original CRM browser fixture did not complete.'.PHP_EOL);exit(1);}
    });
    $receipt['phpVersion']=PHP_VERSION;$receipt['laravel']=\Illuminate\Foundation\Application::VERSION;$receipt['mariaVersion']=$pdo->query('SELECT VERSION()')->fetchColumn();
    require $application.'/database/migrations/2026_10_17_000001_create_crm_customer_directory.php';(new \CreateCrmCustomerDirectory)->up();
    verify(DB::table('crm_customers')->count()===0&&DB::table('crm_purchases')->count()===0,'actual CRM migration starts with synthetic empty directory and purchase history');
    $actor=User::withoutGlobalScopes()->findOrFail(1);$device=(string)\Illuminate\Support\Str::uuid();
    $link=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$device,'name'=>'original CRM browser','nonce'=>bin2hex(random_bytes(32))],$actor);
    $snapshot=app(DesktopDashboardBootstrap::class)->export(app(DesktopDashboardDevices::class)->device($link['token']));
    verify(count($snapshot['tables'])===109&&isset($snapshot['tables']['crm_customers'],$snapshot['tables']['crm_customer_sources'],$snapshot['tables']['crm_purchases']),'actual CRM snapshot retains all 106 inspected tables and three original CRM migration tables');
    config(['database.connections.mysql.database'=>$stage,'desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>$device]);DB::purge();
    $import=app(DesktopDashboardImport::class)->import($snapshot);
    verify(app(DesktopDashboardImport::class)->verify($import)['verified'],'real CRM browser uses the independently verified full original import');
    $receipt['verifiedImport']=true;$receipt['importedSnapshotTables']=count($snapshot['tables']);
    $preserved=[];foreach(array_keys($snapshot['tables']) as $table)if(!in_array($table,['crm_customers','crm_customer_sources','branch_operation_commands'],true)){
        $rows=array_map(fn($row)=>(array)$row,DB::table($table)->get()->all());usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$preserved[$table]=hash('sha256',json_encode($rows));
    }
    $reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($reservation,false),':'),1);fclose($reservation);
    $origin='http://127.0.0.1:'.$port;$token=bin2hex(random_bytes(32));$env=getenv();
    foreach(['DB_DATABASE'=>$stage,'APP_URL'=>$origin,'DESKTOP_DASHBOARD_DEVICE_ID'=>$device,'DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_ORIGIN'=>$origin,'DESKTOP_DASHBOARD_TOKEN'=>$token,'DESKTOP_DASHBOARD_CONTROL_TOKEN'=>bin2hex(random_bytes(32))] as $key=>$value)$env[$key]=$value;
    $web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$artifact.'/http.log','a'],['file',$artifact.'/http.log','a']],$pipes,$application,$env);
    try{
        for($n=0;$n<100;$n++){$socket=@fsockopen('127.0.0.1',$port,$errno,$error,.1);if($socket){fclose($socket);break;}usleep(50000);}
        $browser=proc_open(['node',$fixture.'/customer-directory-browser.cjs'],[['pipe','r'],['file',$artifact.'/browser.log','w'],['file',$artifact.'/browser-errors.log','w']],$browserPipes,$application,$env);
        fwrite($browserPipes[0],json_encode(['origin'=>$origin,'token'=>$token,'sourceCommit'=>$revision,'fixtureRevision'=>$revision,'artifacts'=>$artifact]));fclose($browserPipes[0]);
        $deadline=microtime(true)+150;do{$status=proc_get_status($browser);if(!$status['running'])break;usleep(100000);}while(microtime(true)<$deadline);
        if($status['running'])proc_terminate($browser);$exit=proc_close($browser);$browserStopped=!$status['running'];if($exit<0&&!$status['running'])$exit=$status['exitcode'];
        $output=file_get_contents($artifact.'/browser.log');echo $output;fwrite(STDERR,file_get_contents($artifact.'/browser-errors.log'));
        if($exit!==0){fwrite(STDERR,substr(file_get_contents($artifact.'/http.log'),-10000));if(is_file($profile.'/logs/laravel.log'))fwrite(STDERR,substr(file_get_contents($profile.'/logs/laravel.log'),-10000));throw new RuntimeException('Actual CRM browser failed.');}
        preg_match('/CUSTOMER_DIRECTORY_BROWSER_PROOF (\{[^\r\n]+\})/',$output,$match);$proof=json_decode($match[1]??'null',true,512,JSON_THROW_ON_ERROR);
        $receipt['browserProof']=$proof;$receipt['browserExitCode']=$exit;
        verify(($proof['domInjection']??true)===false&&($proof['providersReplaced']??true)===false&&$proof['newPageErrors']===0,'original CRM script completes real clicks without injected DOM, handlers or providers');
        $writes=$proof['writes'];$commands=DB::table('desktop_dashboard_commands')->orderBy('sequence')->get();
        verify(count($writes)===5&&$commands->count()===4&&DB::table('branch_operation_commands')->count()===4,'create, edit, approval and withdrawal commit once each while the rejected reopening creates no operation');
        $expectedRoutes=['customers.directory-save','customers.directory-save','customers.consent','customers.consent'];$payloads=[];
        foreach($commands as $index=>$row){
            verify($row->route_name===$expectedRoutes[$index]&&$row->command_id===$writes[$index]['payload']['idempotency_key'],'actual CRM journal command matches browser UUID and route at position '.$index);
            $payloads[]=json_decode(Crypt::decryptString($row->command_cipher),true,512,JSON_THROW_ON_ERROR);
        }
        $customer=DB::table('crm_customers')->sole();$receipt['directoryId']=(int)$customer->id;
        verify((int)$customer->id===$proof['directoryId']&&$customer->name==='عميل متصفح تجريبي معدل'&&$customer->address==='عنوان تجريبي معدل'&&$customer->phone==='+201012667788','actual CRM form creates one normalized-phone profile and updates its original identity');
        verify(!$customer->service_consent&&$customer->marketing_consent&&$customer->opted_out_at===null&&(int)$customer->service_consented_by===1&&(int)$customer->marketing_consented_by===1,'explicit service withdrawal preserves marketing approval and records the real signed-in actor');
        verify(str_contains($customer->service_consent_evidence,'إيقاف')&&str_contains($customer->marketing_consent_evidence,'وافق شفهيًا'),'original controller records withdrawal and explicit staff-attested marketing evidence');
        verify(data_get($payloads[0],'facts.customer_before.customer')===null&&!array_key_exists('service_consent',$payloads[0]['values'])&&!array_key_exists('marketing_consent',$payloads[1]['values']),'real profile form capture does not imply messaging consent');
        verify(data_get($payloads[2],'values.consent_scope')==='all'&&data_get($payloads[2],'values.consent_method')==='verbal'&&data_get($payloads[3],'values.consent_scope')==='service'&&data_get($payloads[3],'values.consent_method')==='withdrawal','real consent buttons retain explicit purpose and approval or withdrawal semantics');
        verify(count(DB::table('crm_customer_sources')->get())===1&&DB::table('crm_customer_sources')->value('branch')==='f:100'&&DB::table('crm_customer_sources')->value('channel')==='takeaway','original customer create keeps its selected branch and channel association');
        foreach($preserved as $table=>$hash){$rows=array_map(fn($row)=>(array)$row,DB::table($table)->get()->all());usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));if(hash('sha256',json_encode($rows))!==$hash)throw new RuntimeException('CRM browser unexpectedly changes '.$table);}
        verify(count($preserved)===106,'CRM browser preserves every other imported table, including purchases, money, stock, orders and provider data');
        $receipt['unchangedImportedTables']=count($preserved);$crmCompleted=true;
        echo 'ORIGINAL_CUSTOMER_DIRECTORY_BROWSER_COMPLETE '.json_encode(['sourceRevision'=>$revision,'schemaInventory'=>106,'checks'=>$GLOBALS['count']??0,'fullDashboard'=>false]).PHP_EOL;
    }finally{
        fclose($pipes[0]);proc_terminate($web);$httpStopped=false;for($n=0;$n<100;$n++){if(!proc_get_status($web)['running']){$httpStopped=true;break;}usleep(50000);}proc_close($web);
    }
})();
