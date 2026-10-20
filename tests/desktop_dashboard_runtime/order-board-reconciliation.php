<?php
// Original board local middleware -> independent server journal -> native lost-reply recovery.
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardOrderBoard,DesktopDashboardJournal,DesktopDashboardDevices,DesktopDashboardReconciliation,DesktopDashboardRemoteAttempts,DesktopDashboardReferences};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
(function()use($application,$port,$pdo,$database){
    $start=$GLOBALS['count'];$clock=\Carbon\Carbon::getTestNow();$guard=auth('admin');$previous=$guard->getUser();
    $config=['database.connections.mysql.database'=>config('database.connections.mysql.database'),'desktop_dashboard.local'=>config('desktop_dashboard.local'),'desktop_dashboard.device_id'=>config('desktop_dashboard.device_id')];
    $server='desktop_board_'.bin2hex(random_bytes(8));$local='desktop_board_'.bin2hex(random_bytes(8));
    foreach([$server,$local] as $db){$pdo->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$db.'`'));}
    $copy=function($from,$to)use($pdo){foreach($pdo->query('SHOW TABLES FROM `'.$from.'`')->fetchAll(PDO::FETCH_COLUMN) as $table){
        $pdo->exec('CREATE TABLE `'.$to.'`.`'.$table.'` LIKE `'.$from.'`.`'.$table.'`');$pdo->exec('INSERT INTO `'.$to.'`.`'.$table.'` SELECT * FROM `'.$from.'`.`'.$table.'`');
    }};
    $switch=function($db,$isLocal,$id='')use($guard){config(['database.connections.mysql.database'=>$db,'desktop_dashboard.local'=>$isLocal,'desktop_dashboard.device_id'=>$id]);DB::purge();$guard->setUser(User::withoutGlobalScopes()->findOrFail(1));};
    $make=function(string $source,int $id,array $values){$route=clone app('router')->getRoutes()->getByName(DesktopDashboardOrderBoard::ROUTE);
        $request=Request::create(url('admin/order-board/'.$source.'/'.$id.'/action'),'POST',$values);$request->headers->set('Accept','application/json');$route->bind($request);$request->setRouteResolver(fn()=>$route);return $request;};
    $original=function(Request $request){$router=app('router');$route=$request->route();$middleware=array_map(fn($item)=>\Illuminate\Routing\MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
        $old=app('request');app()->instance('request',$request);\Illuminate\Support\Facades\Facade::clearResolvedInstance('request');app('url')->setRequest($request);
        try{return (new \Illuminate\Pipeline\Pipeline(app()))->send($request)->through($middleware)->then(fn($r)=>$router->prepareResponse($r,$route->run()));}
        finally{app()->instance('request',$old);\Illuminate\Support\Facades\Facade::clearResolvedInstance('request');app('url')->setRequest($old);}
    };
    try{
        $copy($database,$server);$switch($server,false);\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-20T03:00:00Z'));
        foreach(['2026_10_08_160000_create_desktop_dashboard_local_state.php'=>'CreateDesktopDashboardLocalState','2026_10_08_210000_create_desktop_dashboard_remote_attempts.php'=>'CreateDesktopDashboardRemoteAttempts'] as $file=>$class){require_once $application.'/database/migrations/'.$file;(new $class)->up();}
        DB::table('users')->insert(['id'=>21,'added_by'=>1,'name'=>'عميل استقبال جديد','email'=>'board21@test.invalid','mobile'=>'01010000021','account_type'=>'user','status'=>'accepted','app_scope'=>'fasakhansta','balance'=>'100.00','created_at'=>now(),'updated_at'=>now()]);
        foreach([1010,1011,1012] as $id){DB::table('orders')->insert(['id'=>$id,'user_id'=>21,'resturant_id'=>100,'type'=>'current','status'=>'pending','payment_type'=>'cash','accepted_notify'=>'no','delivery_price'=>'0.00','vendor_tax'=>'10.00','user_tax'=>'0.00','created_at'=>now(),'updated_at'=>now()]);
            DB::table('carts')->insert(['id'=>$id,'order_id'=>$id,'user_id'=>21,'resturant_id'=>100,'resturant_product_id'=>1,'is_order'=>1,'price'=>'100.00','qty'=>1,'created_at'=>now(),'updated_at'=>now()]);}
        $id=(string)Str::uuid();$link=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$id,'name'=>'Original board pair','nonce'=>bin2hex(random_bytes(32))],User::withoutGlobalScopes()->findOrFail(1));
        $device=app(DesktopDashboardDevices::class)->device($link['token']);$copy($server,$local);$switch($local,true,$id);
        DB::table('desktop_dashboard_local_state')->insert(['device_id'=>$id,'actor_id'=>1,'snapshot_id'=>(string)Str::uuid(),'schema_hash'=>str_repeat('a',64),'branches'=>$device->branches,'coverage'=>json_encode(['full_dashboard'=>false,'write_routes'=>[DesktopDashboardOrderBoard::ROUTE]]),'state'=>'ready','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        $localWrite=function(array $values,int $order=1010)use($make,$original){$response=app(\App\Http\Middleware\DesktopDashboardJournal::class)->handle($make('legacy',$order,$values),$original);verify($response->getStatusCode()===200,'shared board middleware executes the original '.$values['action'].' route');return $response->getContent();};
        $commands=[];$replies=[];
        foreach([['accept','pending','no'],['prepare','pending','yes'],['complete','accepted','yes']] as [$action,$status,$accepted]){
            \Carbon\Carbon::setTestNow(now('UTC')->addSeconds(3));$commands[]=$command=(string)Str::uuid();$values=['_desktop_command'=>$command,'action'=>$action,'expected_status'=>$status,'expected_accepted_notify'=>$accepted];
            $replies[]=$localWrite($values);if($action==='complete')verify($localWrite($values)===end($replies),'the real local board reply remains byte-identical after completion');
        }
        verify(count(app(DesktopDashboardJournal::class)->pending($id))===1,'dependent board preparation/completion wait behind their original accepted identity and CRM output');
        $switch($server,false,$id);DB::statement('ALTER TABLE wallets AUTO_INCREMENT=12000');DB::statement('ALTER TABLE crm_customers AUTO_INCREMENT=13000');DB::statement('ALTER TABLE coupon_subscripes AUTO_INCREMENT=14000');
        $receipts=[];
        foreach($commands as $offset=>$command){
            $switch($local,true,$id);$envelope=app(DesktopDashboardJournal::class)->pending($id)[0];verify($envelope['command_id']===$command,'the actual outbox preserves original board command order');
            $switch($server,false,$id);
            if($offset===2){
                $tampered=$envelope;$tampered['local_result']['after']['tables']['users'][0]['balance']='99999.00';
                rejectWallet(fn()=>app(DesktopDashboardReconciliation::class)->ingest($device,$tampered),409,'complete after-facts reject a forged local party balance after executing the real controller inside its transaction');
                verify(DB::table('orders')->where('id',1010)->value('status')==='accepted'&&!DB::table('desktop_dashboard_commands')->where('command_id',$command)->exists(),
                    'a mismatching financial after-state rolls back the original completion and produces no saved success');
                $draw=collect($envelope['local_result']['after']['tables']['coupon_subscripes'])->firstWhere('id',$envelope['local_references']['coupon_subscription.0']);
                $collision=DB::table('coupon_subscripes')->insertGetId(['user_id'=>20,'user_coupon_code'=>$draw['user_coupon_code'],'price'=>'100.00','amount'=>1,'coupon_wheel_id'=>1,'created_at'=>now(),'updated_at'=>now()]);
                $stock=DB::table('branch_inventory')->where('ingredient_id',1)->value('quantity_units');$count=DB::table('wallets')->count();
                rejectWallet(fn()=>app(DesktopDashboardReconciliation::class)->ingest($device,$envelope),409,'a real unrelated customer draw-code collision rejects replay instead of choosing a different local entry');
                verify(DB::table('orders')->where('id',1010)->value('status')==='accepted'&&DB::table('wallets')->count()===$count
                    &&DB::table('branch_inventory')->where('ingredient_id',1)->value('quantity_units')===$stock&&!DB::table('desktop_dashboard_commands')->where('command_id',$command)->exists(),
                    'draw-code conflict rolls back actual completion stock, commission and immutable success receipt');
                DB::table('coupon_subscripes')->where('id',$collision)->delete();
            }
            $receipt=app(DesktopDashboardReconciliation::class)->ingest($device,$envelope);$receipts[]=$receipt;
            verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($device,$envelope),'a lost central board reply returns its identical durable receipt');
            if($offset===0)verify($receipt['result']['references']['crm_customer.0']!==$envelope['local_result']['references']['crm_customer.0'],'original acceptance maps genuinely different new CRM identities');
            if($offset===2)verify($receipt['result']['references']['wallet_cash_movement.0']!==$envelope['local_result']['references']['wallet_cash_movement.0']
                &&$receipt['result']['references']['coupon_subscription.0']!==$envelope['local_result']['references']['coupon_subscription.0'],'original completion maps genuinely different financial ledger and competition entry IDs');
            $switch($local,true,$id);app(DesktopDashboardJournal::class)->acknowledge($id,$command,$receipt);
        }
        verify(app(DesktopDashboardJournal::class)->counts($id)['pending']===0&&DB::table('orders')->where('id',1010)->value('status')==='completed','every original board command acknowledges while preserving the local completed order');
        $switch($server,false,$id);DB::table('desktop_dashboard_devices')->where('id',$id)->update(['branches'=>'[]']);
        rejectWallet(fn()=>app(DesktopDashboardReconciliation::class)->ingest($device,$envelope),403,'an old acknowledged board receipt rechecks the current enrollment branches before disclosure');
        DB::table('desktop_dashboard_devices')->where('id',$id)->update(['branches'=>$device->branches]);$switch($local,true,$id);
        // A changed server menu rejects before stock, commission, draw or journal result can commit.
        $stale=(string)Str::uuid();$localWrite(['_desktop_command'=>$stale,'action'=>'accept','expected_status'=>'pending','expected_accepted_notify'=>'no'],1012);$envelope=app(DesktopDashboardJournal::class)->pending($id)[0];
        $switch($server,false,$id);DB::table('resturant_products')->where('id',1)->update(['product_price'=>'101.00']);$wallets=DB::table('wallets')->count();
        rejectWallet(fn()=>app(DesktopDashboardReconciliation::class)->ingest($device,$envelope),409,'current original menu/financial facts reject stale offline board acceptance before any original mutation');
        verify(DB::table('orders')->where('id',1012)->value('accepted_notify')==='no'&&DB::table('wallets')->count()===$wallets&&!DB::table('desktop_dashboard_commands')->where('command_id',$stale)->exists(),'conflicting board reconciliation preserves all financial rows and records no successful receipt');
        DB::table('resturant_products')->where('id',1)->update(['product_price'=>'100.00']);
        // Connected native requests also keep a source-bound immutable business operation.
        $attempts=app(DesktopDashboardRemoteAttempts::class);$operation=(string)Str::uuid();$values=['_desktop_command'=>$operation,'action'=>'accept','expected_status'=>'pending','expected_accepted_notify'=>'no'];$calls=0;
        $reservation=['id'=>(string)Str::uuid(),'method'=>'POST','path'=>'/admin/order-board/legacy/1011/action'];$proof=$attempts->decide($device,$reservation,false);
        $native=function(array $proof,array $values)use($attempts,$make,$original,&$calls){$request=$make('legacy',1011,$values);$request->headers->set('X-Fasakhansta-Remote-Attempt',$proof['id']);$request->headers->set('X-Fasakhansta-Remote-Capability',$proof['capability']);return $attempts->handle($request,function($request)use($original,&$calls){$calls++;return $original($request);})->getContent();};
        $first=$native($proof,$values);verify($first===$native($proof,$values)&&$calls===1,'native original board retry recovers its committed response without entering its controller again');
        $alias=$reservation;$alias['id']=(string)Str::uuid();$aliasProof=$attempts->decide($device,$alias,false);verify($native($aliasProof,$values)===$first&&$calls===1,'a new native transport aliases the same original board business UUID');
        $changed=$values;$changed['action']='prepare';rejectWallet(fn()=>$native($proof,$changed),409,'native board recovery refuses changed action bytes for an old immutable UUID');
        DB::table('users')->where('id',1)->update(['owner_resturant_id'=>100]);rejectWallet(fn()=>$native($proof,$values),403,'current owner financial authority is checked before a saved native board reply');
        rejectWallet(fn()=>$attempts->decide($device,$reservation,true),403,'terminal native board recovery also rechecks current owner authority');DB::table('users')->where('id',1)->update(['owner_resturant_id'=>null]);
        $cancel=$reservation;$cancel['id']=(string)Str::uuid();$cancelProof=$attempts->decide($device,$cancel,false);$attempts->decide($device,$cancel,true);
        $values['_desktop_command']=(string)Str::uuid();rejectWallet(fn()=>$native($cancelProof,$values),409,'cancelled native board reservation prevents a delayed original request from writing');
    }finally{config($config);DB::purge();\Carbon\Carbon::setTestNow($clock);if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
    echo 'ORDER_BOARD_RECONCILIATION_CHECKS='.($GLOBALS['count']-$start).PHP_EOL;
})();
