<?php
// Child process for real MariaDB lock contention; synthetic private checkout only.
use App\Services\Dashboard\{DesktopDashboardPrintLedger,DesktopDashboardJournal,TakeawayService,DesktopDashboardPrintLedgerSchema};
$application=realpath($argv[1]??'');
if(!$application||getenv('DESKTOP_DASHBOARD_LOCAL')!=='true')exit(2);
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$loader=require $application.'/vendor/autoload.php';$loader->setPsr4('App\\',$application.'/app');
$app=require $application.'/desktop/bootstrap.php';
$publish=static function(string $path,array $value): void {
    $temporary=$path.'.'.bin2hex(random_bytes(8)).'.tmp';$file=fopen($temporary,'xb');if(!$file)throw new RuntimeException('Worker rendezvous could not be created.');
    try{fwrite($file,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($file);if(function_exists('fsync'))fsync($file);}finally{fclose($file);}
    if(!rename($temporary,$path))throw new RuntimeException('Worker rendezvous could not be published.');
};
$db=app(DesktopDashboardPrintLedgerSchema::class)->connection();$db->statement('SET SESSION innodb_lock_wait_timeout=20');
$publish($input['ready_path'],['format'=>1,'connection_id'=>(int)$db->selectOne('SELECT CONNECTION_ID() AS id')->id]);
try{
    $actor=\App\Models\User::withoutGlobalScopes()->findOrFail(1);$device=(string)config('desktop_dashboard.device_id');$values=$input['values'];
    $result=app(DesktopDashboardPrintLedger::class)->atomic($device,$values['idempotency_key'],'takeaway.checkout',1,'f:100',
        fn()=>app(DesktopDashboardJournal::class)->execute($device,$values['idempotency_key'],1,'takeaway.checkout',['values'=>$values],[],fn()=>app(TakeawayService::class)->checkout($values,$actor,[],true)));
    $publish($input['result_path'],['status'=>200,'result'=>$result]);
}catch(Throwable $error){
    $status=$error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$error->getStatusCode():500;
    $publish($input['result_path'],['status'=>$status,'error'=>get_class($error).': '.$error->getMessage()]);
    if($status!==409)exit(1);
}
