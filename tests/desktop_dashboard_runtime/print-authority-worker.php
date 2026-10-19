<?php
// Independent connection intentionally warms RR and Spatie before waiting on the fixed scope.
use Illuminate\Support\Facades\DB;
use App\Services\Dashboard\{DesktopDashboardPrintEffects,DesktopDashboardJournal,TakeawayService};
$application=realpath($argv[1]??'');if(!$application||getenv('DESKTOP_DASHBOARD_LOCAL')!=='true')exit(2);
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$loader=require $application.'/vendor/autoload.php';$loader->setPsr4('App\\',$application.'/app');$app=require $application.'/desktop/bootstrap.php';
$warm=false;
DB::listen(function($query)use(&$warm,$input){
    if($warm||DB::transactionLevel()<1||!str_contains($query->sql,'desktop_dashboard_local_state')||!str_contains($query->sql,'coverage'))return;
    $warm=true;$actor=\App\Models\User::withoutGlobalScopes()->findOrFail($input['actor_id']);
    $permission=$actor->can('order-list');
    echo 'WARM '.json_encode(['connection'=>(int)DB::selectOne('SELECT CONNECTION_ID() AS id')->id,'status'=>$actor->status,'permission'=>$permission,'isolation'=>DB::selectOne('SELECT @@tx_isolation AS isolation')->isolation],JSON_THROW_ON_ERROR).PHP_EOL;flush();
});
try{
    $device=(string)config('desktop_dashboard.device_id');$values=$input['values'];$action=$input['action'];
    if(in_array($action,['journal','saved'],true))$result=app(DesktopDashboardJournal::class)->execute($device,$values['idempotency_key'],$input['actor_id'],'takeaway.checkout',['values'=>$values],[],
        fn()=>app(TakeawayService::class)->checkout($values,\App\Models\User::withoutGlobalScopes()->findOrFail($input['actor_id']),[],true));
    else $result=app(DesktopDashboardPrintEffects::class)->{$action}($device,$values);
    echo json_encode(['status'=>200,'effect_id'=>$result['effect_id']??null,'permit_present'=>isset($result['permit'])],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){
    $status=$error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$error->getStatusCode():500;
    echo json_encode(['status'=>$status,'error'=>get_class($error).': '.$error->getMessage()],JSON_THROW_ON_ERROR).PHP_EOL;
    if($status>=500)exit(1);
}
