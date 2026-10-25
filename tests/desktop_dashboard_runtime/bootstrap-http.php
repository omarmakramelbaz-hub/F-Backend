<?php
// Included by legacy.php after its disposable account/schema import. Only this
// test router exposes a data-free probe; ordinary requests use desktop/router.php.
verify(config('settings.auto_discover_settings')===[],
    'local CLI keeps the explicit settings binding without scanning unrelated App classes');
verify(class_exists(\App\Console\Commands\CouponWheelCommand::class,false),
    'native CLI still discovers original Artisan commands for import and replay');
verify(auth('admin')->getProvider() instanceof FasakhanstaDesktopRememberProvider,
    'the actual CLI admin guard resolves the private native provider after bootstrap');
$previousLocal=getenv('DESKTOP_DASHBOARD_LOCAL');
try{
    putenv('DESKTOP_DASHBOARD_LOCAL=false');$serverSettings=require $application.'/config/settings.php';
    verify($serverSettings['auto_discover_settings']===[app()->path()]
        &&$serverSettings['settings']===[\App\Models\GeneralSettings::class],
        'ordinary server settings discovery and the explicit settings class stay unchanged');
}finally{putenv('DESKTOP_DASHBOARD_LOCAL='.$previousLocal);}
$reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
$bootstrapPort=(int)substr(strrchr(stream_socket_get_name($reservation,false),':'),1);fclose($reservation);
$bootstrapOrigin='http://127.0.0.1:'.$bootstrapPort;$bootstrapToken=bin2hex(random_bytes(32));
$bootstrapEnv=$env;$bootstrapEnv['DESKTOP_TEST_APPLICATION']=$application;
$bootstrapEnv['APP_URL']=$bootstrapOrigin;$bootstrapEnv['DESKTOP_DASHBOARD_ORIGIN']=$bootstrapOrigin;
$bootstrapEnv['DESKTOP_DASHBOARD_TOKEN']=$bootstrapToken;
$bootstrapEnv['DESKTOP_DASHBOARD_AUTH_STORAGE']=$profile.'/auth';
$bootstrapWeb=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$bootstrapPort,'-t',$application.'/public',__DIR__.'/bootstrap-http-router.php'],
    [['pipe','r'],['file',$profile.'/bootstrap-http.log','a'],['file',$profile.'/bootstrap-http.log','a']],$bootstrapPipes,$application,$bootstrapEnv);
$bootstrapCookies=[];
$bootstrapHttp=function(string $path,?array $form=null)use(&$bootstrapOrigin,$bootstrapToken,&$bootstrapCookies){
    $headers=['X-Fasakhansta-Desktop: '.$bootstrapToken];
    if($bootstrapCookies)$headers[]='Cookie: '.implode('; ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($bootstrapCookies),$bootstrapCookies));
    if($form!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
    $context=stream_context_create(['http'=>['method'=>$form===null?'GET':'POST','header'=>implode("\r\n",$headers),
        'content'=>$form===null?'':http_build_query($form),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>15]]);
    $started=microtime(true);$body=@file_get_contents($bootstrapOrigin.$path,false,$context);$elapsed=microtime(true)-$started;
    $responseHeaders=$http_response_header??[];preg_match('/^HTTP\/\S+ (\d+)/',$responseHeaders[0]??'',$status);
    foreach($responseHeaders as $header)if(preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$header,$match))$bootstrapCookies[$match[1]]=$match[2];
    return [(int)($status[1]??0),$body,$elapsed,$responseHeaders];
};
try{
    for($attempt=0;$attempt<100;$attempt++){[$status]=$bootstrapHttp('/_desktop/health');if($status===200)break;usleep(50000);}
    verify($status===200,'private real PHP HTTP bootstrap fixture starts through the original loopback router');
    [$status,$body]=$bootstrapHttp('/__desktop_bootstrap_probe');$optimized=json_decode($body?:'',true,512,JSON_THROW_ON_ERROR);
    verify($status===200&&$optimized['sapi']==='cli-server'&&$optimized['boundPath']==='/__desktop_bootstrap_probe',
        'HTTP providers bootstrap with the actual captured request instead of a console request');
    verify($optimized['discovery']===[]&&$optimized['settings']===[\App\Models\GeneralSettings::class],
        'HTTP settings resolution preserves GeneralSettings without automatic App discovery');
    verify($optimized['privateProvider']===FasakhanstaDesktopRememberProvider::class,
        'the actual HTTP admin guard resolves the private native provider after bootstrap');
    verify($optimized['consoleCommandLoaded']===false&&$optimized['unrelatedWorkflowLoaded']===false,
        'an ordinary HTTP bootstrap does not eagerly load Artisan commands or unrelated provider workflows');
    [$status,$body]=$bootstrapHttp('/__desktop_bootstrap_probe?baseline=1');$baseline=json_decode($body?:'',true,512,JSON_THROW_ON_ERROR);
    verify($status===200&&$baseline['consoleCommandLoaded']&&$baseline['unrelatedWorkflowLoaded']
        &&$baseline['includedAppFiles']>$optimized['includedAppFiles']+100,
        'reconstructing the old bootstrap loads more than one hundred avoidable App files');
    [$status,$body]=$bootstrapHttp('/__desktop_bootstrap_probe');$repeated=json_decode($body?:'',true,512,JSON_THROW_ON_ERROR);
    verify($status===200&&$repeated['includedAppFiles']===$optimized['includedAppFiles']
        &&!$repeated['consoleCommandLoaded']&&!$repeated['unrelatedWorkflowLoaded'],
        'each following HTTP request retains the lean bootstrap without relying on a previous PHP request cache');
    $loginSeconds=[];
    for($requestIndex=0;$requestIndex<3;$requestIndex++){
        [$status,$page,$seconds,$loginHeaders]=$bootstrapHttp('/admin/login');$loginSeconds[]=$seconds;
        preg_match('/name="_token" value="([^"]+)"/',$page?:'',$csrf);
        verify($status===200&&!empty($csrf[1])&&str_contains($page,'dashboard-login-form'),
            'the original local login renders its real session and CSRF form after lean bootstrap '.($requestIndex+1));
        if($requestIndex===0){
            $sessionHeaders=array_values(array_filter($loginHeaders,fn($header)=>preg_match('/^Set-Cookie: [^=;]+_session=/i',$header)));
            verify(count($sessionHeaders)===1&&stripos($sessionHeaders[0],'httponly')!==false
                &&stripos($sessionHeaders[0],'expires=')===false&&stripos($sessionHeaders[0],'max-age=')===false,
                'the actual primary admin session cookie is HttpOnly and expires with the client session');
        }
    }
    $originalRememberToken=DB::table('users')->where('id',10)->value('remember_token');
    DB::table('users')->where('id',10)->update(['remember_token'=>str_repeat('L',60)]);
    $authBefore=(array)DB::table('users')->where('id',10)->first(['password','remember_token']);
    $form=['email'=>'branch@test.invalid','password'=>'Fixture123'];
    verify($bootstrapHttp('/admin/signin',$form+['_token'=>str_repeat('x',40)])[0]===419,
        'wrong CSRF still fails before the unchanged original signin controller');
    verify($bootstrapHttp('/admin/signin',$form)[0]===419,
        'missing CSRF still fails before the unchanged original signin controller');
    [$status]=$bootstrapHttp('/admin/signin',$form+['_token'=>$csrf[1]]);
    verify($status===302,'the genuine local session token still signs in through the original controller');
    verify($bootstrapHttp('/admin/login')[0]===302,'the accepted original signin persists its authenticated local session');
    $rememberCookies=fn(array $jar)=>array_filter($jar,
        fn($name)=>preg_match('/^remember_admin_[a-f0-9]{40}$/D',$name)===1,ARRAY_FILTER_USE_KEY);
    verify($rememberCookies($bootstrapCookies)===[],
        'unchecked original signin does not issue an admin remember cookie');
    // Model closing the native client by discarding its session cookies. Do not
    // call logout: that would intentionally revoke the server's remember token.
    $bootstrapCookies=[];
    [$status,$page]=$bootstrapHttp('/admin/login');
    preg_match('/name="_token" value="([^"]+)"/',$page?:'',$rememberCsrf);
    verify($status===200&&!empty($rememberCsrf[1]),
        'unchecked signin requires the original login form again after client session cookies are discarded');
    verify($bootstrapHttp('/admin/signin',$form+['_token'=>$rememberCsrf[1],'remember'=>'1'])[0]===302,
        'checked remember signs in through the unchanged original controller with genuine CSRF');
    $recallerOnly=$rememberCookies($bootstrapCookies);
    verify(count($recallerOnly)===1&&strlen((string)reset($recallerOnly))>40,
        'checked original signin issues exactly one real encrypted admin recaller');
    verify((array)DB::table('users')->where('id',10)->first(['password','remember_token'])===$authBefore,
        'remembered signin preserves the synchronized password hash and remember_token byte for byte');
    $bootstrapCookies=$recallerOnly;
    verify($bootstrapHttp('/admin/login')[0]===302,
        'a fresh GET restores the remembered account with only its admin recaller and no normal session cookie or signin POST');
    // A full snapshot/delta creates a different database and storage generation.
    // Clone this disposable imported dataset with its redacted remember_token,
    // keeping only the profile-wide private capability and original recaller.
    $rememberDatabase='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));
    $pdo->exec('CREATE DATABASE `'.$rememberDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(function()use($pdo,$rememberDatabase){$pdo->exec('DROP DATABASE IF EXISTS `'.$rememberDatabase.'`');});
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach($pdo->query('SHOW TABLES FROM `'.$stage.'`')->fetchAll(PDO::FETCH_COLUMN) as $table){
        $pdo->exec('CREATE TABLE `'.$rememberDatabase.'`.`'.$table.'` LIKE `'.$stage.'`.`'.$table.'`');
        $pdo->exec('INSERT INTO `'.$rememberDatabase.'`.`'.$table.'` SELECT * FROM `'.$stage.'`.`'.$table.'`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec('UPDATE `'.$rememberDatabase.'`.`users` SET remember_token=NULL');
    if(is_resource($bootstrapPipes[0]))fclose($bootstrapPipes[0]);proc_terminate($bootstrapWeb);proc_close($bootstrapWeb);
    $generationStorage=$profile.'/remember-generation/storage';
    foreach(['app/public','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache','private'] as $dir)mkdir($generationStorage.'/'.$dir,0700,true);
    $reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
    $bootstrapPort=(int)substr(strrchr(stream_socket_get_name($reservation,false),':'),1);fclose($reservation);
    $bootstrapOrigin='http://127.0.0.1:'.$bootstrapPort;
    $bootstrapEnv['DB_DATABASE']=$rememberDatabase;$bootstrapEnv['DESKTOP_DASHBOARD_STORAGE']=$generationStorage;
    $bootstrapEnv['APP_URL']=$bootstrapOrigin;$bootstrapEnv['DESKTOP_DASHBOARD_ORIGIN']=$bootstrapOrigin;
    foreach(['APP_CONFIG_CACHE'=>'config.php','APP_PACKAGES_CACHE'=>'packages.php','APP_SERVICES_CACHE'=>'services.php','APP_ROUTES_CACHE'=>'routes.php'] as $key=>$file)
        $bootstrapEnv[$key]=$generationStorage.'/bootstrap/cache/'.$file;
    $bootstrapWeb=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$bootstrapPort,'-t',$application.'/public',__DIR__.'/bootstrap-http-router.php'],
        [['pipe','r'],['file',$profile.'/bootstrap-http.log','a'],['file',$profile.'/bootstrap-http.log','a']],$bootstrapPipes,$application,$bootstrapEnv);
    for($attempt=0;$attempt<100;$attempt++){[$status]=$bootstrapHttp('/_desktop/health');if($status===200)break;usleep(50000);}
    $bootstrapCookies=$recallerOnly;
    verify($status===200&&$bootstrapHttp('/admin/login')[0]===302,
        'only the real recaller restores login after snapshot database replacement and empty session storage in a new generation');
    $oldPassword=$authBefore['password'];
    $change=$pdo->prepare('UPDATE `'.$rememberDatabase.'`.`users` SET password=? WHERE id=10');$change->execute([password_hash('ChangedFixture123',PASSWORD_BCRYPT)]);
    $bootstrapCookies=$recallerOnly;verify($bootstrapHttp('/admin/login')[0]===200,
        'a current synchronized password change invalidates the old private remember capability');
    $change->execute([$oldPassword]);
    $pdo->exec("UPDATE `".$rememberDatabase."`.`users` SET status='disabled' WHERE id=10");
    $bootstrapCookies=$recallerOnly;verify($bootstrapHttp('/admin/login')[0]===200,
        'a currently disabled account cannot restore a remembered local session');
    $pdo->exec("UPDATE `".$rememberDatabase."`.`users` SET status='accepted' WHERE id=10");
    $pdo->exec('UPDATE `'.$rememberDatabase.'`.`desktop_dashboard_local_state` SET actor_id=11');
    $bootstrapCookies=$recallerOnly;verify($bootstrapHttp('/admin/login')[0]===200,
        'a private remember capability cannot restore a different enrolled actor');
    $pdo->exec('UPDATE `'.$rememberDatabase.'`.`desktop_dashboard_local_state` SET actor_id=10');
    $changeDevice=$pdo->prepare('UPDATE `'.$rememberDatabase.'`.`desktop_dashboard_local_state` SET device_id=?');
    $changeDevice->execute([(string)\Illuminate\Support\Str::uuid()]);
    $bootstrapCookies=$recallerOnly;verify($bootstrapHttp('/admin/login')[0]===200,
        'a private remember capability cannot restore a foreign enrolled device');
    $changeDevice->execute([$bootstrapEnv['DESKTOP_DASHBOARD_DEVICE_ID']]);
    $recordFiles=glob($profile.'/auth/*.enc');verify(count($recordFiles)===1,
        'remembering the enrolled account creates one profile-wide private encrypted capability');
    $rememberBytes=file_get_contents($recordFiles[0]);
    $rememberRecord=json_decode(app('encrypter')->decryptString($rememberBytes),true,8,JSON_THROW_ON_ERROR);
    verify(!isset($rememberRecord['token'])&&!isset($rememberRecord['password'])&&!str_contains($rememberBytes,$oldPassword)
        &&isset($rememberRecord['token_proof'],$rememberRecord['password_binding']),
        'the private encrypted capability stores only token and password bindings without raw credentials');
    file_put_contents($recordFiles[0],'malformed encrypted capability');
    $bootstrapCookies=$recallerOnly;verify($bootstrapHttp('/admin/login')[0]===200,
        'a malformed private capability fails closed without trusting a synchronized user token');
    file_put_contents($recordFiles[0],$rememberBytes);
    $recallerName=array_key_first($recallerOnly);$invalidRecaller=(string)$recallerOnly[$recallerName];
    $invalidRecaller=($invalidRecaller[0]==='A'?'B':'A').substr($invalidRecaller,1);
    $bootstrapCookies=[$recallerName=>$invalidRecaller];
    verify($bootstrapHttp('/admin/login')[0]===200,
        'an invalid encrypted recaller cannot restore an authenticated account after client restart');
    $bootstrapCookies=$recallerOnly;
    verify($bootstrapHttp('/admin/login')[0]===302,
        'the intact private capability still authenticates after rejected foreign or malformed inputs');
    verify($bootstrapHttp('/admin/adminLogout')[0]===302&&glob($profile.'/auth/*.enc')===[],
        'explicit original logout revokes the private capability even though synchronized remember_token stays null');
    $bootstrapCookies=$recallerOnly;
    verify($bootstrapHttp('/admin/login')[0]===200,
        'a copied old recaller cannot restore login after explicit logout revocation');
    $oldAuthRoot=getenv('DESKTOP_DASHBOARD_AUTH_STORAGE');
    try{
        putenv('DESKTOP_DASHBOARD_AUTH_STORAGE='.$profile.'/auth');
        $provider=app('auth')->createUserProvider('admins');
        $proofUser=$provider->retrieveByCredentials(['email'=>'branch@test.invalid']);
        $proofToken=\Illuminate\Support\Str::random(60);$provider->updateRememberToken($proofUser,$proofToken);
        verify($proofUser->getRememberToken()===$proofToken&&!$proofUser->isDirty('remember_token'),
            'the private provider exposes its generated token only in memory without marking the user row dirty');
        $nextProof=\Illuminate\Support\Str::random(60);$provider->updateRememberToken($proofUser,$nextProof);
        verify($provider->retrieveByToken(10,$proofToken)===null&&$provider->retrieveByToken(10,$nextProof)!==null,
            'atomic replacement of an existing private record revokes the old proof and accepts only the new proof');
        $proofToken=$nextProof;
        $oldName=$proofUser->name;$proofUser->timestamps=false;$proofUser->name=$oldName.' proof';
        $proofUser->saveQuietly();
        verify((array)DB::table('users')->where('id',10)->first(['password','remember_token'])===$authBefore,
            'a later unrelated Eloquent save cannot persist the transient private remember token or rehash the synchronized password');
        $proofUser->name=$oldName;$proofUser->saveQuietly();
        app('events')->dispatch(new \Illuminate\Auth\Events\CurrentDeviceLogout('admin',$proofUser));
        verify(glob($profile.'/auth/*.enc')===[]&&$provider->retrieveByToken(10,$proofToken)===null,
            'the actual current-device logout event revokes the private capability without modifying synchronized credentials');
    }finally{
        putenv($oldAuthRoot===false?'DESKTOP_DASHBOARD_AUTH_STORAGE':'DESKTOP_DASHBOARD_AUTH_STORAGE='.$oldAuthRoot);
    }
    DB::table('users')->where('id',10)->update(['remember_token'=>$originalRememberToken]);
    $bootstrapCookies=[];
    echo 'DESKTOP_HTTP_BOOTSTRAP_COMPLETE '.json_encode([
        'actualPhpServer'=>true,'originalLogin'=>true,'wrongCsrfRejected'=>true,'missingCsrfRejected'=>true,
        'nativeCliPreserved'=>true,'serverDiscoveryPreserved'=>true,'optimizedAppFiles'=>$optimized['includedAppFiles'],
        'oldAppFiles'=>$baseline['includedAppFiles'],'loginSeconds'=>$loginSeconds,
        'uncheckedReopenRequiresLogin'=>true,'checkedReopenUsesRecaller'=>true,'invalidRecallerRejected'=>true,
        'rememberAcrossGenerations'=>true,'credentialRowsUnchanged'=>true,'passwordChangeRejected'=>true,
        'disabledActorRejected'=>true,'foreignActorRejected'=>true,'foreignDeviceRejected'=>true,'malformedPrivateRecordRejected'=>true,
        'primarySessionCookieTransient'=>true,
        'explicitLogoutRevokes'=>true,
        'currentDeviceLogoutRevokes'=>true,'legacyDatabaseTokenCannotSkipPrivateProof'=>true,'unrelatedSaveKeepsCredentials'=>true,
        'actualProviderResolved'=>true,'atomicProofRotation'=>true,
    ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}finally{
    $bootstrapCookies=[];
    if(is_resource($bootstrapPipes[0]??null))fclose($bootstrapPipes[0]);
    if(is_resource($bootstrapWeb)){proc_terminate($bootstrapWeb);proc_close($bootstrapWeb);}
}
