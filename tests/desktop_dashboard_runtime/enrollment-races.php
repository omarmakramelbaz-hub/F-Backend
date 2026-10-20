<?php
// Real authenticated objects and independent MariaDB replacement transactions.
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardReconciliation,DesktopDashboardRemoteAttempts,DesktopDashboardBootstrap,DesktopDashboardMedia};
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
(function()use($application,$port,$pdo,$database,$profile){
    $start=$GLOBALS['count'];$savedClock=\Carbon\Carbon::getTestNow();$savedConfig=['desktop_dashboard.local'=>config('desktop_dashboard.local'),'desktop_dashboard.device_id'=>config('desktop_dashboard.device_id')];
    $guard=auth('admin');$previous=$guard->getUser();
    try{
        config(['desktop_dashboard.local'=>false,'desktop_dashboard.device_id'=>'']);\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-20T05:00:00Z'));
        $guard->setUser(User::withoutGlobalScopes()->findOrFail(1));
        foreach(['2026_10_08_160000_create_desktop_dashboard_local_state.php'=>'CreateDesktopDashboardLocalState','2026_10_08_210000_create_desktop_dashboard_remote_attempts.php'=>'CreateDesktopDashboardRemoteAttempts'] as $file=>$class){require_once $application.'/database/migrations/'.$file;(new $class)->up();}
        $devices=app(DesktopDashboardDevices::class);$reconcile=app(DesktopDashboardReconciliation::class);$attempts=app(DesktopDashboardRemoteAttempts::class);
        $id=(string)Str::uuid();$link=$devices->enroll(['device_id'=>$id,'name'=>'Immutable enrollment fixture','nonce'=>bin2hex(random_bytes(32))],auth('admin')->user());
        $device=$devices->device($link['token']);$original=(array)$device;
        $race=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$database.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $replace=function(array $row)use($race,$id){$race->beginTransaction();try{
            $q=$race->prepare('DELETE FROM desktop_dashboard_devices WHERE id=?');$q->execute([$id]);
            $q=$race->prepare('INSERT INTO desktop_dashboard_devices (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));$race->commit();
        }catch(Throwable $error){$race->rollBack();throw $error;}};
        $envelope=function(){ $command=(string)Str::uuid();return ['command_id'=>$command,'actor_id'=>1,'route_name'=>'delivery-companies.save',
            'payload'=>['parameters'=>[],'values'=>['idempotency_key'=>$command,'branch'=>'f:100','name'=>'شركة ربط '.$command,'phone'=>'01010000001','active'=>true],'files'=>[]],
            'local_result'=>['success'=>true],'local_references'=>[],'dependencies'=>[],'occurred_at'=>now('UTC')->toIso8601String()];};
        $business=function(){return [DB::table('branch_delivery_companies')->count(),DB::table('branch_operation_commands')->count(),DB::table('desktop_dashboard_commands')->count(),DB::table('wallets')->count(),DB::table('users')->where('id',20)->value('balance')];};
        $first=$envelope();$receipt=$reconcile->ingest($device,$first);$before=$business();
        verify($receipt['committed']&&DB::table('branch_delivery_companies')->where('name',$first['payload']['values']['name'])->count()===1,'an unchanged authenticated enrollment commits the actual original delivery-company business service');
        verify($reconcile->ingest($device,$first)===$receipt&&$business()===$before,'the identical enrollment recovers its exact saved ordinary receipt without another business effect');
        $date=clone $device;$date->enrolled_at=\Carbon\Carbon::parse($device->enrolled_at,'UTC')->setTimezone('Africa/Cairo');
        verify($devices->enrollmentFingerprint($date)===$devices->enrollmentFingerprint($device),'equivalent DateTime and database UTC timestamp representations bind one enrollment');
        $mutable=$original;$mutable['name']='Renamed device';$mutable['last_seen_at']=now('UTC')->format('Y-m-d H:i:s');unset($mutable['last_seen_at']);$replace($mutable);
        verify($reconcile->ingest($device,$first)===$receipt,'changing a mutable device display name does not invalidate its immutable enrollment');$replace($original);
        foreach(['enrollment_hash','token_hash','enrolled_at','actor_id','delete-and-reenroll'] as $field){
            $changed=$original;
            if(in_array($field,['enrollment_hash','token_hash'],true))$changed[$field]=hash('sha256',$field.' replacement');
            elseif($field==='enrolled_at')$changed[$field]=now('UTC')->addSecond()->format('Y-m-d H:i:s');
            elseif($field==='actor_id')$changed[$field]=30;
            else{$nonce=bin2hex(random_bytes(32));$token=hash_hmac('sha256',$id.':1:'.$nonce,(string)config('app.key'));$changed['enrollment_hash']=hash('sha256',$nonce);$changed['token_hash']=hash('sha256',$token);$changed['enrolled_at']=now('UTC')->addSecond()->format('Y-m-d H:i:s');}
            DB::beginTransaction();try{
                $oldView=DB::table('desktop_dashboard_devices')->where('id',$id)->first();$replace($changed);
                verify($oldView->token_hash===$device->token_hash,'the independent '.$field.' replacement commits beneath the already authenticated repeatable-read view');
                rejectWallet(fn()=>$reconcile->ingest($device,$first),401,'changed '.$field.' rejects an already saved ordinary receipt before deduplication');
                rejectWallet(fn()=>$reconcile->ingest($device,$envelope()),401,'changed '.$field.' rejects new ordinary business work after old authentication');
            }finally{DB::rollBack();$replace($original);}
            verify($business()===$before,'the '.$field.' enrollment race changes neither original business rows nor receipts');
        }
        $logo=storage_path('app/public/enrollment-fixture.png');file_put_contents($logo,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jV1EAAAAASUVORK5CYII='));
        $oldLogo=DB::table('settings')->where('group','general')->where('name','logo')->value('payload');DB::table('settings')->where('group','general')->where('name','logo')->update(['payload'=>json_encode('enrollment-fixture.png')]);
        $snapshot=app(DesktopDashboardBootstrap::class)->export($device);$media=collect($snapshot['media'])->firstWhere('path','enrollment-fixture.png');
        verify($snapshot['actor_id']===1&&$media&&app(DesktopDashboardMedia::class)->download($device,$media['ticket'])['bytes']===file_get_contents($logo),'the unchanged real bootstrap and scoped encrypted media ticket retain their original behavior');
        $native=function(array $proof,string $operation)use($attempts){$route=clone app('router')->getRoutes()->getByName('wallets.transfer');
            $request=\Illuminate\Http\Request::create(url('admin/wallets/transfer'),'POST',['_desktop_command'=>$operation,'to_user'=>20,'amount'=>'1.00']);$route->bind($request);$request->setRouteResolver(fn()=>$route);
            $request->headers->set('X-Fasakhansta-Remote-Attempt',$proof['id']);$request->headers->set('X-Fasakhansta-Remote-Capability',$proof['capability']);
            $old=app('request');app()->instance('request',$request);\Illuminate\Support\Facades\Facade::clearResolvedInstance('request');app('url')->setRequest($request);
            try{return $attempts->handle($request,fn($r)=>app('router')->prepareResponse($r,$route->run()))->getStatusCode();}
            finally{app()->instance('request',$old);\Illuminate\Support\Facades\Facade::clearResolvedInstance('request');app('url')->setRequest($old);}
        };
        $ready=['id'=>(string)Str::uuid(),'method'=>'POST','path'=>'/admin/wallets/transfer'];$readyProof=$attempts->decide($device,$ready,false);
        $committed=$ready;$committed['id']=(string)Str::uuid();$committedProof=$attempts->decide($device,$committed,false);$operation=(string)Str::uuid();
        verify($native($committedProof,$operation)===302&&$native($committedProof,$operation)===302,'a new bound native reservation still returns the original cash controller response and its exact durable retry');$before=$business();
        $nonce=bin2hex(random_bytes(32));$newToken=hash_hmac('sha256',$id.':1:'.$nonce,(string)config('app.key'));$changed=$original;
        $changed['enrollment_hash']=hash('sha256',$nonce);$changed['token_hash']=hash('sha256',$newToken);$changed['enrolled_at']=now('UTC')->addSecond()->format('Y-m-d H:i:s');$replace($changed);$fresh=$devices->device($newToken);
        rejectWallet(fn()=>$reconcile->ingest($fresh,$first),401,'a newly authenticated replacement cannot inherit an old ordinary receipt under the same device UUID');
        rejectWallet(fn()=>app(DesktopDashboardBootstrap::class)->export($device),401,'a bootstrap authenticated before replacement cannot export the replacement enrollment dataset');
        rejectWallet(fn()=>app(DesktopDashboardMedia::class)->download($device,$media['ticket']),401,'an old authenticated object cannot download media after enrollment replacement');
        rejectWallet(fn()=>app(DesktopDashboardMedia::class)->download($fresh,$media['ticket']),403,'new enrollment credentials cannot reuse an old enrollment private media ticket');
        rejectWallet(fn()=>$attempts->decide($device,$ready,true),401,'old authenticated reserve or settle cannot cross a replaced enrollment');
        rejectWallet(fn()=>$attempts->decide($fresh,$ready,true),401,'new credentials cannot cancel an older enrollment ready reservation');
        rejectWallet(fn()=>$attempts->decide($fresh,$committed,true),401,'new credentials cannot disclose an older enrollment committed native result');
        rejectWallet(fn()=>$native($committedProof,$operation),401,'an old original native request cannot recover its saved result after enrollment replacement');
        $alias=$ready;$alias['id']=(string)Str::uuid();$aliasProof=$attempts->decide($fresh,$alias,false);rejectWallet(fn()=>$native($aliasProof,$operation),401,'a new enrollment reservation cannot alias an old enrollment business operation');
        verify($business()===$before&&DB::table('desktop_dashboard_remote_attempts')->where('id',$ready['id'])->value('status')==='ready'
            &&DB::table('desktop_dashboard_remote_attempts')->where('id',$committed['id'])->value('status')==='committed','rejected cross-enrollment recovery preserves committed money and every unresolved native outcome');
        $race->prepare('DELETE FROM desktop_dashboard_remote_attempts WHERE id=?')->execute([$ready['id']]);$newProof=$attempts->decide($fresh,$ready,false);
        verify($newProof['capability']!==$readyProof['capability'],'recreating the exact reservation UUID/path under a new enrollment produces a different capability');
        rejectWallet(fn()=>$native($readyProof,(string)Str::uuid()),403,'an old capability cannot execute a newly recreated same-UUID reservation');
        verify($native($newProof,(string)Str::uuid())===302,'the recreated reservation permits its own current enrollment capability and original business controller');
        $replace($original);
        $row=DB::table('desktop_dashboard_remote_attempts')->where('id',$committed['id'])->first();$unbound=json_decode(Crypt::decryptString($row->response_cipher),true);unset($unbound['enrollment_binding']);$legacy=Crypt::encryptString(json_encode($unbound));
        DB::table('desktop_dashboard_remote_attempts')->where('id',$committed['id'])->update(['response_cipher'=>$legacy]);$before=$business();
        rejectWallet(fn()=>$attempts->decide($device,$committed,true),503,'a preexisting unbound committed native result requires review instead of inferred cancellation');
        verify(DB::table('desktop_dashboard_remote_attempts')->where('id',$committed['id'])->value('response_cipher')===$legacy&&$business()===$before,'unknown native protocol leaves the encrypted committed result and original financial rows untouched');
        $oldReady=$ready;$oldReady['id']=(string)Str::uuid();$attempts->decide($device,$oldReady,false);
        DB::table('desktop_dashboard_remote_attempts')->where('id',$oldReady['id'])->update(['response_cipher'=>null]);
        rejectWallet(fn()=>$attempts->decide($device,$oldReady,true),503,'a preexisting unbound ready native reservation requires review without being cancelled');
        verify(DB::table('desktop_dashboard_remote_attempts')->where('id',$oldReady['id'])->value('status')==='ready'&&$business()===$before,'unknown ready reservation protocol preserves its unresolved state and all original business effects');
        $old=DB::table('desktop_dashboard_commands')->where('device_id',$id)->where('command_id',$first['command_id'])->first();$oldPayload=json_decode(Crypt::decryptString($old->command_cipher),true);unset($oldPayload['enrollment_binding']);
        DB::table('desktop_dashboard_commands')->where('sequence',$old->sequence)->update(['command_cipher'=>Crypt::encryptString(json_encode($oldPayload))]);
        rejectWallet(fn()=>$reconcile->ingest($device,$first),503,'a preexisting ordinary receipt without enrollment binding requires review and remains recorded');
        verify($business()===$before,'unknown ordinary receipt protocol repeats no original business or financial effect');
        DB::table('settings')->where('group','general')->where('name','logo')->update(['payload'=>$oldLogo]);unlink($logo);
    }finally{config($savedConfig);\Carbon\Carbon::setTestNow($savedClock);if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
    echo 'ENROLLMENT_BINDING_CHECKS='.($GLOBALS['count']-$start).PHP_EOL;
})();
