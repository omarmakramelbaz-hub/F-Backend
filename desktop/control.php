<?php
// Native supervisor endpoint. Its credential is distinct from the browser asset credential.
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardRefresh};

try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'
        || !getenv('DESKTOP_DASHBOARD_CONTROL_TOKEN')
        || !hash_equals(getenv('DESKTOP_DASHBOARD_CONTROL_TOKEN'),$_SERVER['HTTP_X_FASAKHANSTA_CONTROL']??'')){
        http_response_code(403);exit;
    }
    $input=file_get_contents('php://input',false,null,0,20*1024*1024+1);
    if(strlen($input)>20*1024*1024){http_response_code(413);exit;}
    $value=json_decode($input,true,128,JSON_THROW_ON_ERROR);
    $app=require __DIR__.'/bootstrap.php';
    $device=(string)config('desktop_dashboard.device_id');
    $journal=$app->make(DesktopDashboardJournal::class);
    switch($value['action']??''){
        case 'pending':$result=['commands'=>$journal->pending($device),'counts'=>$journal->counts($device)];break;
        case 'acknowledge':$journal->acknowledge($device,(string)($value['command_id']??''),(array)($value['receipt']??[]));$result=['counts'=>$journal->counts($device)];break;
        case 'failed':$journal->failed($device,(string)($value['command_id']??''),(string)($value['message']??''),($value['conflict']??false)===true);$result=['counts'=>$journal->counts($device)];break;
        case 'refresh-begin':$result=$app->make(DesktopDashboardRefresh::class)->begin($device,(string)($value['refresh_id']??''),(string)($value['token']??''));break;
        case 'refresh-cancel':$result=$app->make(DesktopDashboardRefresh::class)->cancel($device,(string)($value['refresh_id']??''),(string)($value['token']??''));break;
        case 'refresh-status':$result=$app->make(DesktopDashboardRefresh::class)->inspect($device,(string)($value['refresh_id']??''),(string)($value['token']??''));break;
        default:abort(422,'أمر التشغيل المحلي غير معروف.');
    }
    header('Content-Type: application/json');header('Cache-Control: no-store');
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(Throwable $error){
    $status=$error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$error->getStatusCode():500;
    http_response_code($status);header('Content-Type: application/json');header('Cache-Control: no-store');
    echo json_encode(['message'=>'تعذر تحديث سجل المزامنة المحلي؛ العمليات محفوظة.'],JSON_UNESCAPED_UNICODE);
}
