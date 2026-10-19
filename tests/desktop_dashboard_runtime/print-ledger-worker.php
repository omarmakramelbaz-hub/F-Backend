<?php
// Child process for real MariaDB lock contention; synthetic private checkout only.
use App\Services\Dashboard\{DesktopDashboardPrintLedger,DesktopDashboardJournal,TakeawayService};
$application=realpath($argv[1]??'');
if(!$application||getenv('DESKTOP_DASHBOARD_LOCAL')!=='true')exit(2);
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$loader=require $application.'/vendor/autoload.php';$loader->setPsr4('App\\',$application.'/app');
$app=require $application.'/desktop/bootstrap.php';
echo "READY\n";flush();
try{
    $actor=\App\Models\User::withoutGlobalScopes()->findOrFail(1);$device=(string)config('desktop_dashboard.device_id');$values=$input['values'];
    $result=app(DesktopDashboardPrintLedger::class)->atomic($device,$values['idempotency_key'],'takeaway.checkout',1,'f:100',
        fn()=>app(DesktopDashboardJournal::class)->execute($device,$values['idempotency_key'],1,'takeaway.checkout',['values'=>$values],[],fn()=>app(TakeawayService::class)->checkout($values,$actor,[],true)));
    echo json_encode(['status'=>200,'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){
    $status=$error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$error->getStatusCode():500;
    echo json_encode(['status'=>$status,'error'=>get_class($error).': '.$error->getMessage()],JSON_THROW_ON_ERROR).PHP_EOL;
    if($status!==409)exit(1);
}
