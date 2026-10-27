<?php
// Native supervisor endpoint. Its credential is distinct from the browser asset credential.
use App\Services\Dashboard\DesktopDashboardControl;

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
    // A shared workspace uses the separate root/session capability protocol.
    abort_if(getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL')==='true',403);
    $device=(string)config('desktop_dashboard.device_id');
    $result=$app->make(DesktopDashboardControl::class)->dispatch($device,$value);
    header('Content-Type: application/json');header('Cache-Control: no-store');
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(Throwable $error){
    $status=$error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$error->getStatusCode():500;
    if(in_array($value['action']??'', ['outbox-review','outbox-retry'],true)&&$error instanceof \Illuminate\Validation\ValidationException)$status=422;
    http_response_code($status);header('Content-Type: application/json');header('Cache-Control: no-store');
    $message='تعذر تحديث سجل المزامنة المحلي؛ العمليات محفوظة.';
    if(in_array($value['action']??'', ['outbox-review','outbox-retry'],true))$message=match($status){
        403=>'ربط الجهاز أو صلاحية حسابه لم تعد تسمح بالمراجعة أو إعادة المحاولة؛ العمليات محفوظة.',
        404=>'صلاحية أحد الفروع المرتبطة تغيّرت؛ يلزم مراجعتها على السيرفر، والعمليات محفوظة.',
        409=>'حالة العملية أو نسخة بيانات الجهاز تغيّرت؛ افتح المراجعة من جديد. لا يمكن تجاوز التعارض.',
        422=>'بيانات طلب المراجعة غير صالحة؛ افتح المراجعة من جديد.',default=>$message};
    echo json_encode(['message'=>$message],JSON_UNESCAPED_UNICODE);
}
