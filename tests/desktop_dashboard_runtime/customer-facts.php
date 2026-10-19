<?php
// Existing operational writes must not revive consent or merge an unseen CRM identity on reconnect.
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Models\User;
use App\Services\Dashboard\{BranchCustomers,CustomerDirectory,DesktopDashboardBootstrap,DesktopDashboardDevices,
    DesktopDashboardCustomerFacts,DesktopDashboardImport,DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardRoutes,TakeawayService};

(function()use($pdo,$remoteDatabase){
    $savedConfig=[
        'database.connections.mysql.database'=>config('database.connections.mysql.database'),
        'desktop_dashboard.local'=>config('desktop_dashboard.local'),
        'desktop_dashboard.device_id'=>config('desktop_dashboard.device_id'),
        'desktop_dashboard.enabled'=>config('desktop_dashboard.enabled'),
    ];
    $savedClock=\Carbon\Carbon::getTestNow();
    $switch=function(string $database,bool $local,string $device,bool $loadActor=true): ?User{
        config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>$local,
            'desktop_dashboard.device_id'=>$device,'desktop_dashboard.enabled'=>true]);DB::purge();
        return $loadActor?User::withoutGlobalScopes()->findOrFail(1):null;
    };
    $pair=function(?string $phone=null,bool $realClock=false,?callable $beforeExport=null)use($pdo,$remoteDatabase,$switch): array{
        $server='desktop_customer_facts_'.bin2hex(random_bytes(8));
        $local='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));
        foreach([$server,$local] as $database){
            $pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
        }
        foreach($pdo->query('SHOW TABLES FROM `'.$remoteDatabase.'`')->fetchAll(PDO::FETCH_COLUMN) as $table){
            $pdo->exec('CREATE TABLE `'.$server.'`.`'.$table.'` LIKE `'.$remoteDatabase.'`.`'.$table.'`');
            $pdo->exec('INSERT INTO `'.$server.'`.`'.$table.'` SELECT * FROM `'.$remoteDatabase.'`.`'.$table.'`');
        }
        \Carbon\Carbon::setTestNow($realClock?null:\Carbon\Carbon::parse('2026-10-19T12:00:00Z'));
        $id=(string)Str::uuid();$actor=$switch($server,false,$id);
        DB::table('branch_inventory')->where('branch','f:100')->where('ingredient_id',6)->update(['quantity_units'=>10000000]);
        if($phone!==null){
            $customer=app(CustomerDirectory::class)->capture(['phone'=>$phone,'name'=>'الاسم الأصلي'],'takeaway','f:100');
            app(CustomerDirectory::class)->consent((int)$customer->id,true,false,'fixture:customer-approved-before-export',1);
            if($beforeExport)$beforeExport($customer,$actor);
        }
        $link=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$id,'name'=>'isolated customer facts fixture',
            'nonce'=>bin2hex(random_bytes(32))],$actor);
        $device=app(DesktopDashboardDevices::class)->device($link['token']);
        $snapshot=app(DesktopDashboardBootstrap::class)->export($device);
        $switch($local,true,$id,false);$imported=app(DesktopDashboardImport::class)->import($snapshot);
        check($imported['device_id']===$id&&DB::table('desktop_dashboard_commands')->count()===0,
            'customer facts fixture imports a fresh real account dataset with its own empty journal');
        return ['server'=>$server,'local'=>$local,'id'=>$id,'device'=>$device];
    };
    $sale=function(string $phone,string $name='اسم محلي'): array{
        $values=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'customer_phone'=>$phone,'customer_name'=>$name,
            'service_consent'=>true,'items'=>[['product_id'=>1,'option_id'=>'','quantity_mode'=>'weight','quantity'=>'0.250']],
            'discount'=>'0.00','payment_method'=>'cash','cash_received'=>'100.00'];
        $values['quote_hash']=app(TakeawayService::class)->quote($values,User::withoutGlobalScopes()->findOrFail(1))['quote_hash'];
        return $values;
    };
    $write=function(array $pair,string $route,array $values,int $delayAfterWrite=0): array{
        $actor=User::withoutGlobalScopes()->findOrFail(1);
        return app(DesktopDashboardJournal::class)->execute($pair['id'],$values['idempotency_key'],1,$route,['values'=>$values],[],
            function()use($route,$values,$actor,$delayAfterWrite){
                $result=$route==='customers.save'?app(BranchCustomers::class)->save($values,$actor):app(TakeawayService::class)->checkout($values,$actor,[],true);
                if($delayAfterWrite)usleep($delayAfterWrite);
                return $result;
            });
    };
    $fingerprint=function(?array $tables=null): string{
        $tables=$tables??['crm_customers','crm_customer_sources','crm_purchases','branch_customers','branch_operation_commands',
            'takeaway_orders','takeaway_order_items','takeaway_tills','takeaway_till_entries','branch_order_sequences',
            'branch_stock','branch_stock_movements','branch_inventory','branch_inventory_movements','branch_recipe_sales',
            'desktop_dashboard_commands','desktop_dashboard_entities'];
        $state=[];
        foreach($tables as $table){
            $rows=DB::table($table)->get()->map(fn($row)=>DesktopDashboardBootstrap::json((array)$row))->all();
            sort($rows,SORT_STRING);$state[$table]=$rows;
        }
        return app(DesktopDashboardJournal::class)->fingerprint($state);
    };
    try{
        check(DesktopDashboardRoutes::journaled('customers.directory-save')&&DesktopDashboardRoutes::journaled('customers.consent'),
            'the customer UI increment journals its exact profile and consent routes');
        $consentFields=['service_consent','marketing_consent','service_consented_at','marketing_consented_at',
            'service_consent_evidence','marketing_consent_evidence','service_consented_by','marketing_consented_by','opted_out_at'];
        foreach(['scoped withdrawal'=>'01092000001','global STOP'=>'01092000002'] as $history=>$phone){
            $withdrawal=null;
            $p=$pair($phone,false,function($customer,$actor)use($history,&$withdrawal){
                if($history==='global STOP'){
                    app(CustomerDirectory::class)->consent((int)$customer->id,false,false,'whatsapp-inbound:opt-out');
                    return;
                }
                app(CustomerDirectory::class)->consent((int)$customer->id,true,true,'fixture:previous-approval',1);
                $withdrawal=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'directory_id'=>(int)$customer->id,
                    'service_consent'=>false,'marketing_consent'=>true,'consent_scope'=>'service','consent_method'=>'withdrawal'];
                app(BranchCustomers::class)->consent($withdrawal,$actor);
            });
            $values=$sale($phone,'الاسم الأصلي');$actor=User::withoutGlobalScopes()->findOrFail(1);$before=$fingerprint();$businessCalled=false;
            denied(function()use($p,$values,$actor,&$businessCalled){return app(DesktopDashboardJournal::class)->execute($p['id'],$values['idempotency_key'],1,'takeaway.checkout',
                ['values'=>$values],[],function()use(&$businessCalled,$values,$actor){
                    $businessCalled=true;return app(TakeawayService::class)->checkout($values,$actor,[],true);
                });},409,'a new original offline checkout cannot reopen consent after a prior '.$history);
            check(!$businessCalled&&$fingerprint()===$before,
                'the local '.$history.' reopening gate runs before the original checkout and leaves every ledger and command unchanged');

            // Emulate a real pending command produced by published 86ed158: its Journal
            // allowed this unchanged original business closure. The new local gate must
            // not be bypassed in normal requests; only this old-client wire fixture uses
            // the original service directly to retain its actual persisted result.
            $values['idempotency_key']=(string)Str::uuid();
            $legacy=DB::transaction(function()use($p,$values,$actor){
                $payload=['parameters'=>[],'values'=>$values,'files'=>[],
                    'facts'=>['customer_directory'=>app(DesktopDashboardCustomerFacts::class)->capture('takeaway.checkout',['values'=>$values])]];
                $result=app(TakeawayService::class)->checkout($values,$actor,[],true);
                $result=json_decode(DesktopDashboardBootstrap::json($result),true,512,JSON_THROW_ON_ERROR);
                $references=app(\App\Services\Dashboard\DesktopDashboardReferences::class)->outputs('takeaway.checkout',$result,$values,1,$payload['facts']);
                return ['command_id'=>$values['idempotency_key'],'actor_id'=>1,'route_name'=>'takeaway.checkout','payload'=>$payload,
                    'local_result'=>$result,'local_references'=>$references,'dependencies'=>[],
                    'occurred_at'=>now('UTC')->toIso8601String()];
            });
            check(($legacy['local_result']['success']??false)===true&&isset($legacy['local_references']['receipt'],$legacy['local_references']['crm_customer']),
                'published-client emulation retains the original accepted checkout result and actual typed receipt/CRM outputs');
            $switch($p['server'],false,$p['id']);$id=(int)DB::table('crm_customers')->where('phone',CustomerDirectory::normalizePhone($phone))->value('id');
            $prior=(array)DB::table('crm_customers')->where('id',$id)->first($consentFields);
            if($history==='global STOP')app(CustomerDirectory::class)->consent($id,false,false,'whatsapp-inbound:opt-out');
            else{
                $withdrawal['directory_id']=$id;$withdrawal['idempotency_key']=(string)Str::uuid();
                app(BranchCustomers::class)->consent($withdrawal,User::withoutGlobalScopes()->findOrFail(1));
            }
            check((array)DB::table('crm_customers')->where('id',$id)->first($consentFields)===$prior
                &&!$prior['service_consent']&&($history==='global STOP'?$prior['opted_out_at']!==null:$prior['marketing_consent']&&$prior['opted_out_at']===null),
                'a same-second repeated '.$history.' leaves all nine captured consent fields identical');
            $before=$fingerprint();
            denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($p['device'],$legacy),409,
                'a legacy pending checkout cannot reopen a withdrawn purpose even when every consent before-field still matches');
            check($fingerprint()===$before&&(array)DB::table('crm_customers')->where('id',$id)->first($consentFields)===$prior,
                'rejected legacy '.$history.' reopening preserves every consent field, cash balance, stock, purchase, receipt and journal row');
        }

        $phone='01092000003';$p=$pair($phone);$values=$sale($phone,'الاسم الأصلي');
        $currentResult=$write($p,'takeaway.checkout',$values);$currentEnvelope=app(DesktopDashboardJournal::class)->pending($p['id'])[0];
        check($currentResult['success']&&$currentEnvelope['payload']['facts']['customer_directory']['targets'][0]['customer']['service_consent'],
            'an original checkout retains normal offline collection when its service consent is already current and true');
        $switch($p['server'],false,$p['id']);$currentReceipt=app(DesktopDashboardReconciliation::class)->ingest($p['device'],$currentEnvelope);$before=$fingerprint();
        check($currentReceipt['committed']&&$currentReceipt===app(DesktopDashboardReconciliation::class)->ingest($p['device'],$currentEnvelope)
            &&$fingerprint()===$before,
            'currently true consent still reconciles one original sale and returns its exact saved reply');

        foreach(['takeaway.checkout','customers.save'] as $route){
            $phone=$route==='takeaway.checkout'?'01090000001':'01090000002';$p=$pair($phone);
            \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19T12:00:01Z'));
            $values=$route==='takeaway.checkout'?$sale($phone):[
                'branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'name'=>'اسم محفوظ محليًا',
                'phone'=>$phone,'address'=>'عنوان العميل المحلي','area'=>'المنصورة',
            ];
            $localResult=$write($p,$route,$values);$envelope=app(DesktopDashboardJournal::class)->pending($p['id'])[0];
            check($envelope['payload']['facts']['customer_directory']['targets'][0]['customer']['service_consent']==1,
                $route.' retains the actual prior CRM consent in the encrypted command');
            $localBefore=$fingerprint();
            $again=app(DesktopDashboardJournal::class)->execute($p['id'],$values['idempotency_key'],1,$route,['values'=>$values],[],
                fn()=>throw new RuntimeException('A lost local reply executed the creator twice.'));
            check($again===$localResult&&$fingerprint()===$localBefore,
                $route.' lost local reply reuses immutable pre-write facts and its exact original result');
            $switch($p['server'],false,$p['id']);
            \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19T12:01:00Z'));
            $customer=DB::table('crm_customers')->where('phone',CustomerDirectory::normalizePhone($phone))->first();
            app(CustomerDirectory::class)->consent((int)$customer->id,false,false,'whatsapp-inbound:opt-out');
            if($route==='customers.save')DB::table('crm_customers')->where('id',$customer->id)->update(['name'=>'اسم أحدث على السيرفر']);
            $serverBefore=$fingerprint();
            denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($p['device'],$envelope),409,
                $route.($route==='customers.save'?' cannot overwrite a changed authoritative server profile':' cannot renew consent after a newer server STOP'));
            check($fingerprint()===$serverBefore,
                $route.' STOP conflict rolls back all CRM, cash, stock, purchase, receipt and server-journal effects');
            $stopped=DB::table('crm_customers')->where('id',$customer->id)->first();
            check(!$stopped->service_consent&&!$stopped->marketing_consent&&$stopped->opted_out_at!==null
                &&$stopped->service_consent_evidence==='whatsapp-inbound:opt-out'
                &&$stopped->name===($route==='customers.save'?'اسم أحدث على السيرفر':'الاسم الأصلي'),
                $route.' retains the authoritative STOP evidence and server profile');
            $switch($p['local'],true,$p['id']);
            check(app(DesktopDashboardJournal::class)->pending($p['id'])[0]===$envelope&&$fingerprint()===$localBefore,
                $route.' server conflict leaves the committed local operation and its exact pending envelope intact');
            if($route==='customers.save'){
                $switch($p['server'],false,$p['id']);
                DB::table('crm_customers')->where('id',$customer->id)->update(['name'=>'الاسم الأصلي']);
                $financialTables=['takeaway_orders','takeaway_order_items','takeaway_tills','takeaway_till_entries',
                    'branch_inventory','branch_inventory_movements','branch_recipe_sales','crm_purchases'];
                $financialBefore=$fingerprint($financialTables);
                $reply=app(DesktopDashboardReconciliation::class)->ingest($p['device'],$envelope);
                $stopped=DB::table('crm_customers')->where('id',$customer->id)->first();
                check($reply['committed']&&$stopped->name===$values['name']&&!$stopped->service_consent
                    &&!$stopped->marketing_consent&&$stopped->opted_out_at!==null
                    &&$stopped->service_consent_evidence==='whatsapp-inbound:opt-out'
                    &&$stopped->marketing_consent_evidence==='whatsapp-inbound:opt-out',
                    'a profile-only saved customer write can reconcile after a later STOP while retaining every revoked purpose');
                check($fingerprint($financialTables)===$financialBefore
                    &&app(DesktopDashboardReconciliation::class)->ingest($p['device'],$envelope)===$reply,
                    'the allowed profile-only replay returns its exact reply and creates no cash, stock, purchase or receipt effects');
            }
        }

        $phone='01090000003';$p=$pair();$values=$sale($phone,'اسم العميل المحلي غير المرئي للسيرفر');
        $write($p,'takeaway.checkout',$values);$envelope=app(DesktopDashboardJournal::class)->pending($p['id'])[0];
        check($envelope['payload']['facts']['customer_directory']['targets'][0]['customer']===null,
            'a new local canonical phone explicitly records that no CRM identity existed at capture');
        $switch($p['server'],false,$p['id']);
        $collision=app(CustomerDirectory::class)->capture(['phone'=>'00201090000003','name'=>'هوية سيرفر مستقلة'],
            'delivery','f:101');
        $serverBefore=$fingerprint();
        denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($p['device'],$envelope),409,
            'a canonical-phone collision created outside the snapshot conflicts instead of merging into the offline sale');
        check($fingerprint()===$serverBefore&&DB::table('crm_customer_sources')->where('customer_id',$collision->id)
            ->where('branch','f:100')->count()===0,
            'unseen-phone rejection adds no branch link, purchase, money movement, inventory deduction or committed journal');

        // A real wall-clock delay after consent must not shift journal occurrence away
        // from the original CRM timestamps used by the following dependent command.
        $phone='01090000004';$p=$pair(null,true);$first=$sale($phone,'عميل بعمليتين محليتين');
        check(\Carbon\Carbon::getTestNow()===null,'the mapped CRM timing regression uses a real unfrozen caller clock');
        $wallStart=microtime(true);$firstResult=$write($p,'takeaway.checkout',$first,1200000);
        $localCustomer=(int)DB::table('crm_customers')->where('phone',CustomerDirectory::normalizePhone($phone))->value('id');
        $firstEnvelope=app(DesktopDashboardJournal::class)->pending($p['id'])[0];
        $firstConsentedAt=DB::table('crm_customers')->where('id',$localCustomer)->value('service_consented_at');
        check(microtime(true)-$wallStart>=1.1&&\Carbon\Carbon::getTestNow()===null
            &&$firstConsentedAt===\Carbon\Carbon::parse($firstEnvelope['occurred_at'])->setTimezone('UTC')->format('Y-m-d H:i:s'),
            'a delayed original creator pins consent and command occurrence to one instant and restores the caller real clock');
        check(($firstEnvelope['local_references']['crm_customer']??null)===$localCustomer
            &&($firstEnvelope['local_references']['receipt']??null)===$firstResult['receipt']['id'],
            'an original local sale exports distinct typed CRM and payment receipt identities');
        $second=$sale($phone,'عميل بعمليتين محليتين');$secondResult=$write($p,'takeaway.checkout',$second);
        $secondRow=DB::table('desktop_dashboard_commands')->where('device_id',$p['id'])->where('command_id',$second['idempotency_key'])->first();
        $secondPayload=json_decode(Crypt::decryptString($secondRow->command_cipher),true,512,JSON_THROW_ON_ERROR);
        $before=$secondPayload['facts']['customer_directory']['targets'][0]['customer'];
        check(($before['id']['$desktop_ref']['entity']??null)==='crm_customer'
            &&($before['id']['$desktop_ref']['command_id']??null)===$first['idempotency_key']
            &&($before['service_consent_evidence']['$desktop_crm_evidence']['prefix']??null)==='pos-checkout:'
            &&($before['service_consent_evidence']['$desktop_crm_evidence']['reference']['$desktop_ref']['entity']??null)==='receipt'
            &&json_decode($secondRow->dependencies,true)===[$first['idempotency_key']],
            'a next sale from the same phone binds its prior CRM row and receipt-based consent evidence to the first command');
        $localBefore=$fingerprint();
        $duplicate=app(DesktopDashboardJournal::class)->execute($p['id'],$second['idempotency_key'],1,'takeaway.checkout',
            ['values'=>$second],[],fn()=>throw new RuntimeException('A duplicate next sale ran twice.'));
        check($duplicate===$secondResult&&$fingerprint()===$localBefore,
            'lost next-sale replies rebuild the identical typed facts without a second local financial effect');

        $switch($p['server'],false,$p['id']);
        DB::statement('ALTER TABLE crm_customers AUTO_INCREMENT='.($localCustomer+1000));
        DB::statement('ALTER TABLE takeaway_orders AUTO_INCREMENT='.($secondResult['receipt']['id']+1000));
        $orderCount=DB::table('takeaway_orders')->count();$cash=(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents');
        $stock=(int)DB::table('branch_inventory')->where('branch','f:100')->where('ingredient_id',6)->value('quantity_units');
        $withoutFacts=$firstEnvelope;$withoutFacts['command_id']=(string)Str::uuid();
        $withoutFacts['payload']['values']['idempotency_key']=$withoutFacts['command_id'];unset($withoutFacts['payload']['facts']['customer_directory']);
        $serverBefore=$fingerprint();
        denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($p['device'],$withoutFacts),409,
            'an old creator envelope missing immutable customer facts fails before any financial operation');
        check($fingerprint()===$serverBefore,'missing customer facts leave all server business and command tables unchanged');
        $firstReceipt=app(DesktopDashboardReconciliation::class)->ingest($p['device'],$firstEnvelope);
        $mapped=collect($firstReceipt['references'])->keyBy('entity');
        check($firstReceipt['committed']&&$mapped['crm_customer']['local_id']===$localCustomer
            &&$mapped['crm_customer']['server_id']!==$localCustomer
            &&$mapped['receipt']['local_id']===$firstResult['receipt']['id']
            &&$mapped['receipt']['server_id']!==$firstResult['receipt']['id'],
            'normal reconciliation maps both CRM and receipt integer drift to the correct server identities');
        $firstServerState=$fingerprint();
        check(app(DesktopDashboardReconciliation::class)->ingest($p['device'],$firstEnvelope)===$firstReceipt
            &&$fingerprint()===$firstServerState,
            'a lost normal server reply returns its exact saved receipt without reapplying CRM, purchase, cash or stock effects');
        $switch($p['local'],true,$p['id']);app(DesktopDashboardJournal::class)->acknowledge($p['id'],$first['idempotency_key'],$firstReceipt);
        $secondEnvelope=app(DesktopDashboardJournal::class)->pending($p['id'])[0];
        check($secondEnvelope['command_id']===$second['idempotency_key'],
            'the dependent same-phone sale becomes sendable only after its CRM and receipt mappings are acknowledged');
        $switch($p['server'],false,$p['id']);
        $secondReceipt=app(DesktopDashboardReconciliation::class)->ingest($p['device'],$secondEnvelope);
        $secondServerState=$fingerprint();
        check($secondReceipt===app(DesktopDashboardReconciliation::class)->ingest($p['device'],$secondEnvelope)
            &&$fingerprint()===$secondServerState,
            'mapped same-phone CRM and consent-evidence facts permit the unchanged next sale and its exact duplicate reply');
        $customer=DB::table('crm_customers')->where('phone',CustomerDirectory::normalizePhone($phone))->first();
        check((int)$customer->id===$mapped['crm_customer']['server_id']&&$customer->service_consent
            &&$customer->service_consent_evidence==='pos-checkout:'.$secondReceipt['result']['receipt']['id']
            &&DB::table('crm_customers')->where('phone',$customer->phone)->count()===1
            &&DB::table('crm_purchases')->where('customer_id',$customer->id)->count()===2,
            'two mapped sales keep one canonical CRM identity and two purchases with current server receipt evidence');
        $paid=DB::table('takeaway_orders')->whereIn('request_key',[$first['idempotency_key'],$second['idempotency_key']])->sum('total_cents');
        check(DB::table('takeaway_orders')->count()===$orderCount+2
            &&(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===$cash+(int)$paid
            &&(int)DB::table('branch_inventory')->where('branch','f:100')->where('ingredient_id',6)->value('quantity_units')===$stock-500000,
            'the unchanged two-sale flow commits exactly two original cash collections and recipe deductions');
        $switch($p['local'],true,$p['id']);app(DesktopDashboardJournal::class)->acknowledge($p['id'],$second['idempotency_key'],$secondReceipt);
        check(app(DesktopDashboardJournal::class)->counts($p['id'])['pending']===0,
            'both original same-phone operations remain locally committed and leave no pending duplicate after acknowledgement');

        $actor=$switch($p['server'],false,$p['id']);$unexpected=$sale('01090000006','عميل خارج الحقائق المحفوظة');
        $emptyTargets=['values'=>$unexpected,'facts'=>['customer_directory'=>['version'=>1,'available'=>true,'targets'=>[]]]];
        $serverBefore=$fingerprint();
        denied(fn()=>app(DesktopDashboardCustomerFacts::class)->replay($emptyTargets,
            fn()=>app(TakeawayService::class)->checkout($unexpected,$actor,[],true)),409,
            'an active replay with frozen empty customer targets rejects an actual checkout that discovers a new phone');
        check($fingerprint()===$serverBefore,
            'an unexpected CRM capture rolls back the original checkout receipt, cash, stock, purchase and identity inserts');
        $onlineCustomer=app(CustomerDirectory::class)->capture(['phone'=>'01090000006','name'=>'عميل أونلاين عادي'],'takeaway','f:100');
        check($onlineCustomer&&$onlineCustomer->phone===CustomerDirectory::normalizePhone('01090000006')
            &&$onlineCustomer->name==='عميل أونلاين عادي'
            &&DB::table('crm_customer_sources')->where('customer_id',$onlineCustomer->id)->where('branch','f:100')->where('channel','takeaway')->exists(),
            'the failed frozen replay restores its context so ordinary online customer capture still succeeds');

        $racing=$sale('01090000007','ملف محلي لا يملك السجل المتسابق');
        $frozen=DB::transaction(fn()=>app(DesktopDashboardCustomerFacts::class)->capture('takeaway.checkout',['values'=>$racing]));
        check($frozen['targets'][0]['customer']===null,
            'the insert-race fixture captures an absent canonical phone in a completed real transaction');
        $racingCustomer=app(CustomerDirectory::class)->capture(['phone'=>'00201090000007','name'=>'سجل فرع آخر بعد فحص الحقائق'],
            'delivery','f:101');
        $serverBefore=$fingerprint();
        denied(fn()=>app(DesktopDashboardCustomerFacts::class)->replay(
            ['values'=>$racing,'facts'=>['customer_directory'=>$frozen]],
            fn()=>app(TakeawayService::class)->checkout($racing,$actor,[],true)),409,
            'INSERT ownership rejects an unseen canonical-phone record inserted after frozen facts without rerunning the initial guard');
        check($fingerprint()===$serverBefore
            &&DB::table('crm_customers')->where('id',$racingCustomer->id)->value('name')==='سجل فرع آخر بعد فحص الحقائق'
            &&!DB::table('crm_customer_sources')->where('customer_id',$racingCustomer->id)->where('branch','f:100')->exists(),
            'the post-check canonical-phone race preserves the foreign profile and sources and rolls back every original financial effect');
        require __DIR__.'/customer-provider-ui.php';
    }finally{
        config($savedConfig);DB::purge();\Carbon\Carbon::setTestNow($savedClock);
    }
})();
