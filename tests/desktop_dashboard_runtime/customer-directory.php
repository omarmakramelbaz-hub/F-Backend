<?php
// Actual CRM forms use the original controller, its middleware, the local journal and real reconciliation.
use App\Http\Controllers\Dashboard\BranchOperationsController;
use App\Http\Middleware\DesktopDashboardJournal as CustomerUiMiddleware;
use App\Models\User;
use App\Services\Dashboard\{BranchCustomers,CustomerDirectory,DesktopDashboardBootstrap,DesktopDashboardDevices,
    DesktopDashboardImport,DesktopDashboardJournal,DesktopDashboardReconciliation};
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;

(function()use($pdo,$remoteDatabase){
    $savedConfig=[
        'database.connections.mysql.database'=>config('database.connections.mysql.database'),
        'desktop_dashboard.local'=>config('desktop_dashboard.local'),
        'desktop_dashboard.device_id'=>config('desktop_dashboard.device_id'),
        'desktop_dashboard.enabled'=>config('desktop_dashboard.enabled'),
    ];
    $savedClock=\Carbon\Carbon::getTestNow();$guard=auth('admin');$savedActor=$guard->getUser();
    $switch=function(string $database,bool $local,string $device): void{
        config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>$local,
            'desktop_dashboard.device_id'=>$device,'desktop_dashboard.enabled'=>true]);DB::purge();
    };
    $pair=function(?string $phone=null)use($pdo,$remoteDatabase,$switch): array{
        $server='desktop_customer_ui_'.bin2hex(random_bytes(8));$local='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));
        foreach([$server,$local] as $database){
            $pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
        }
        foreach($pdo->query('SHOW TABLES FROM `'.$remoteDatabase.'`')->fetchAll(PDO::FETCH_COLUMN) as $table){
            $pdo->exec('CREATE TABLE `'.$server.'`.`'.$table.'` LIKE `'.$remoteDatabase.'`.`'.$table.'`');
            $pdo->exec('INSERT INTO `'.$server.'`.`'.$table.'` SELECT * FROM `'.$remoteDatabase.'`.`'.$table.'`');
        }
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19T13:00:00Z'));
        $id=(string)Str::uuid();$switch($server,false,$id);$actor=User::withoutGlobalScopes()->findOrFail(1);
        if($phone!==null)app(BranchCustomers::class)->save(['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),
            'name'=>'الملف الأصلي','phone'=>$phone,'address'=>'العنوان الأصلي','area'=>'المنصورة'],$actor);
        $link=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$id,'name'=>'CRM original UI fixture',
            'nonce'=>bin2hex(random_bytes(32))],$actor);
        $device=app(DesktopDashboardDevices::class)->device($link['token']);
        $snapshot=app(DesktopDashboardBootstrap::class)->export($device);
        $switch($local,true,$id);$receipt=app(DesktopDashboardImport::class)->import($snapshot);
        check($receipt['device_id']===$id&&DB::table('desktop_dashboard_commands')->count()===0,
            'original CRM UI fixture imports an independent real account generation');
        return compact('server','local','id','device');
    };
    $request=function(string $name,array $values=[],?string $method=null): Request{
        $original=app('router')->getRoutes()->getByName($name);
        if(!$original)throw new RuntimeException('Missing original CRM route '.$name);
        $request=Request::create(url('/'.$original->uri()),$method??$original->methods()[0],$values);
        $route=clone $original;$route->bind($request);$request->setRouteResolver(fn()=>$route);
        $request->headers->set('Accept','application/json');return $request;
    };
    $controllerCalls=0;
    $localWrite=function(string $route,array $values)use($request,$guard,&$controllerCalls): array{
        $guard->setUser(User::withoutGlobalScopes()->findOrFail(1));$req=$request($route,$values);
        $response=app(CustomerUiMiddleware::class)->handle($req,function($req)use($route,&$controllerCalls){
            $controllerCalls++;$router=app('router');$original=$req->route();
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),$original->controllerMiddleware());
            return (new Pipeline(app()))->send($req)->through($middleware)->then(function($req)use($route){
                $controller=app(BranchOperationsController::class);$service=app(BranchCustomers::class);
                if($route==='customers.save')return $controller->save($req);
                return $route==='customers.directory-save'?$controller->directorySave($req,$service):$controller->customerConsent($req,$service);
            });
        });
        check($response->getStatusCode()===200,'the original '.$route.' controller returns its successful local JSON response');
        return json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
    };
    $fingerprint=function(bool $financialOnly=false): string{
        $tables=['takeaway_orders','takeaway_order_items','takeaway_tills','takeaway_till_entries',
            'branch_inventory','branch_inventory_movements','branch_recipe_sales','branch_stock','branch_stock_movements','crm_purchases'];
        if(!$financialOnly)$tables=array_merge($tables,['crm_customers','crm_customer_sources','branch_customers',
            'branch_operation_commands','desktop_dashboard_commands','desktop_dashboard_entities']);
        $state=[];foreach($tables as $table){$rows=DB::table($table)->get()->map(fn($row)=>DesktopDashboardBootstrap::json((array)$row))->all();sort($rows,SORT_STRING);$state[$table]=$rows;}
        return app(DesktopDashboardJournal::class)->fingerprint($state);
    };
    $ack=function(array $p,array $envelope,array $receipt)use($switch): void{
        $switch($p['local'],true,$p['id']);app(DesktopDashboardJournal::class)->acknowledge($p['id'],$envelope['command_id'],$receipt);
    };
    $envelope=function(array $p): array{
        $pending=app(DesktopDashboardJournal::class)->pending($p['id']);
        if(count($pending)!==1)throw new RuntimeException('The CRM fixture expected exactly one sendable command.');
        return $pending[0];
    };
    try{
        $p=$pair();$financialBefore=$fingerprint(true);
        $create=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'channel'=>'delivery',
            'phone'=>'01091000001','name'=>'عميل أصلي جديد','address'=>'عنوان أول','area'=>'المنصورة'];
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19T13:00:01Z'));
        $created=$localWrite('customers.directory-save',$create);$localId=(int)$created['customer']['id'];$createEnvelope=$envelope($p);
        $localBefore=$fingerprint();$callsBefore=$controllerCalls;
        check($localWrite('customers.directory-save',$create)===$created&&$controllerCalls===$callsBefore&&$fingerprint()===$localBefore,
            'a lost original CRM create reply returns identical JSON without reentering the original controller');
        $changed=$create;$changed['name']='طلب مختلف بنفس UUID';
        denied(fn()=>$localWrite('customers.directory-save',$changed),409,'a changed profile payload cannot reuse a committed local CRM operation UUID');
        check($fingerprint()===$localBefore,'rejected local CRM UUID reuse changes no customer, source, command or financial data');
        $update=$create;$update['idempotency_key']=(string)Str::uuid();$update['directory_id']=$localId;
        $update['expected_updated_at']=$created['customer']['updated_at'];$update['name']='عميل محدث محليًا';$update['address']='عنوان ثان';
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19T13:00:02Z'));
        $updated=$localWrite('customers.directory-save',$update);
        $consent=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'directory_id'=>$localId,
            'service_consent'=>true,'marketing_consent'=>false,'consent_scope'=>'service','consent_method'=>'verbal'];
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19T13:00:03Z'));
        $consented=$localWrite('customers.consent',$consent);$consentBefore=$fingerprint();$callsBefore=$controllerCalls;
        check($localWrite('customers.consent',$consent)===$consented&&$controllerCalls===$callsBefore&&$fingerprint()===$consentBefore,
            'a lost original service-consent reply retains exact evidence and does not attest again');
        $row=DB::table('desktop_dashboard_commands')->where('command_id',$update['idempotency_key'])->first();
        $payload=json_decode(Crypt::decryptString($row->command_cipher),true,512,JSON_THROW_ON_ERROR);
        check(($payload['values']['directory_id']['$desktop_ref']['entity']??null)==='crm_customer'
            &&($payload['facts']['customer_before']['customer']['id']['$desktop_ref']['command_id']??null)===$create['idempotency_key'],
            'the original profile form binds both submitted and before-fact CRM IDs to the creating command');
        check($fingerprint(true)===$financialBefore,'CRM creation, profile editing and service consent create no sales, purchases, cash or inventory effects');

        $switch($p['server'],false,$p['id']);DB::statement('ALTER TABLE crm_customers AUTO_INCREMENT='.($localId+1000));
        $remoteFinancial=$fingerprint(true);$replies=[];
        foreach([['customers.directory-save',$create['idempotency_key']],['customers.directory-save',$update['idempotency_key']],['customers.consent',$consent['idempotency_key']]] as [$route,$command]){
            $switch($p['local'],true,$p['id']);$pending=$envelope($p);
            check($pending['route_name']===$route&&$pending['command_id']===$command,'the original CRM outbox preserves create, profile and consent dependency order');
            $switch($p['server'],false,$p['id']);$reply=app(DesktopDashboardReconciliation::class)->ingest($p['device'],$pending);
            $serverBefore=$fingerprint();
            check($reply===app(DesktopDashboardReconciliation::class)->ingest($p['device'],$pending)&&$fingerprint()===$serverBefore,
                'lost server '.$route.' replies return identical committed receipts without repeating their effects');
            $replies[]=$reply;
            if($command===$create['idempotency_key']){
                $mapping=collect($reply['references'])->keyBy('entity');
                check($mapping['crm_customer']['local_id']===$localId&&$mapping['crm_customer']['server_id']!==$localId,
                    'the original CRM create maps a genuine local/server CRM integer ID drift');
                $branches=DB::table('desktop_dashboard_devices')->where('id',$p['id'])->value('branches');
                DB::table('desktop_dashboard_devices')->where('id',$p['id'])->update(['branches'=>json_encode(['f:101'])]);
                $before=$fingerprint();
                denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($p['device'],$pending),403,
                    'a stale device object cannot replay even a saved CRM receipt outside its current locked enrollment');
                check($fingerprint()===$before,'current-enrollment rejection leaves the saved CRM receipt and every business row unchanged');
                DB::table('desktop_dashboard_devices')->where('id',$p['id'])->update(['branches'=>$branches]);
                DB::table('users')->where('id',1)->update(['account_type'=>'user']);$before=$fingerprint();
                denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($p['device'],$pending),403,
                    'a revoked persisted account cannot receive a previously committed CRM receipt');
                check($fingerprint()===$before,'account revocation is checked before returning cached CRM success');
                DB::table('users')->where('id',1)->update(['account_type'=>'admin']);
            }
            $ack($p,$pending,$reply);
        }
        check(app(DesktopDashboardJournal::class)->counts($p['id'])['pending']===0,
            'normal original CRM create, update and consent commands are all acknowledged after mapping');
        $switch($p['server'],false,$p['id']);$customer=DB::table('crm_customers')->where('phone','+201091000001')->first();
        check((int)$customer->id!==$localId&&$customer->name===$update['name']&&$customer->address===$update['address']
            &&$customer->service_consent&&!$customer->marketing_consent&&$customer->opted_out_at===null
            &&$fingerprint(true)===$remoteFinancial,
            'mapped original CRM forms preserve the edited profile and service-only consent without financial side effects');
        $changedServer=$createEnvelope;$changedServer['payload']['values']['name']='تغيير بعد تأكيد السيرفر';$before=$fingerprint();
        denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($p['device'],$changedServer),409,
            'a changed original CRM payload cannot reuse an already committed server UUID');
        check($fingerprint()===$before,'rejected server UUID reuse preserves the exact saved CRM receipt and customer');

        // CREATE has no before-customer ID: cached replies must bind the persisted output identity instead.
        foreach(['local saved CREATE reply'=>[$p['local'],true,$localId,$created],
            'server saved CREATE receipt'=>[$p['server'],false,(int)$customer->id,$replies[0]]] as $label=>[$database,$local,$crmId,$expected]){
            $switch($database,$local,$p['id']);
            $cachedCreate=function()use($local,$localWrite,$create,$p,$createEnvelope): array{
                return $local?$localWrite('customers.directory-save',$create)
                    :app(DesktopDashboardReconciliation::class)->ingest($p['device'],$createEnvelope);
            };
            $before=$fingerprint();$callsBefore=$controllerCalls;
            check(DB::table('crm_customers')->where('id',$crmId)->value('name')===$update['name']
                &&$cachedCreate()===$expected&&$fingerprint()===$before&&$controllerCalls===$callsBefore,
                $label.' retains its exact original outcome after later profile and consent commands without replaying CREATE');
            $source=(array)DB::table('crm_customer_sources')->where('customer_id',$crmId)->where('branch','f:100')->first();
            check(isset($source['id']),$label.' binds the actual persisted CRM output and selected-branch source');
            DB::table('crm_customer_sources')->where('id',$source['id'])->delete();
            try{
                $before=$fingerprint();$callsBefore=$controllerCalls;
                denied($cachedCreate,409,$label.' rejects a removed branch source even though its captured before-customer is null');
                check($fingerprint()===$before&&$controllerCalls===$callsBefore,
                    $label.' source rejection executes no controller and changes no customer, command or financial row');
            }finally{
                DB::table('crm_customer_sources')->insert($source);
            }
            $identity=(array)DB::table('crm_customers')->where('id',$crmId)->first(['phone','phone_key']);
            $otherPhone=CustomerDirectory::normalizePhone('01091999991');
            DB::table('crm_customers')->where('id',$crmId)->update(['phone'=>$otherPhone,'phone_key'=>CustomerDirectory::phoneKey($otherPhone)]);
            try{
                $before=$fingerprint();$callsBefore=$controllerCalls;
                denied($cachedCreate,409,$label.' rejects a changed canonical identity using its durable output ID and historic phone');
                check($fingerprint()===$before&&$controllerCalls===$callsBefore,
                    $label.' identity rejection preserves all current profile, source, receipt and financial data');
            }finally{
                DB::table('crm_customers')->where('id',$crmId)->update($identity);
            }

            $outside=new PDO('mysql:host=127.0.0.1;port='.config('database.connections.mysql.port').';dbname='.$database.';charset=utf8mb4',
                'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            DB::beginTransaction();
            try{
                check(DB::table('crm_customer_sources')->where('id',$source['id'])->exists(),
                    $label.' warms a repeatable-read snapshot containing the CREATE output source');
                $outside->prepare('DELETE FROM crm_customer_sources WHERE id=?')->execute([$source['id']]);
                check((int)$outside->query('SELECT COUNT(*) FROM crm_customer_sources WHERE id='.(int)$source['id'])->fetchColumn()===0
                    &&DB::table('crm_customer_sources')->where('id',$source['id'])->exists(),
                    $label.' retains a genuinely stale CREATE source snapshot after concurrent removal commits');
                $before=$fingerprint();$callsBefore=$controllerCalls;
                denied($cachedCreate,409,$label.' uses a current locking source read despite its null before-customer and warmed snapshot');
                check($fingerprint()===$before&&$controllerCalls===$callsBefore,
                    $label.' rejects concurrent CREATE-source revocation without controller or business replay');
            }finally{
                DB::rollBack();
                $columns=['id','customer_id','branch','channel','first_seen_at','last_seen_at','created_at','updated_at'];
                $outside->prepare('INSERT INTO crm_customer_sources (`'.implode('`,`',$columns).'`) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute(array_map(fn($column)=>$source[$column],$columns));
                $outside=null;
            }
            $before=$fingerprint();$callsBefore=$controllerCalls;
            check($cachedCreate()===$expected&&$fingerprint()===$before&&$controllerCalls===$callsBefore,
                $label.' returns the original exact outcome after its identity and source are restored');
        }

        $p=$pair('01091000002');$localCustomer=DB::table('crm_customers')->where('phone','+201091000002')->first();
        $update=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'directory_id'=>(int)$localCustomer->id,
            'expected_updated_at'=>$localCustomer->updated_at,'phone'=>$localCustomer->phone,'channel'=>'delivery',
            'name'=>'تعديل الملف المحلي','address'=>'عنوان بعد التعديل','area'=>'المنصورة'];
        $updated=$localWrite('customers.directory-save',$update);$profileEnvelope=$envelope($p);
        $consent=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'directory_id'=>(int)$localCustomer->id,
            'service_consent'=>true,'marketing_consent'=>false,'consent_scope'=>'service','consent_method'=>'verbal'];
        $localWrite('customers.consent',$consent);
        $switch($p['server'],false,$p['id']);
        DB::table('crm_customers')->where('id',$localCustomer->id)->update(['name'=>'ملف أحدث على السيرفر']);$before=$fingerprint();
        denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($p['device'],$profileEnvelope),409,
            'an original offline profile form cannot overwrite a changed authoritative CRM profile');
        check($fingerprint()===$before,'stale profile rejection changes no CRM profile, saved delivery revision or journal row');
        DB::table('crm_customers')->where('id',$localCustomer->id)->update(['name'=>'الملف الأصلي']);
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19T13:01:00Z'));
        app(CustomerDirectory::class)->consent((int)$localCustomer->id,false,false,'whatsapp-inbound:opt-out');
        $consentFields=['service_consent','marketing_consent','service_consented_at','marketing_consented_at',
            'service_consent_evidence','marketing_consent_evidence','service_consented_by','marketing_consented_by','opted_out_at'];
        $stop=(array)DB::table('crm_customers')->where('id',$localCustomer->id)->first($consentFields);$financial=$fingerprint(true);
        $profileReply=app(DesktopDashboardReconciliation::class)->ingest($p['device'],$profileEnvelope);
        check($profileReply['committed']&&(array)DB::table('crm_customers')->where('id',$localCustomer->id)->first($consentFields)===$stop
            &&DB::table('crm_customers')->where('id',$localCustomer->id)->value('name')===$update['name']&&$fingerprint(true)===$financial,
            'the original profile-only UI form can save after a later STOP without changing any consent purpose or money');
        $savedProfile=DB::table('branch_customers')->where('branch','f:100')->where('phone_key',$localCustomer->phone_key)->first();
        check($savedProfile->name===$update['name']&&(int)$savedProfile->revision===2,
            'profile reconciliation retains the original saved-delivery update and its single optimistic revision increment');
        $ack($p,$profileEnvelope,$profileReply);$positiveEnvelope=$envelope($p);$switch($p['server'],false,$p['id']);$before=$fingerprint();
        denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($p['device'],$positiveEnvelope),409,
            'an older explicit positive consent form cannot clear a newer incoming STOP on reconnect');
        check($fingerprint()===$before&&(array)DB::table('crm_customers')->where('id',$localCustomer->id)->first($consentFields)===$stop,
            'a rejected positive consent preserves every STOP field and creates no branch receipt or command');
        $switch($p['local'],true,$p['id']);check($envelope($p)===$positiveEnvelope,
            'a STOP conflict retains the committed local consent and exact original pending envelope for review');

        $p=$pair();$create=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'channel'=>'delivery',
            'phone'=>'01091000003','name'=>'اسم محلي جديد','address'=>'عنوان محلي'];
        $localWrite('customers.directory-save',$create);$collisionEnvelope=$envelope($p);
        $switch($p['server'],false,$p['id']);
        $collision=app(CustomerDirectory::class)->capture(['phone'=>'00201091000003','name'=>'هوية فرع آخر'],'delivery','f:101');$before=$fingerprint();
        denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($p['device'],$collisionEnvelope),409,
            'an unseen server canonical phone collision cannot be merged by the original offline CRM create form');
        check($fingerprint()===$before&&!DB::table('crm_customer_sources')->where('customer_id',$collision->id)->where('branch','f:100')->exists(),
            'a canonical-phone collision keeps the foreign profile, branch links, local command and all financial data unchanged');

        $p=$pair();$savedValues=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),
            'phone'=>'01091000004','name'=>'عميل محفوظ بمرجعين','address'=>'عنوان عميل الدليفري','area'=>'المنصورة'];
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19T13:00:01Z'));
        $savedResult=$localWrite('customers.save',$savedValues);$localSavedId=(int)$savedResult['customer']['id'];
        $crm=DB::table('crm_customers')->where('phone','+201091000004')->first();$localCrmId=(int)$crm->id;
        $savedEnvelope=$envelope($p);
        check(($savedEnvelope['local_references']['customer']??null)===$localSavedId
            &&($savedEnvelope['local_references']['crm_customer']??null)===$localCrmId,
            'the original saved-delivery customer form emits independent saved-profile and CRM identities');
        $profileValues=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'directory_id'=>$localCrmId,
            'expected_updated_at'=>$crm->updated_at,'channel'=>'delivery','phone'=>$crm->phone,
            'name'=>'عميل محفوظ بعد التعديل','address'=>'عنوان محدث بمرجعين','area'=>'المنصورة'];
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19T13:00:02Z'));
        $profileResult=$localWrite('customers.directory-save',$profileValues);
        $profileRow=DB::table('desktop_dashboard_commands')->where('command_id',$profileValues['idempotency_key'])->first();
        $profilePayload=json_decode(Crypt::decryptString($profileRow->command_cipher),true,512,JSON_THROW_ON_ERROR);
        check(($profilePayload['values']['directory_id']['$desktop_ref']['entity']??null)==='crm_customer'
            &&($profilePayload['facts']['customer_before']['saved_profiles'][0]['id']['$desktop_ref']['entity']??null)==='customer'
            &&json_decode($profileRow->dependencies,true)===[$savedValues['idempotency_key']],
            'the CRM profile form carries distinct typed CRM and saved-delivery before-facts from one actual producer');
        $approveValues=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'directory_id'=>$localCrmId,
            'service_consent'=>true,'marketing_consent'=>true,'consent_scope'=>'all','consent_method'=>'verbal'];
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19T13:00:03Z'));
        $approvedResult=$localWrite('customers.consent',$approveValues);
        $marketingFields=['marketing_consent','marketing_consented_at','marketing_consent_evidence','marketing_consented_by'];
        $marketing=(array)DB::table('crm_customers')->where('id',$localCrmId)->first($marketingFields);
        $withdrawValues=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'directory_id'=>$localCrmId,
            'service_consent'=>false,'marketing_consent'=>true,'consent_scope'=>'service','consent_method'=>'withdrawal'];
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19T13:00:04Z'));
        $withdrawnResult=$localWrite('customers.consent',$withdrawValues);
        $withdrawn=DB::table('crm_customers')->where('id',$localCrmId)->first();
        check(!$withdrawn->service_consent&&$withdrawn->marketing_consent&&$withdrawn->opted_out_at===null
            &&(array)DB::table('crm_customers')->where('id',$localCrmId)->first($marketingFields)===$marketing,
            'an unchanged service-only withdrawal preserves the other purpose flag, timestamp, evidence and actor exactly');
        $switch($p['server'],false,$p['id']);
        DB::statement('ALTER TABLE branch_customers AUTO_INCREMENT='.($localSavedId+1000));
        DB::statement('ALTER TABLE crm_customers AUTO_INCREMENT='.($localCrmId+1000));$financial=$fingerprint(true);
        $serverCrmId=null;$serverSavedId=null;$profileCommittedEnvelope=null;
        foreach([$savedValues['idempotency_key'],$profileValues['idempotency_key'],$approveValues['idempotency_key'],$withdrawValues['idempotency_key']] as $command){
            $switch($p['local'],true,$p['id']);$pending=$envelope($p);
            check($pending['command_id']===$command,'dual-identity CRM dependencies retain the original saved-profile, edit, approval and withdrawal order');
            if($command===$profileValues['idempotency_key'])$profileCommittedEnvelope=$pending;
            $switch($p['server'],false,$p['id']);$receipt=app(DesktopDashboardReconciliation::class)->ingest($p['device'],$pending);$before=$fingerprint();
            check($receipt===app(DesktopDashboardReconciliation::class)->ingest($p['device'],$pending)&&$fingerprint()===$before,
                'a mapped dual-identity CRM operation returns the exact server receipt without a second mutation');
            if($command===$savedValues['idempotency_key']){
                $mapped=collect($receipt['references'])->keyBy('entity');$serverCrmId=$mapped['crm_customer']['server_id'];$serverSavedId=$mapped['customer']['server_id'];
                check($serverCrmId!==$localCrmId&&$serverSavedId!==$localSavedId,
                    'both the CRM identity and saved-delivery profile use genuinely different server integer IDs');
            }
            $ack($p,$pending,$receipt);
        }
        $before=$fingerprint();$callsBefore=$controllerCalls;
        check($localWrite('customers.save',$savedValues)===$savedResult
            &&$localWrite('customers.directory-save',$profileValues)===$profileResult
            &&$localWrite('customers.consent',$approveValues)===$approvedResult
            &&$localWrite('customers.consent',$withdrawValues)===$withdrawnResult
            &&$controllerCalls===$callsBefore&&$fingerprint()===$before,
            'lost earlier UI replies remain exact after every later dependency receipt without becoming their own dependency or changing consent');
        $switch($p['server'],false,$p['id']);$serverCrm=DB::table('crm_customers')->where('id',$serverCrmId)->first();
        $serverSaved=DB::table('branch_customers')->where('id',$serverSavedId)->first();
        check($serverCrm->name===$profileValues['name']&&$serverSaved->name===$profileValues['name']
            &&(int)$serverSaved->revision===2&&!$serverCrm->service_consent&&$serverCrm->marketing_consent&&$serverCrm->opted_out_at===null
            &&(array)DB::table('crm_customers')->where('id',$serverCrmId)->first($marketingFields)===$marketing
            &&$fingerprint(true)===$financial,
            'dual-ID reconciliation edits the correct saved profile once and preserves scoped withdrawal without financial effects');

        $switch($p['local'],true,$p['id']);
        $reopenValues=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'directory_id'=>$localCrmId,
            'service_consent'=>true,'marketing_consent'=>true,'consent_scope'=>'service','consent_method'=>'verbal'];
        $before=$fingerprint();$callsBefore=$controllerCalls;
        denied(fn()=>$localWrite('customers.consent',$reopenValues),409,
            'a local positive service-consent form cannot reopen a recorded scoped withdrawal while marketing remains enabled');
        check($fingerprint()===$before&&$controllerCalls===$callsBefore,
            'the recorded-purpose UI reopening gate rejects before the original controller and changes no consent or command data');

        // Older desktop builds allowed this original staff-attestation closure. Emulate
        // that old client only in this fixture so the server receives a genuine before
        // state, real original result and the normal typed reference/dependency wire.
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19T13:00:05Z'));
        $reopenValues['idempotency_key']=(string)Str::uuid();
        $legacyReopen=DB::transaction(function()use($p,$reopenValues,$request,$guard){
            $actor=User::withoutGlobalScopes()->findOrFail(1);$guard->setUser($actor);
            $scope=app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->lock($p['id']);
            $payload=app(\App\Services\Dashboard\DesktopDashboardCustomerDirectory::class)->payload(
                'customers.consent',$request('customers.consent',$reopenValues),$actor,$scope);
            $references=app(\App\Services\Dashboard\DesktopDashboardReferences::class);
            $prepared=$references->prepare($p['id'],'customers.consent',$payload,$reopenValues['idempotency_key']);
            $result=app(BranchCustomers::class)->consent($reopenValues,$actor);
            $result=json_decode(DesktopDashboardBootstrap::json($result),true,512,JSON_THROW_ON_ERROR);
            return ['command_id'=>$reopenValues['idempotency_key'],'actor_id'=>1,'route_name'=>'customers.consent',
                'payload'=>$prepared['payload'],'local_result'=>$result,
                'local_references'=>$references->outputs('customers.consent',$result,$reopenValues,1,$payload['facts']),
                'dependencies'=>$prepared['dependencies'],'occurred_at'=>now('UTC')->toIso8601String()];
        });
        check($legacyReopen['local_result']['consent']['service_consent']
            &&($legacyReopen['payload']['values']['directory_id']['$desktop_ref']['entity']??null)==='crm_customer'
            &&in_array($savedValues['idempotency_key'],$legacyReopen['dependencies'],true),
            'legacy UI emulation retains the actually accepted original consent result and real mapped CRM dependency');
        $reopenFields=['service_consent','marketing_consent','service_consented_at','marketing_consented_at',
            'service_consent_evidence','marketing_consent_evidence','service_consented_by','marketing_consented_by','opted_out_at'];
        $legacyBefore=array_intersect_key($legacyReopen['payload']['facts']['customer_before']['customer'],array_flip($reopenFields));
        $switch($p['server'],false,$p['id']);
        $serverBefore=(array)DB::table('crm_customers')->where('id',$serverCrmId)->first($reopenFields);
        check($serverBefore===$legacyBefore&&!$serverBefore['service_consent']&&$serverBefore['marketing_consent']
            &&$serverBefore['opted_out_at']===null,
            'the legacy UI command still matches every captured consent field despite the recorded service withdrawal');
        $before=$fingerprint();
        denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($p['device'],$legacyReopen),409,
            'a legacy positive UI attestation cannot reopen a recorded withdrawn purpose when its complete before-tuple still matches');
        check($fingerprint()===$before&&(array)DB::table('crm_customers')->where('id',$serverCrmId)->first($reopenFields)===$serverBefore,
            'rejected legacy UI reopening preserves both purposes, the authoritative withdrawal and every business or receipt row');

        foreach(['local saved UI reply'=>[$p['local'],true,$localCrmId],
            'server saved receipt'=>[$p['server'],false,$serverCrmId]] as $label=>[$database,$local,$crmId]){
            $switch($database,$local,$p['id']);
            $outside=new PDO('mysql:host=127.0.0.1;port='.config('database.connections.mysql.port').';dbname='.$database.';charset=utf8mb4',
                'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $source=(array)DB::table('crm_customer_sources')->where('customer_id',$crmId)->where('branch','f:100')->first();
            check(isset($source['id']),$label.' starts with its original enrolled-branch source');
            DB::beginTransaction();
            try{
                check(DB::table('crm_customer_sources')->where('id',$source['id'])->exists(),
                    $label.' establishes a repeatable-read snapshot containing the source before revocation');
                $outside->prepare('DELETE FROM crm_customer_sources WHERE id=?')->execute([$source['id']]);
                check((int)$outside->query('SELECT COUNT(*) FROM crm_customer_sources WHERE id='.(int)$source['id'])->fetchColumn()===0
                    &&DB::table('crm_customer_sources')->where('id',$source['id'])->exists(),
                    $label.' retains a genuinely stale source snapshot after another InnoDB connection commits its removal');
                $before=$fingerprint();$callsBefore=$controllerCalls;
                if($local)denied(fn()=>$localWrite('customers.directory-save',$profileValues),409,
                    'a warmed local snapshot cannot authorize a saved CRM UI reply after its branch source was revoked');
                else denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($p['device'],$profileCommittedEnvelope),409,
                    'a warmed server snapshot cannot return a committed CRM receipt after its branch source was revoked');
                check($fingerprint()===$before&&$controllerCalls===$callsBefore,
                    $label.' rejects from current locking authority without executing the controller or changing business data');
            }finally{
                DB::rollBack();
                $columns=['id','customer_id','branch','channel','first_seen_at','last_seen_at','created_at','updated_at'];
                $outside->prepare('INSERT INTO crm_customer_sources (`'.implode('`,`',$columns).'`) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute(array_map(fn($column)=>$source[$column],$columns));
                $outside=null;
            }
            check(DB::table('crm_customer_sources')->where('id',$source['id'])->where('branch','f:100')->exists(),
                $label.' regression restores its original source after rolling back the warmed snapshot');
        }

        $switch($p['local'],true,$p['id']);$guard->setUser(User::withoutGlobalScopes()->findOrFail(1));$before=$fingerprint();$nextCalls=0;
        foreach(['customers.messaging-status','customers.messaging-configure','customers.feedback-template-prepare',
            'customers.marketing-template-prepare','customers.campaign-queue'] as $provider){
            $response=app(CustomerUiMiddleware::class)->handle($request($provider),function()use(&$nextCalls){$nextCalls++;throw new RuntimeException('A local provider route reached its controller.');});
            check($response->getStatusCode()===404&&$response->getContent()===''
                &&str_contains($response->headers->get('Cache-Control',''),'private')
                &&str_contains($response->headers->get('Cache-Control',''),'no-store')
                &&$response->headers->get('Pragma')==='no-cache'&&$response->headers->get('X-Content-Type-Options')==='nosniff',
                $provider.' returns a private empty local 404 before provider controller work');
        }
        check($nextCalls===0&&$fingerprint()===$before,'the five local CRM provider boundaries invoke no route callback and change no business or command data');
        $guard->setUser(User::withoutGlobalScopes()->findOrFail(10));
        denied(fn()=>app(CustomerUiMiddleware::class)->handle($request('customers.messaging-status'),
            fn()=>throw new RuntimeException('An unauthorized provider callback ran.')),403,
            'the local provider 404 boundary retains the original central-owner access check');
    }finally{
        config($savedConfig);DB::purge();\Carbon\Carbon::setTestNow($savedClock);
        if($savedActor)$guard->setUser($savedActor);else (function(){$this->user=null;})->call($guard);
    }
})();
require __DIR__.'/customer-directory-remote.php';
