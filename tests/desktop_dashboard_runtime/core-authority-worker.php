<?php
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardReconciliation,DesktopDashboardRemoteAttempts,DesktopDashboardJournal};
use Illuminate\Http\Request;
$application=realpath($argv[1]??'');if(!$application||getenv('DESKTOP_DASHBOARD_LOCAL')!=='true')exit(2);
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$loader=require $application.'/vendor/autoload.php';$loader->setPsr4('App\\',$application.'/app');$app=require $application.'/desktop/bootstrap.php';
config(['desktop_dashboard.enabled'=>true,'desktop_dashboard.local'=>$input['action']==='local','desktop_dashboard.device_id'=>$input['action']==='local'?$input['device']['id']:'']);
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-20T05:00:00Z'));
DB::beginTransaction();$actor=User::withoutGlobalScopes()->findOrFail(12);$permission=$actor->can('order-list');auth('admin')->setUser($actor);
echo 'WARM '.json_encode(['connection'=>(int)DB::selectOne('SELECT CONNECTION_ID() AS id')->id,'status'=>$actor->status,'permission'=>$permission,'isolation'=>DB::selectOne('SELECT @@tx_isolation AS isolation')->isolation],JSON_THROW_ON_ERROR).PHP_EOL;flush();
try{
    $device=(object)$input['device'];$attempts=app(DesktopDashboardRemoteAttempts::class);
    if($input['action']==='ordinary')app(DesktopDashboardReconciliation::class)->ingest($device,$input['envelope']);
    elseif($input['action']==='native-decide')$attempts->decide($device,$input['reservation'],false);
    elseif($input['action']==='local')app(DesktopDashboardJournal::class)->execute($device->id,$input['local_values']['idempotency_key'],12,'delivery-companies.save',['values'=>$input['local_values']],[],fn()=>throw new RuntimeException('A saved CORE result reran business work'));
    else{
        $route=clone app('router')->getRoutes()->getByName('delivery-companies.save');$request=Request::create(url($input['reservation']['path']),'POST',$input['native_values']);$route->bind($request);$request->setRouteResolver(fn()=>$route);
        $request->headers->set('X-Fasakhansta-Remote-Attempt',$input['proof']['id']);$request->headers->set('X-Fasakhansta-Remote-Capability',$input['proof']['capability']);
        app()->instance('request',$request);\Illuminate\Support\Facades\Facade::clearResolvedInstance('request');app('url')->setRequest($request);
        $attempts->handle($request,fn()=>throw new RuntimeException('A saved native CORE result reran its controller'));
    }
    DB::commit();echo json_encode(['status'=>200],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){if(DB::transactionLevel())DB::rollBack();$status=$error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$error->getStatusCode():500;echo json_encode(['status'=>$status,'error'=>get_class($error).': '.$error->getMessage()],JSON_THROW_ON_ERROR).PHP_EOL;if($status>=500)exit(1);}
