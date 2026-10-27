<?php
// Loopback-only fixture transport. Authentication and all business requests use the original HTTP kernel.
require __DIR__.'/workspace-native-fixture.php';
try{
    WorkspaceNativeServerFixture::need(PHP_SAPI==='cli-server'&&($_SERVER['REMOTE_ADDR']??null)==='127.0.0.1');
    $file=getenv('DESKTOP_WORKSPACE_NATIVE_FIXTURE_CONFIG');WorkspaceNativeServerFixture::need(is_string($file)&&$file!=='');
    $config=WorkspaceNativeServerFixture::config($file);$origin=WorkspaceNativeServerFixture::origin($config['origin']);
    $host=$origin['host'].':'.$origin['port'];WorkspaceNativeServerFixture::need(($_SERVER['HTTP_HOST']??null)===$host);
    // This router is reached only through the acceptance runner's fixed TLS gateway.
    $_SERVER['HTTPS']='on';$_SERVER['REQUEST_SCHEME']='https';$_SERVER['SERVER_NAME']=$origin['host'];$_SERVER['SERVER_PORT']=(string)$origin['port'];
    $app=WorkspaceNativeServerFixture::boot($config);
    if(($_SERVER['REQUEST_URI']??null)==='/__workspace_native_health'&&($_SERVER['REQUEST_METHOD']??null)==='GET'){
        header('Content-Type: application/json');header('Cache-Control: no-store');echo '{"ok":true,"protocol":2}';return;
    }
    $kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);$request=\Illuminate\Http\Request::capture();
    $response=$kernel->handle($request);
    if($request->isMethod('POST')&&$request->path()==='admin/signin')
        WorkspaceNativeServerFixture::assertOnlinePasswordIntegrity($config);
    $response->send();$kernel->terminate($request,$response);
}catch(Throwable $error){http_response_code(503);header('Content-Type: application/json');header('Cache-Control: no-store');
    error_log('WORKSPACE_NATIVE_SERVER_ROUTER_FAILED '.get_class($error).' '.$error->getMessage());echo '{"error":"WORKSPACE_NATIVE_SERVER_ROUTER_FAILED"}';}
