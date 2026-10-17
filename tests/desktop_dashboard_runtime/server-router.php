<?php
// Disposable test server only: real API routes, no production environment or deployment.
$application=getenv('DESKTOP_TEST_APPLICATION');
if(!$application||getenv('APP_ENV')!=='desktop'){http_response_code(403);exit;}
$app=require $application.'/desktop/bootstrap.php';
config(['desktop_dashboard.local'=>false]);
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$request=Illuminate\Http\Request::capture();$response=$kernel->handle($request);
$response->send();$kernel->terminate($request,$response);
