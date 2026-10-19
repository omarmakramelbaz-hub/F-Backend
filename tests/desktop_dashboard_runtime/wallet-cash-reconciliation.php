<?php
// Included by wallet-cash.php: real independent local/server databases and native reservations.
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
(function()use($application,$port,$pdo,$database,$profile){
    $oldConfig=['database.connections.mysql.database'=>config('database.connections.mysql.database'),
        'desktop_dashboard.local'=>config('desktop_dashboard.local'),'desktop_dashboard.device_id'=>config('desktop_dashboard.device_id')];
    $savedClock=\Carbon\Carbon::getTestNow();$guard=auth('admin');$previous=$guard->getUser();
    $server='desktop_wallet_cash_'.bin2hex(random_bytes(8));$local='desktop_wallet_cash_'.bin2hex(random_bytes(8));
    foreach([$server,$local] as $db){$pdo->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$db.'`'));}
    $copy=function(string $source,string $destination)use($pdo){
        foreach($pdo->query('SHOW TABLES FROM `'.$source.'`')->fetchAll(PDO::FETCH_COLUMN) as $table){
            $pdo->exec('CREATE TABLE `'.$destination.'`.`'.$table.'` LIKE `'.$source.'`.`'.$table.'`');
            $pdo->exec('INSERT INTO `'.$destination.'`.`'.$table.'` SELECT * FROM `'.$source.'`.`'.$table.'`');
        }
    };
    $switch=function(string $db,bool $isLocal,string $id='')use($guard){
        config(['database.connections.mysql.database'=>$db,'desktop_dashboard.local'=>$isLocal,'desktop_dashboard.device_id'=>$id]);DB::purge();
        $guard->setUser(User::withoutGlobalScopes()->findOrFail(1));
    };
    $journal=fn()=>app(\App\Services\Dashboard\DesktopDashboardJournal::class);
    $make=function(string $name,array $values): Request{
        $route=clone app('router')->getRoutes()->getByName($name);$request=Request::create(url($route->uri()),'POST',$values);
        $request->headers->set('Referer',url($name==='wallets.transfer'?'admin/wallets':'admin/wallet/withdraw'));
        $route->bind($request);$request->setRouteResolver(fn()=>$route);return $request;
    };
    $original=function(Request $request){
        $router=app('router');$route=$request->route();$middleware=array_map(fn($item)=>\Illuminate\Routing\MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),$route->controllerMiddleware());
        $previous=app('request');app()->instance('request',$request);\Illuminate\Support\Facades\Facade::clearResolvedInstance('request');app('url')->setRequest($request);
        try{return (new \Illuminate\Pipeline\Pipeline(app()))->send($request)->through($middleware)->then(fn($request)=>$router->prepareResponse($request,$route->run()));}
        finally{app()->instance('request',$previous);\Illuminate\Support\Facades\Facade::clearResolvedInstance('request');app('url')->setRequest($previous);}
    };
    try{
        $copy($database,$server);$switch($server,false);\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-20T01:00:00Z'));
        foreach(['2026_10_08_160000_create_desktop_dashboard_local_state.php'=>'CreateDesktopDashboardLocalState',
            '2026_10_08_210000_create_desktop_dashboard_remote_attempts.php'=>'CreateDesktopDashboardRemoteAttempts'] as $file=>$class){require_once $application.'/database/migrations/'.$file;(new $class)->up();}
        $id=(string)Str::uuid();$link=app(\App\Services\Dashboard\DesktopDashboardDevices::class)->enroll(['device_id'=>$id,'name'=>'Cash original pipeline pair','nonce'=>bin2hex(random_bytes(32))],User::withoutGlobalScopes()->findOrFail(1));
        $device=app(\App\Services\Dashboard\DesktopDashboardDevices::class)->device($link['token']);$copy($server,$local);$switch($local,true,$id);
        DB::table('desktop_dashboard_local_state')->insert(['device_id'=>$id,'actor_id'=>1,'snapshot_id'=>(string)Str::uuid(),'schema_hash'=>str_repeat('a',64),'branches'=>$device->branches,
            'coverage'=>json_encode(['full_dashboard'=>false,'write_routes'=>['wallets.transfer','wallets.withdraw.store']]),'state'=>'ready','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        $localWrite=function(string $name,array $values)use($make,$original){
            $response=app(\App\Http\Middleware\DesktopDashboardJournal::class)->handle($make($name,$values),$original);
            verify($response->getStatusCode()===302,'shared local middleware executes the original '.$name.' form successfully');return $response;
        };
        $credit=(string)Str::uuid();$creditForm=['_desktop_command'=>$credit,'from_user'=>'','to_user'=>20,'type'=>'transfer','amount'=>'2.01'];
        $localWrite('wallets.transfer',$creditForm);$first=$journal()->pending($id)[0];
        verify($first['command_id']===$credit&&$first['local_result']['wallet']['transfer_reference']==='dw:'.str_replace('-','',$credit)
            &&$first['local_references']['wallet_cash_movement']===$first['local_result']['wallet']['wallet_id'],
            'original form command metadata creates an exact immutable referenced local cash envelope');
        $withdraw=(string)Str::uuid();$withdrawForm=['_desktop_command'=>$withdraw,'from_user'=>20,'to_user'=>'','type'=>'withdraw','amount'=>'1.01'];
        \Carbon\Carbon::setTestNow(now('UTC')->addSeconds(2));$localWrite('wallets.withdraw.store',$withdrawForm);
        verify(count($journal()->pending($id))===1,'a dependent wallet withdrawal waits until its preceding local ledger identity is acknowledged');
        $retry=$localWrite('wallets.transfer',$creditForm);verify($retry->getStatusCode()===302&&DB::table('wallets')->where('transfer_reference','dw:'.str_replace('-','',$credit))->count()===1,
            'shared local middleware returns the saved original cash redirect after its balance moved again');
        $switch($server,false,$id);DB::statement('ALTER TABLE wallets AUTO_INCREMENT=10000');
        $receipt=app(\App\Services\Dashboard\DesktopDashboardReconciliation::class)->ingest($device,$first);
        verify($receipt['committed']&&$receipt['result']['wallet']['wallet_id']!==$first['local_result']['wallet']['wallet_id'],
            'central reconciliation executes original cash work with a genuinely different server ledger ID');
        verify($receipt===app(\App\Services\Dashboard\DesktopDashboardReconciliation::class)->ingest($device,$first),
            'a lost central cash reply returns the exact recorded receipt without crediting twice');
        $switch($local,true,$id);$journal()->acknowledge($id,$credit,$receipt);$second=$journal()->pending($id)[0];
        verify(($second['payload']['values']['from_user']['$desktop_ref']['entity']??null)==='wallet_account'
            &&in_array($credit,$second['dependencies'],true),'dependent withdrawal carries the typed acknowledged account and ledger dependency');
        $switch($server,false,$id);$resolved=app(\App\Services\Dashboard\DesktopDashboardReferences::class)->resolve($id,$second['payload']);
        verify(collect($resolved['facts']['wallet_cash_before']['ledger'])->contains(fn($r)=>(int)$r['id']===(int)$receipt['result']['wallet']['wallet_id']),
            'the full dependent before-ledger maps the actual local/server ledger identity drift');
        $secondReceipt=app(\App\Services\Dashboard\DesktopDashboardReconciliation::class)->ingest($device,$second);
        verify($secondReceipt['result']['wallet']['balance_after']===$second['local_result']['wallet']['balance_after'],
            'dependent original withdrawal reconciles the same exact balance after typed ledger resolution');
        $switch($local,true,$id);$journal()->acknowledge($id,$withdraw,$secondReceipt);
        verify($journal()->counts($id)===['pending'=>0,'conflicts'=>0,'acknowledged'=>2],'both original cash operations become acknowledged without discarding a local command');
        // Current server state wins before work and produces a retained conflict.
        $conflict=(string)Str::uuid();$localWrite('wallets.transfer',['_desktop_command'=>$conflict,'to_user'=>20,'amount'=>'1.00']);$conflictEnvelope=$journal()->pending($id)[0];
        $switch($server,false,$id);$beforeBalance=DB::table('users')->where('id',20)->value('balance');DB::table('users')->where('id',20)->increment('balance','1.00');$rows=DB::table('wallets')->count();
        rejectWallet(fn()=>app(\App\Services\Dashboard\DesktopDashboardReconciliation::class)->ingest($device,$conflictEnvelope),409,
            'an independent server cash movement conflicts before replaying a stale offline credit');
        verify(DB::table('wallets')->count()===$rows&&!DB::table('desktop_dashboard_commands')->where('device_id',$id)->where('command_id',$conflict)->exists(),
            'conflicting reconciliation commits neither a financial row nor a success receipt');DB::table('users')->where('id',20)->update(['balance'=>$beforeBalance]);
        // Native connected original forms use a durable server reservation and saved identity binding.
        $attempts=app(\App\Services\Dashboard\DesktopDashboardRemoteAttempts::class);$operation=(string)Str::uuid();
        $reservation=['id'=>(string)Str::uuid(),'method'=>'POST','path'=>'/admin/wallets/transfer'];$proof=$attempts->decide($device,$reservation,false);
        $nativeForm=['_desktop_command'=>$operation,'to_user'=>20,'amount'=>'1.00'];$calls=0;
        $native=function(array $proof,array $form)use($make,$attempts,$original,&$calls){
            $request=$make('wallets.transfer',$form);$request->headers->set('X-Fasakhansta-Remote-Attempt',$proof['id']);$request->headers->set('X-Fasakhansta-Remote-Capability',$proof['capability']);
            $response=$attempts->handle($request,function($request)use($original,&$calls){$calls++;return $original($request);});
            return [$response->getStatusCode(),$response->getContent(),$response->headers->get('Location')];
        };
        $nativeResult=$native($proof,$nativeForm);$nativeCount=DB::table('wallets')->count();
        verify($nativeResult===$native($proof,$nativeForm)&&$calls===1&&DB::table('wallets')->count()===$nativeCount,
            'native original cash request recovers a lost committed response without entering its controller twice');
        $alias=['id'=>(string)Str::uuid(),'method'=>'POST','path'=>'/admin/wallets/transfer'];$aliasProof=$attempts->decide($device,$alias,false);
        verify($native($aliasProof,$nativeForm)===$nativeResult&&$calls===1,'a new native transport reservation aliases the same immutable wallet operation');
        $bad=$nativeForm;$bad['amount']='2.00';rejectWallet(fn()=>$native($proof,$bad),409,'native saved cash result rejects changed original form bytes');
        DB::table('users')->where('id',1)->update(['owner_resturant_id'=>100]);
        rejectWallet(fn()=>$native($proof,$nativeForm),403,'native cash retry checks current central authority before disclosing a saved result');
        rejectWallet(fn()=>$attempts->decide($device,$reservation,true),403,'native terminal recovery also checks current central authority for a committed cash request');
        DB::table('users')->where('id',1)->update(['owner_resturant_id'=>null]);
        $cancel=['id'=>(string)Str::uuid(),'method'=>'POST','path'=>'/admin/wallets/transfer'];$cancelProof=$attempts->decide($device,$cancel,false);$attempts->decide($device,$cancel,true);
        rejectWallet(fn()=>$native($cancelProof,['_desktop_command'=>(string)Str::uuid(),'to_user'=>20,'amount'=>'1.00']),409,
            'cancelled native reservations reject a delayed original cash request');
    }finally{
        config($oldConfig);DB::purge();\Carbon\Carbon::setTestNow($savedClock);if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);
    }
})();
