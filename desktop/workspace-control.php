<?php
// This file is routed only through the existing loopback/host/app-header guard.
try{
    $control=getenv('DESKTOP_DASHBOARD_CONTROL_TOKEN');
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!is_string($control)||$control===''
        ||!hash_equals($control,(string)($_SERVER['HTTP_X_FASAKHANSTA_CONTROL']??''))){http_response_code(403);exit;}
    $authorization=$_SERVER['HTTP_AUTHORIZATION']??'';
    if(!is_string($authorization)||!preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$match)){http_response_code(403);exit;}
    $raw=file_get_contents('php://input',false,null,0,20*1024*1024+1);
    if(!is_string($raw)||strlen($raw)>20*1024*1024){http_response_code(413);exit;}
    $value=json_decode($raw,true,128,JSON_THROW_ON_ERROR);if(!is_array($value))throw new \InvalidArgumentException;
    $app=require __DIR__.'/bootstrap.php';
    $result=$app->make(\App\Services\Dashboard\DesktopDashboardWorkspaceControl::class)->dispatch($value,$match[1]);
    header('Content-Type: application/json');header('Cache-Control: private, no-store');
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(\Throwable $error){
    $status=$error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$error->getStatusCode():500;
    if($error instanceof \Illuminate\Validation\ValidationException||$error instanceof \JsonException||$error instanceof \InvalidArgumentException)$status=422;
    http_response_code($status);header('Content-Type: application/json');header('Cache-Control: private, no-store');
    echo json_encode(['message'=>'تعذر تنفيذ أمر مساحة عمل الجهاز؛ البيانات والعمليات المحفوظة لم تُحذف.'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
