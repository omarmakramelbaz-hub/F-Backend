<?php
// Included by legacy.php after its disposable account/schema import. Only this
// test router exposes a data-free probe; ordinary requests use desktop/router.php.
verify(config('settings.auto_discover_settings')===[],
    'local CLI keeps the explicit settings binding without scanning unrelated App classes');
verify(class_exists(\App\Console\Commands\CouponWheelCommand::class,false),
    'native CLI still discovers original Artisan commands for import and replay');
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
$bootstrapWeb=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$bootstrapPort,'-t',$application.'/public',__DIR__.'/bootstrap-http-router.php'],
    [['pipe','r'],['file',$profile.'/bootstrap-http.log','a'],['file',$profile.'/bootstrap-http.log','a']],$bootstrapPipes,$application,$bootstrapEnv);
$bootstrapCookies=[];
$bootstrapHttp=function(string $path,?array $form=null)use($bootstrapOrigin,$bootstrapToken,&$bootstrapCookies){
    $headers=['X-Fasakhansta-Desktop: '.$bootstrapToken];
    if($bootstrapCookies)$headers[]='Cookie: '.implode('; ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($bootstrapCookies),$bootstrapCookies));
    if($form!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
    $context=stream_context_create(['http'=>['method'=>$form===null?'GET':'POST','header'=>implode("\r\n",$headers),
        'content'=>$form===null?'':http_build_query($form),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>15]]);
    $started=microtime(true);$body=@file_get_contents($bootstrapOrigin.$path,false,$context);$elapsed=microtime(true)-$started;
    $responseHeaders=$http_response_header??[];preg_match('/^HTTP\/\S+ (\d+)/',$responseHeaders[0]??'',$status);
    foreach($responseHeaders as $header)if(preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$header,$match))$bootstrapCookies[$match[1]]=$match[2];
    return [(int)($status[1]??0),$body,$elapsed];
};
try{
    for($attempt=0;$attempt<100;$attempt++){[$status]=$bootstrapHttp('/_desktop/health');if($status===200)break;usleep(50000);}
    verify($status===200,'private real PHP HTTP bootstrap fixture starts through the original loopback router');
    [$status,$body]=$bootstrapHttp('/__desktop_bootstrap_probe');$optimized=json_decode($body?:'',true,512,JSON_THROW_ON_ERROR);
    verify($status===200&&$optimized['sapi']==='cli-server'&&$optimized['boundPath']==='/__desktop_bootstrap_probe',
        'HTTP providers bootstrap with the actual captured request instead of a console request');
    verify($optimized['discovery']===[]&&$optimized['settings']===[\App\Models\GeneralSettings::class],
        'HTTP settings resolution preserves GeneralSettings without automatic App discovery');
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
        [$status,$page,$seconds]=$bootstrapHttp('/admin/login');$loginSeconds[]=$seconds;
        preg_match('/name="_token" value="([^"]+)"/',$page?:'',$csrf);
        verify($status===200&&!empty($csrf[1])&&str_contains($page,'dashboard-login-form'),
            'the original local login renders its real session and CSRF form after lean bootstrap '.($requestIndex+1));
    }
    $form=['email'=>'branch@test.invalid','password'=>'Fixture123'];
    verify($bootstrapHttp('/admin/signin',$form+['_token'=>str_repeat('x',40)])[0]===419,
        'wrong CSRF still fails before the unchanged original signin controller');
    verify($bootstrapHttp('/admin/signin',$form)[0]===419,
        'missing CSRF still fails before the unchanged original signin controller');
    [$status]=$bootstrapHttp('/admin/signin',$form+['_token'=>$csrf[1]]);
    verify($status===302,'the genuine local session token still signs in through the original controller');
    verify($bootstrapHttp('/admin/login')[0]===302,'the accepted original signin persists its authenticated local session');
    echo 'DESKTOP_HTTP_BOOTSTRAP_COMPLETE '.json_encode([
        'actualPhpServer'=>true,'originalLogin'=>true,'wrongCsrfRejected'=>true,'missingCsrfRejected'=>true,
        'nativeCliPreserved'=>true,'serverDiscoveryPreserved'=>true,'optimizedAppFiles'=>$optimized['includedAppFiles'],
        'oldAppFiles'=>$baseline['includedAppFiles'],'loginSeconds'=>$loginSeconds,
    ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}finally{
    if(is_resource($bootstrapPipes[0]??null))fclose($bootstrapPipes[0]);
    if(is_resource($bootstrapWeb)){proc_terminate($bootstrapWeb);proc_close($bootstrapWeb);}
}
