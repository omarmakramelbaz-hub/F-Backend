<?php
// Included by wallet-cash.php: original board controllers and retained stock/wallet services.
use App\Services\Dashboard\DesktopDashboardOrderBoard;
use Illuminate\Support\Facades\DB;

(function()use($application,$port,$pdo,$database){
    $beforeCount=$GLOBALS['count'];$savedClock=\Carbon\Carbon::getTestNow();$savedDevice=config('desktop_dashboard.device_id');$guard=auth('admin');$previous=$guard->getUser();
    try{
        config(['desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>'']);\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-20T02:00:00Z'));
        if(!\Illuminate\Support\Facades\Schema::hasTable('crm_customers')){require_once $application.'/database/migrations/2026_10_17_000001_create_crm_customer_directory.php';(new CreateCrmCustomerDirectory)->up();}
        $actor=\App\Models\User::withoutGlobalScopes()->findOrFail(1);$guard->setUser($actor);$adapter=app(DesktopDashboardOrderBoard::class);$journal=app(\App\Services\Dashboard\DesktopDashboardJournal::class);$device=(string)\Illuminate\Support\Str::uuid();
        $scope=(object)['actor_id'=>1,'branches'=>'["f:100","gs:60"]','enabled'=>true];
        DB::table('users')->insert(['id'=>60,'added_by'=>1,'name'=>'المتجر الأصلي','email'=>'store@test.invalid','mobile'=>'01060000000','password'=>password_hash('Fixture123',PASSWORD_BCRYPT),
            'account_type'=>'vendor','status'=>'accepted','app_scope'=>'go_partner','balance'=>'100.00','min_wallet'=>50,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('go_stores')->insert(['user_id'=>60,'name'=>'متجر الاختبار','kind'=>'grocery','address'=>'المنصورة','revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('categories')->insert(['id'=>1,'added_by'=>1,'name_ar'=>'السمك','name_en'=>'Fish','status'=>'show','order'=>1]);
        DB::table('products')->insert(['id'=>1,'added_by'=>1,'category_id'=>1,'name_ar'=>'رنجة','name_en'=>'Herring','status'=>'show']);
        DB::table('resturant_products')->insert(['id'=>1,'added_by'=>1,'resturant_id'=>100,'product_id'=>1,'category_id'=>1,'product_name'=>'رنجة','product_price'=>'100.00','status'=>'show','price'=>'{}']);
        DB::table('stock_ingredients')->insert(['id'=>1,'name'=>'رنجة','unit'=>'kg','position'=>1]);
        DB::table('branch_stock_recipes')->insert(['branch'=>'f:100','product_id'=>1,'unit'=>'kg','revision'=>1,'updated_by'=>1,
            'variants'=>json_encode(['0'=>[['ingredient_id'=>1,'name'=>'رنجة','unit'=>'kg','quantity_units'=>1000000]]]),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('branch_inventory')->insert(['branch'=>'f:100','ingredient_id'=>1,'quantity_units'=>10000000,'revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('coupon_wheels')->insert(['id'=>1,'added_by'=>1,'name'=>'مسابقة فعلية','price'=>'50.00','start_date'=>'2026-10-01','end_date'=>'2026-10-31','status'=>'show','created_at'=>now(),'updated_at'=>now()]);
        DB::table('coupon_wheel_resturants')->insert(['coupon_wheel_id'=>1,'resturant_id'=>100,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('coupon_subscripes')->insert(['id'=>1,'user_id'=>20,'user_coupon_code'=>'board-fixture','price'=>'0.00','amount'=>'0.00','coupon_wheel_id'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('orders')->insert(['id'=>1001,'user_id'=>20,'resturant_id'=>100,'type'=>'current','status'=>'pending','payment_type'=>'cash','accepted_notify'=>'no',
            'delivery_price'=>'0.00','vendor_tax'=>'10.00','user_tax'=>'0.00','created_at'=>now(),'updated_at'=>now()]);
        DB::table('carts')->insert(['id'=>1001,'order_id'=>1001,'user_id'=>20,'resturant_id'=>100,'resturant_product_id'=>1,'is_order'=>1,'price'=>'100.00','qty'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $payload=function(string $source,int $id,array $values,?array $saved=null)use($adapter,$actor,$scope){
            $original=clone app('router')->getRoutes()->getByName(DesktopDashboardOrderBoard::ROUTE);
            $req=\Illuminate\Http\Request::create(url('admin/order-board/'.$source.'/'.$id.'/action'),'POST',$values);$original->bind($req);$req->setRouteResolver(fn()=>$original);
            return DB::transaction(fn()=>$adapter->payload($req,$values['idempotency_key'],$actor,$scope,$saved));
        };
        $commit=function(array $payload)use($adapter,$actor,$scope,$journal,$device){
            return DB::transaction(function()use($adapter,$actor,$scope,$journal,$device,$payload){$fresh=$adapter->authorize($payload,$actor,$scope);
                return $journal->execute($device,$payload['values']['idempotency_key'],1,DesktopDashboardOrderBoard::ROUTE,$payload,[],fn()=>$adapter->execute($payload,$fresh));});
        };
        $legacy=[];
        foreach([['accept','pending','no'],['prepare','pending','yes'],['complete','accepted','yes']] as [$action,$status,$accepted]){
            \Carbon\Carbon::setTestNow(now('UTC')->addSeconds(3));$v=['action'=>$action,'expected_status'=>$status,'expected_accepted_notify'=>$accepted,'idempotency_key'=>(string)\Illuminate\Support\Str::uuid()];
            $p=$payload('legacy',1001,$v);$result=$commit($p);$legacy[$action]=[$p,$result];
            verify($result['http']['status']===200&&json_decode($result['http']['content'],true)['success']===true,'original legacy board '.$action.' executes through its unchanged route/controller pipeline');
            verify($result===$commit($p),'lost original legacy '.$action.' response returns the identical durable result without entering business work');
        }
        verify(DB::table('orders')->where('id',1001)->value('status')==='completed'&&DB::table('orders')->where('id',1001)->value('transfer_price_by')==='vendor',
            'original own-courier completion persists its retained cash settlement marker');
        verify((int)DB::table('branch_inventory')->where('branch','f:100')->where('ingredient_id',1)->value('quantity_units')===9000000
            &&DB::table('branch_recipe_sales')->where('source_type','app')->where('source_id','1001')->count()===1,
            'original app-order completion consumes its real recipe stock once');
        verify(\App\Services\GoServices\Money::minor(DB::table('users')->where('id',10)->value('balance'))===9000
            &&DB::table('wallets')->where('transfer_reference','ob:l:1001:commission')->count()===1,
            'original completion debits the real vendor commission and creates one retained financial ledger');
        verify(DB::table('crm_purchases')->where('source_type','app_order')->where('source_id','1001')->count()===1,
            'the actual completed-order observer captures one CRM purchase inside the board journal');
        verify(DB::table('orders')->where('id',1001)->value('coupon_wheel_id')===1
            &&\App\Services\GoServices\Money::minor(DB::table('coupon_subscripes')->where('id',1)->value('price'))===10000,
            'the original completion retains its active competition observer and customer purchase total');
        verify(DB::table('coupon_subscripes')->where('user_id',20)->where('amount',1)->count()===1
            &&isset($legacy['complete'][1]['references']['coupon_subscription.0']),
            'the real eligible competition observer creates exactly one typed draw entry');
        verify(DB::table('order_board_clocks')->where('source','legacy')->where('order_id',1001)->value('accepted_at')!==null
            &&DB::table('order_board_clocks')->where('source','legacy')->where('order_id',1001)->value('courier_at')!==null,
            'original observer acceptance and own-courier clocks share the frozen operation times');
        // A changed price/ledger/stock must conflict before completion work.
        DB::table('orders')->insert(['id'=>1002,'user_id'=>20,'resturant_id'=>100,'type'=>'current','status'=>'accepted','payment_type'=>'cash','accepted_notify'=>'yes',
            'delegate_from_out'=>'in_resturant','delivery_price'=>'0.00','vendor_tax'=>'10.00','user_tax'=>'0.00','created_at'=>now(),'updated_at'=>now()]);
        DB::table('carts')->insert(['id'=>1002,'order_id'=>1002,'user_id'=>20,'resturant_id'=>100,'resturant_product_id'=>1,'is_order'=>1,'price'=>'100.00','qty'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $complete=['action'=>'complete','expected_status'=>'accepted','expected_accepted_notify'=>'yes','idempotency_key'=>(string)\Illuminate\Support\Str::uuid()];$stale=$payload('legacy',1002,$complete);
        $race=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$database.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        DB::beginTransaction();try{
            DB::table('branch_inventory')->count();$race->exec("UPDATE branch_inventory SET quantity_units=8000000 WHERE branch='f:100' AND ingredient_id=1");
            rejectWallet(fn()=>$commit($stale),409,'a real stock change beneath older repeatable-read rejects stale board financial completion before money moves');
        }finally{DB::rollBack();$race->exec("UPDATE branch_inventory SET quantity_units=9000000 WHERE branch='f:100' AND ingredient_id=1");}
        $stockBefore=DB::table('branch_inventory')->where('ingredient_id',1)->value('quantity_units');$walletsBefore=DB::table('wallets')->count();$commandsBefore=DB::table('desktop_dashboard_commands')->count();
        try{DB::transaction(function()use($commit,$stale){$commit($stale);throw new RuntimeException('forced board rollback');});throw new RuntimeException('Rollback probe succeeded');}
        catch(RuntimeException $e){verify($e->getMessage()==='forced board rollback','the board rollback probe reaches the actual completed controller');}
        verify(DB::table('orders')->where('id',1002)->value('status')==='accepted'&&DB::table('wallets')->count()===$walletsBefore
            &&DB::table('branch_inventory')->where('ingredient_id',1)->value('quantity_units')===$stockBefore&&DB::table('desktop_dashboard_commands')->count()===$commandsBefore,
            'failure after original board completion rolls back order, real stock, wallet commission and durable result');
        DB::beginTransaction();try{$localCompletion=$commit($stale);}finally{DB::rollBack();}
        $replayed=$adapter->replayContext($localCompletion,$stale,fn()=>$commit($stale));$adapter->verifyAfter($replayed,$localCompletion,$stale,$stale);
        $localDraw=collect($localCompletion['after']['tables']['coupon_subscripes'])->firstWhere('id',$localCompletion['references']['coupon_subscription.0']);
        $serverDraw=DB::table('coupon_subscripes')->find($replayed['references']['coupon_subscription.0']);
        verify($serverDraw->user_coupon_code===$localDraw['user_coupon_code']&&$serverDraw->id!==$localDraw['id'],
            'competition replay preserves its actual local random draw code and verifies genuinely different generated subscription IDs');
        foreach([2001,2002] as $id)DB::table('go_store_orders')->insert(['id'=>$id,'customer_id'=>20,'store_id'=>60,'request_key'=>(string)\Illuminate\Support\Str::uuid(),
            'request_hash'=>str_repeat('a',64),'snapshot'=>'{}','fulfillment'=>'pickup','status'=>'pending','revision'=>1,'subtotal_cents'=>10000,'delivery_cents'=>0,'total_cents'=>10000,
            'commission_bps'=>1000,'commission_cents'=>1000,'payment_method'=>'cash','payment_status'=>'cash_due','payment_reference'=>(string)\Illuminate\Support\Str::uuid(),'created_at'=>now(),'updated_at'=>now()]);
        foreach([['accept','pending',1],['ready','preparing',2],['complete','ready',3]] as [$action,$status,$revision]){
            \Carbon\Carbon::setTestNow(now('UTC')->addSeconds(3));$p=$payload('store',2001,['action'=>$action,'expected_status'=>$status,'expected_revision'=>$revision,'idempotency_key'=>(string)\Illuminate\Support\Str::uuid()]);
            $result=$commit($p);verify($result['after']['row']['status']===['accept'=>'preparing','ready'=>'ready','complete'=>'completed'][$action],
                'original GO store '.$action.' retains its distinct state/revision pipeline');verify($commit($p)===$result,'lost GO store '.$action.' reply has one exact saved result');
        }
        verify(\App\Services\GoServices\Money::minor(DB::table('users')->where('id',60)->value('balance'))===9000
            &&DB::table('wallets')->where('transfer_reference','gs:2001:fee')->count()===1
            &&DB::table('go_store_orders')->where('id',2001)->value('payment_status')==='cash_collected','GO cash completion retains one store commission and authoritative cash-collected state');
        $accept=$payload('store',2002,['action'=>'accept','expected_status'=>'pending','expected_revision'=>1,'idempotency_key'=>(string)\Illuminate\Support\Str::uuid()]);$commit($accept);
        $cancel=$payload('store',2002,['action'=>'cancel','expected_status'=>'preparing','expected_revision'=>2,'reason'=>'طلب العميل','idempotency_key'=>(string)\Illuminate\Support\Str::uuid()]);$cancelled=$commit($cancel);
        verify($cancelled['after']['row']['status']==='cancelled'&&DB::table('wallets')->where('transfer_reference','gs:2002:fee-refund')->count()===1
            &&\App\Services\GoServices\Money::minor(DB::table('users')->where('id',60)->value('balance'))===9000,'original GO cancellation refunds exactly its recorded commission under the same journal');
        foreach([['wallet','held',2003],['card','paid',2004]] as [$method,$payment,$id]){
            DB::table('go_store_orders')->insert(['id'=>$id,'customer_id'=>20,'store_id'=>60,'request_key'=>(string)\Illuminate\Support\Str::uuid(),
                'request_hash'=>str_repeat('a',64),'snapshot'=>'{}','fulfillment'=>'pickup','status'=>'pending','revision'=>1,'subtotal_cents'=>10000,'delivery_cents'=>0,'total_cents'=>10000,
                'commission_bps'=>1000,'commission_cents'=>1000,'payment_method'=>$method,'payment_status'=>$payment,'payment_reference'=>(string)\Illuminate\Support\Str::uuid(),'created_at'=>now(),'updated_at'=>now()]);
            if($method==='wallet'){
                rejectWallet(fn()=>$payload('store',$id,['action'=>'reject','expected_status'=>'pending','expected_revision'=>1,'idempotency_key'=>(string)\Illuminate\Support\Str::uuid()]),501,
                    'a held flag alone cannot mint a wallet refund without the unavailable original checkout hold contract');continue;
            }
            rejectWallet(fn()=>$payload('store',$id,['action'=>'reject','expected_status'=>'pending','expected_revision'=>1,'idempotency_key'=>(string)\Illuminate\Support\Str::uuid()]),409,
                'gateway-paid store transitions require their real exact original gross credit before offline admission');
            rejectWallet(fn()=>app(\App\Services\Dashboard\GoStoreBoardActions::class)->transition($id,60,'reject',1),409,
                'the original connected paid rejection also refuses to debit an invented gateway gross');
            DB::table('users')->where('id',60)->increment('balance','100.00');DB::table('wallets')->insert(['transfer_reference'=>'gs:'.$id.':gross','from_user'=>null,'to_user'=>60,'amount'=>'100.00','type'=>'transfer','payment'=>'wallet','status'=>'completed','created_at'=>now(),'updated_at'=>now()]);
            $p=$payload('store',$id,['action'=>'accept','expected_status'=>'pending','expected_revision'=>1,'idempotency_key'=>(string)\Illuminate\Support\Str::uuid()]);$commit($p);
            $p=$payload('store',$id,['action'=>'cancel','expected_status'=>'preparing','expected_revision'=>2,'idempotency_key'=>(string)\Illuminate\Support\Str::uuid()]);$r=$commit($p);
            verify($r['after']['row']['payment_status']===($method==='wallet'?'refunded':'refund_pending')&&$commit($p)===$r,
                'original '.$method.' GO cancellation preserves its distinct refund boundary and an immutable retry');
            verify(DB::table('wallets')->where('transfer_reference','gs:'.$id.':'.($method==='wallet'?'refund':'reversal'))->count()===1,
                'original '.$method.' GO cancellation records exactly one real wallet refund/reversal');
        }
        DB::table('orders')->insert(['id'=>1003,'user_id'=>20,'resturant_id'=>100,'type'=>'current','status'=>'pending','payment_type'=>'wallet','accepted_notify'=>'yes',
            'delivery_price'=>'0.00','vendor_tax'=>'10.00','user_tax'=>'0.00','created_at'=>now(),'updated_at'=>now()]);
        DB::table('carts')->insert(['id'=>1003,'order_id'=>1003,'user_id'=>20,'resturant_id'=>100,'resturant_product_id'=>1,'is_order'=>1,'price'=>'100.00','qty'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('wallets')->insert(['transfer_reference'=>'held:1003','order_id'=>1003,'from_user'=>20,'to_user'=>null,'amount'=>'100.00','type'=>'transfer','payment'=>'wallet','status'=>'completed','created_at'=>now(),'updated_at'=>now()]);
        foreach(['reject','complete','dispatch'] as $unsupported)rejectWallet(fn()=>$payload('legacy',1003,['action'=>$unsupported,'expected_status'=>'pending','expected_accepted_notify'=>'yes','idempotency_key'=>(string)\Illuminate\Support\Str::uuid()]),501,
            'unproved legacy '.$unsupported.' payment/provider work cannot enter a local financial journal');
        $customerBefore=\App\Services\GoServices\Money::minor(DB::table('users')->where('id',20)->value('balance'));
        $p=$payload('legacy',1003,['action'=>'cancel','expected_status'=>'pending','expected_accepted_notify'=>'yes','idempotency_key'=>(string)\Illuminate\Support\Str::uuid()]);$r=$commit($p);
        verify($r['after']['row']['status']==='cancelled'&&$r['after']['row']['transfer_price_by']==='admin'
            &&\App\Services\GoServices\Money::minor(DB::table('users')->where('id',20)->value('balance'))===$customerBefore+10000,
            'original legacy wallet cancellation refunds only the verified order debit and marks its completed settlement');
        verify($commit($p)===$r&&DB::table('wallets')->where('transfer_reference','ob:l:1003:cancel')->count()===1,
            'a lost legacy wallet-cancellation reply never creates a second customer refund');
        DB::table('partner_service_requests')->insert(['id'=>3001,'user_id'=>20,'partner_id'=>60,'profession_key'=>'plumber','description'=>'طلب اختبار','customer_lat'=>31,'customer_lng'=>31,'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
        foreach([['accept','pending'],['complete','accepted']] as [$action,$status]){
            $p=$payload('partner_service',3001,['action'=>$action,'expected_status'=>$status,'idempotency_key'=>(string)\Illuminate\Support\Str::uuid()]);$r=$commit($p);
            verify($r['after']['row']['status']===($action==='accept'?'accepted':'completed'),'original partner-service '.$action.' remains separate from restaurant/store settlement');
        }
        DB::table('users')->where('id',20)->update(['app_scope'=>'go','status'=>'accepted']);
        DB::table('go_service_jobs')->insert(['id'=>4001,'customer_id'=>20,'request_key'=>(string)\Illuminate\Support\Str::uuid(),'payload_hash'=>str_repeat('b',64),'profession_key'=>'plumber','description'=>'طلب بحث','area'=>'','address'=>'المنصورة','phone'=>'',
            'lat'=>31,'lng'=>31,'status'=>'searching','price_cents'=>0,'commission_bps'=>0,'commission_cents'=>0,'payment_status'=>'unpaid','held_cents'=>0,'search_until'=>now()->addHour(),'next_dispatch_at'=>now(),'dispatch_round'=>0,'created_at'=>now(),'updated_at'=>now()]);
        $p=$payload('service',4001,['action'=>'reject','expected_status'=>'searching','idempotency_key'=>(string)\Illuminate\Support\Str::uuid()]);$r=$commit($p);
        verify($r['after']['row']['status']==='cancelled'&&DB::table('go_service_outbox')->where('job_id',4001)->where('event_key','like','%:cancelled:%')->count()===1,
            'original searching-service rejection retains its customer transition and central message intent without inventing a fee');
        $adapter->verifyAfter($r,$r);$tamper=$r;$tamper['after']['row']['status']='completed';rejectWallet(fn()=>$adapter->verifyAfter($tamper,$r),409,'a different financial/lifecycle after-state cannot acknowledge a local board command');
        $other=(object)['actor_id'=>10,'branches'=>'["f:100"]','enabled'=>true];rejectWallet(fn()=>$adapter->authorize($p,\App\Models\User::withoutGlobalScopes()->findOrFail(10),$other),403,
            'a branch snapshot cannot impersonate the complete owner financial graph');
        $disabled=clone $scope;$disabled->enabled=false;rejectWallet(fn()=>$adapter->authorize($p,$actor,$disabled),401,'disabled enrollment cannot disclose an old board result');
    }finally{config(['desktop_dashboard.device_id'=>$savedDevice]);\Carbon\Carbon::setTestNow($savedClock);if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
    echo 'ORDER_BOARD_COMPONENT_CHECKS='.($GLOBALS['count']-$beforeCount).PHP_EOL;
})();
