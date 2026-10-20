<?php
// Native reservations use actual named CRM routes, original controllers and a private server database.
(function()use($pdo,$remoteDatabase,$application){
    $savedConfig=[
        'database.connections.mysql.database'=>config('database.connections.mysql.database'),
        'desktop_dashboard.local'=>config('desktop_dashboard.local'),
        'desktop_dashboard.device_id'=>config('desktop_dashboard.device_id'),
        'desktop_dashboard.enabled'=>config('desktop_dashboard.enabled'),
    ];
    $savedClock=\Carbon\Carbon::getTestNow();$guard=auth('admin');$savedActor=$guard->getUser();
    $server='desktop_customer_native_'.bin2hex(random_bytes(8));
    $pdo->exec('CREATE DATABASE `'.$server.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$server.'`'));
    foreach($pdo->query('SHOW TABLES FROM `'.$remoteDatabase.'`')->fetchAll(PDO::FETCH_COLUMN) as $table){
        $pdo->exec('CREATE TABLE `'.$server.'`.`'.$table.'` LIKE `'.$remoteDatabase.'`.`'.$table.'`');
        $pdo->exec('INSERT INTO `'.$server.'`.`'.$table.'` SELECT * FROM `'.$remoteDatabase.'`.`'.$table.'`');
    }
    $db=fn()=>\Illuminate\Support\Facades\DB::connection();
    $schema=fn()=>$db()->getSchemaBuilder();
    $uuid=fn()=>(string)\Illuminate\Support\Str::uuid();
    $attempts=fn()=>app(\App\Services\Dashboard\DesktopDashboardRemoteAttempts::class);
    $decrypt=fn(string $cipher)=>json_decode(\Illuminate\Support\Facades\Crypt::decryptString($cipher),true,512,JSON_THROW_ON_ERROR);
    $business=function()use($db): string{
        $state=[];
        foreach(['crm_customers','crm_customer_sources','crm_purchases','branch_customers','branch_operation_commands',
            'takeaway_orders','takeaway_order_items','takeaway_tills','takeaway_till_entries','branch_inventory',
            'branch_inventory_movements','branch_stock','branch_stock_movements','desktop_dashboard_commands'] as $table){
            $rows=$db()->table($table)->get()->map(fn($r)=>json_encode((array)$r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))->all();
            sort($rows,SORT_STRING);$state[$table]=$rows;
        }
        return app(\App\Services\Dashboard\DesktopDashboardJournal::class)->fingerprint($state);
    };
    $financial=function()use($db): string{
        $state=[];
        foreach(['takeaway_orders','takeaway_order_items','takeaway_tills','takeaway_till_entries','branch_inventory',
            'branch_inventory_movements','branch_stock','branch_stock_movements','crm_purchases'] as $table){
            $rows=$db()->table($table)->get()->map(fn($r)=>json_encode((array)$r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))->all();
            sort($rows,SORT_STRING);$state[$table]=$rows;
        }
        return app(\App\Services\Dashboard\DesktopDashboardJournal::class)->fingerprint($state);
    };
    $calls=0;
    $request=function(string $route,array $values,array $reservation): \Illuminate\Http\Request{
        $original=app('router')->getRoutes()->getByName($route);
        if(!$original)throw new RuntimeException('Missing native CRM route '.$route);
        $request=\Illuminate\Http\Request::create(url('/'.$original->uri()),'POST',$values);
        $bound=clone $original;$bound->bind($request);$request->setRouteResolver(fn()=>$bound);
        $request->headers->set('Accept','application/json');
        $request->headers->set('X-Fasakhansta-Remote-Attempt',$reservation['id']);
        $request->headers->set('X-Fasakhansta-Remote-Capability',$reservation['capability']);
        return $request;
    };
    $reserve=function(object $device,string $route)use($uuid,$attempts): array{
        $original=app('router')->getRoutes()->getByName($route);
        $values=['id'=>$uuid(),'method'=>'POST','path'=>'/'.$original->uri()];
        return ['values'=>$values,'proof'=>$attempts()->decide($device,$values,false)];
    };
    $handle=function(string $route,array $values,array $reservation)use($request,$attempts,&$calls): array{
        $response=$attempts()->handle($request($route,$values,$reservation['proof']),function($req)use($route,&$calls){
            $calls++;
            $controller=app(\App\Http\Controllers\Dashboard\BranchOperationsController::class);
            $service=app(\App\Services\Dashboard\BranchCustomers::class);
            return $route==='customers.directory-save'?$controller->directorySave($req,$service):$controller->customerConsent($req,$service);
        });
        return ['status'=>$response->getStatusCode(),'content'=>$response->getContent(),
            'type'=>$response->headers->get('Content-Type'),'location'=>$response->headers->get('Location')];
    };
    try{
        config(['database.connections.mysql.database'=>$server,'desktop_dashboard.local'=>false,'desktop_dashboard.enabled'=>true]);
        \Illuminate\Support\Facades\DB::purge();
        foreach(['products','product_features'] as $table)if(!$schema()->hasTable($table))
            $schema()->create($table,function(\Illuminate\Database\Schema\Blueprint $t){$t->engine='InnoDB';$t->id();});
        if(!$schema()->hasColumn('users','status'))$schema()->table('users',fn(\Illuminate\Database\Schema\Blueprint $t)=>$t->string('status')->nullable());
        if(!$schema()->hasTable('desktop_dashboard_remote_attempts')){
            if(!class_exists('CreateDesktopDashboardRemoteAttempts',false))require $application.'/database/migrations/2026_10_08_210000_create_desktop_dashboard_remote_attempts.php';
            (new \CreateDesktopDashboardRemoteAttempts)->up();
        }
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19T15:00:00Z'));
        $actor=\App\Models\User::withoutGlobalScopes()->findOrFail(1);$guard->setUser($actor);$ownerInitialStatus=$actor->status;
        $id=$uuid();config(['desktop_dashboard.device_id'=>$id]);
        $link=app(\App\Services\Dashboard\DesktopDashboardDevices::class)->enroll([
            'device_id'=>$id,'name'=>'Original native CRM fixture','nonce'=>bin2hex(random_bytes(32))],$actor);
        $device=app(\App\Services\Dashboard\DesktopDashboardDevices::class)->device($link['token']);
        $moneyBefore=$financial();
        $create=['branch'=>'f:100','idempotency_key'=>$uuid(),'channel'=>'delivery',
            'phone'=>'01092000001','name'=>'عميل متصل أصلي','address'=>'العنوان المتصل','area'=>'المنصورة'];
        $createAttempt=$reserve($device,'customers.directory-save');$created=$handle('customers.directory-save',$create,$createAttempt);
        $customer=json_decode($created['content'],true,512,JSON_THROW_ON_ERROR)['customer'];$customerId=(int)$customer['id'];
        check($created['status']===200&&$customer['phone']==='+201092000001'&&$calls===1,
            'a real native CRM reservation executes the original directory controller and canonical customer creation once');
        $createRow=$db()->table('desktop_dashboard_remote_attempts')->where('id',$createAttempt['values']['id'])->first();
        $createSaved=$decrypt($createRow->response_cipher);
        check($createRow->status==='committed'&&$createRow->operation_id===$create['idempotency_key']
            &&($createSaved['customer_directory_binding']??null)===['version'=>1,'route'=>'customers.directory-save',
                'branch'=>'f:100','operation'=>$create['idempotency_key'],'customer'=>['id'=>$customerId,'phone'=>'+201092000001']],
            'the native CRM result stores its exact enrolled branch and canonical customer identity in the encrypted terminal receipt');
        $consent=['branch'=>'f:100','idempotency_key'=>$uuid(),'directory_id'=>$customerId,
            'service_consent'=>true,'marketing_consent'=>false,'consent_scope'=>'service','consent_method'=>'verbal'];
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19T15:00:01Z'));
        $consentAttempt=$reserve($device,'customers.consent');$consented=$handle('customers.consent',$consent,$consentAttempt);
        $consentResult=json_decode($consented['content'],true,512,JSON_THROW_ON_ERROR);
        check($consented['status']===200&&$consentResult['customer_id']===$customerId&&$consentResult['consent']['service_consent']
            &&!$consentResult['consent']['marketing_consent']&&$consentResult['consent_scope']==='service'
            &&$consentResult['consent_method']==='verbal'&&$calls===2&&$financial()===$moneyBefore,
            'a real native consent reservation retains original verbal service-only evidence without cash, inventory or purchase effects');
        $cases=[['customers.directory-save',$create,$createAttempt,$created],['customers.consent',$consent,$consentAttempt,$consented]];
        foreach($cases as [$route,$values,$attempt,$result]){
            $before=$business();$beforeCalls=$calls;
            check($handle($route,$values,$attempt)===$result&&$business()===$before&&$calls===$beforeCalls,
                'an exact lost native '.$route.' reply returns its original JSON without controller or business replay');
            $settled=$attempts()->decide($device,$attempt['values'],true);
            check($settled['status']==='committed'&&$settled['capability']===null&&$business()===$before,
                'native '.$route.' recovery settles the committed original outcome without cancelling it');
            $alias=$reserve($device,$route);
            check($handle($route,$values,$alias)===$result&&$business()===$before&&$calls===$beforeCalls
                &&$db()->table('desktop_dashboard_remote_attempts')->where('id',$alias['values']['id'])->value('status')==='committed'
                &&$attempts()->decide($device,$alias['values'],true)['status']==='committed',
                'a new native transmission of the same '.$route.' operation aliases the encrypted outcome without another write');
            $changed=$values;$changed[$route==='customers.directory-save'?'name':'consent_scope']=$route==='customers.directory-save'?'اسم مختلف':'all';
            denied(fn()=>$handle($route,$changed,$attempt),409,'a committed native '.$route.' operation rejects changed payload reuse');
            check($business()===$before&&$calls===$beforeCalls,'rejected native '.$route.' UUID reuse retains every original business row');
        }

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19T15:01:00Z'));
        app(\App\Services\Dashboard\CustomerDirectory::class)->consent($customerId,false,false,'whatsapp-inbound:opt-out');
        $stop=(array)$db()->table('crm_customers')->where('id',$customerId)->first();
        foreach($cases as [$route,$values,$attempt,$result]){
            $before=$business();$beforeCalls=$calls;$alias=$reserve($device,$route);
            check($handle($route,$values,$attempt)===$result&&$attempts()->decide($device,$attempt['values'],true)['status']==='committed'
                &&$handle($route,$values,$alias)===$result&&$business()===$before&&$calls===$beforeCalls
                &&(array)$db()->table('crm_customers')->where('id',$customerId)->first()===$stop,
                'a later STOP permits saved native '.$route.' acknowledgments and aliases while preserving all newer opt-out evidence');
        }

        $sources=$db()->table('crm_customer_sources')->where('customer_id',$customerId)->where('branch','f:100')->get()->map(fn($r)=>(array)$r)->all();
        // A cached native outcome must consult current locking reads, even inside an older caller snapshot.
        $db()->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db()->beginTransaction();
        try{
            check($db()->table('crm_customer_sources')->where('customer_id',$customerId)->where('branch','f:100')->count()===count($sources),
                'the native CRM race fixture warms an outer repeatable-read snapshot with the original branch association');
            $externalDelete=$pdo->prepare('DELETE FROM `'.$server.'`.crm_customer_sources WHERE customer_id=? AND branch=?');
            $externalDelete->execute([$customerId,'f:100']);
            $beforeCalls=$calls;
            foreach($cases as [$route,$values,$attempt]){
                denied(fn()=>$attempts()->decide($device,$attempt['values'],true),409,
                    'native '.$route.' settlement rejects a concurrently removed branch source despite a warmed repeatable-read snapshot');
                denied(fn()=>$handle($route,$values,$attempt),409,
                    'the saved native '.$route.' browser reply rejects a concurrently removed source through a current locking read');
            }
            check($calls===$beforeCalls,'concurrent native CRM source revocation invokes no original controller through either saved-reply path');
        }finally{
            $db()->rollBack();
            $db()->table('crm_customer_sources')->insert($sources);
        }
        $db()->table('crm_customer_sources')->where('customer_id',$customerId)->where('branch','f:100')->delete();
        foreach($cases as [$route,$values,$attempt]){
            $alias=$reserve($device,$route);$before=$business();$beforeCalls=$calls;
            denied(fn()=>$handle($route,$values,$attempt),409,'removed branch linkage blocks the saved native '.$route.' browser reply');
            denied(fn()=>$attempts()->decide($device,$attempt['values'],true),409,'removed branch linkage blocks native '.$route.' recovery settlement');
            denied(fn()=>$handle($route,$values,$alias),409,'removed branch linkage blocks a same-operation native '.$route.' transmission alias');
            check($business()===$before&&$calls===$beforeCalls
                &&$db()->table('desktop_dashboard_remote_attempts')->where('id',$alias['values']['id'])->value('status')==='ready',
                'branch-link rejection returns no cached '.$route.' success and commits no alias or original write');
        }
        $db()->table('crm_customer_sources')->insert($sources);
        $db()->table('crm_customers')->where('id',$customerId)->update(['phone'=>'+201092000099','phone_key'=>'201092000099']);
        foreach($cases as [$route,$values,$attempt]){
            $before=$business();$beforeCalls=$calls;
            denied(fn()=>$handle($route,$values,$attempt),409,'changed canonical identity blocks the saved native '.$route.' browser reply');
            denied(fn()=>$attempts()->decide($device,$attempt['values'],true),409,'changed canonical identity blocks native '.$route.' recovery settlement');
            check($business()===$before&&$calls===$beforeCalls,'identity drift cannot disclose cached '.$route.' success or update a different customer');
        }
        $db()->table('crm_customers')->where('id',$customerId)->update(['phone'=>'+201092000001','phone_key'=>$stop['phone_key']]);

        foreach([
            ['enrollment',403,fn()=>$db()->table('desktop_dashboard_devices')->where('id',$id)->update(['branches'=>json_encode(['f:101'])]),
                fn()=>$db()->table('desktop_dashboard_devices')->where('id',$id)->update(['branches'=>json_encode($link['branches'])])],
            ['disabled actor',403,fn()=>$db()->table('users')->where('id',1)->update(['status'=>'disabled']),
                fn()=>$db()->table('users')->where('id',1)->update(['status'=>$ownerInitialStatus])],
            ['revoked account',403,fn()=>$db()->table('users')->where('id',1)->update(['account_type'=>'user']),
                fn()=>$db()->table('users')->where('id',1)->update(['account_type'=>'admin'])],
            ['revoked device',401,fn()=>$db()->table('desktop_dashboard_devices')->where('id',$id)->update(['enabled'=>false]),
                fn()=>$db()->table('desktop_dashboard_devices')->where('id',$id)->update(['enabled'=>true])],
        ] as [$reason,$status,$revoke,$restore]){
            // Reserve before revocation, then deliberately reuse stale device and authenticated actor objects.
            $aliases=[];foreach($cases as [$route])$aliases[$route]=$reserve($device,$route);
            $revoke();
            try{foreach($cases as [$route,$values,$attempt]){
                $before=$business();$beforeCalls=$calls;
                denied(fn()=>$handle($route,$values,$attempt),$status,'current '.$reason.' blocks a saved native '.$route.' reply despite stale objects');
                denied(fn()=>$attempts()->decide($device,$attempt['values'],true),$status,'current '.$reason.' blocks native '.$route.' recovery settlement');
                denied(fn()=>$handle($route,$values,$aliases[$route]),$status,'current '.$reason.' blocks native '.$route.' operation aliasing');
                check($business()===$before&&$calls===$beforeCalls
                    &&$db()->table('desktop_dashboard_remote_attempts')->where('id',$aliases[$route]['values']['id'])->value('status')==='ready',
                    'current '.$reason.' rejection changes no '.$route.' business rows and stores no transmission alias');
            }}finally{$restore();}
        }

        foreach($cases as [$route,$values,$attempt]){
            $row=$db()->table('desktop_dashboard_remote_attempts')->where('id',$attempt['values']['id'])->first();
            $saved=$decrypt($row->response_cipher);unset($saved['customer_directory_binding']);
            $db()->table('desktop_dashboard_remote_attempts')->where('id',$row->id)->update([
                'response_cipher'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode($saved,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))]);
            try{
                $alias=$reserve($device,$route);$before=$business();$beforeCalls=$calls;
                denied(fn()=>$handle($route,$values,$attempt),503,'missing identity binding fails closed before a saved native '.$route.' response');
                denied(fn()=>$attempts()->decide($device,$attempt['values'],true),503,'missing identity binding fails closed before native '.$route.' recovery settlement');
                denied(fn()=>$handle($route,$values,$alias),503,'missing identity binding fails closed before native '.$route.' transmission aliasing');
                check($business()===$before&&$calls===$beforeCalls
                    &&$db()->table('desktop_dashboard_remote_attempts')->where('id',$alias['values']['id'])->value('status')==='ready',
                    'a legacy unbound native '.$route.' receipt cannot produce success or alter business data');
            }finally{$db()->table('desktop_dashboard_remote_attempts')->where('id',$row->id)->update(['response_cipher'=>$row->response_cipher]);}
        }

        $unassigned=app(\App\Services\Dashboard\CustomerDirectory::class)->capture(['phone'=>'01092000002','name'=>'ملف مركزي غير مرتبط'],'delivery','');
        $db()->table('crm_customer_sources')->where('customer_id',$unassigned->id)->delete();
        $unassignedProfile=$create;$unassignedProfile['idempotency_key']=$uuid();$unassignedProfile['directory_id']=(int)$unassigned->id;
        $unassignedProfile['phone']=$unassigned->phone;$unassignedProfile['name']='تعديل مركزي غير مدعوم';
        $unassignedConsent=$consent;$unassignedConsent['idempotency_key']=$uuid();$unassignedConsent['directory_id']=(int)$unassigned->id;
        foreach([['customers.directory-save',$unassignedProfile],['customers.consent',$unassignedConsent]] as [$route,$values]){
            $attempt=$reserve($device,$route);$before=$business();$reservationCipher=$db()->table('desktop_dashboard_remote_attempts')->where('id',$attempt['values']['id'])->value('response_cipher');
            denied(fn()=>$handle($route,$values,$attempt),409,'an unassigned central CRM identity cannot commit a native branch-scoped '.$route.' receipt');
            $row=$db()->table('desktop_dashboard_remote_attempts')->where('id',$attempt['values']['id'])->first();
            check($business()===$before&&$row->status==='ready'&&$row->operation_id===null&&$row->response_cipher===$reservationCipher,
                'unsupported central '.$route.' scope rolls back original profile or consent effects and leaves recovery ready');
        }

        $rollbackProfile=$create;$rollbackProfile['idempotency_key']=$uuid();$rollbackProfile['phone']='01092000003';
        $rollbackConsent=$consent;$rollbackConsent['idempotency_key']=$uuid();
        foreach([['customers.directory-save',$rollbackProfile],['customers.consent',$rollbackConsent]] as [$route,$values]){
            if($route==='customers.consent')$values['directory_id']=(int)$db()->table('crm_customers')->where('phone','+201092000003')->value('id');
            $attempt=$reserve($device,$route);$before=$business();$reservationCipher=$db()->table('desktop_dashboard_remote_attempts')->where('id',$attempt['values']['id'])->value('response_cipher');
            $db()->unprepared("CREATE TRIGGER fixture_customer_native_outcome BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.operation_id='".$values['idempotency_key']."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture CRM native outcome rollback'; END IF; END");
            $failed=false;
            try{$handle($route,$values,$attempt);}catch(\Illuminate\Database\QueryException $error){
                $failed=str_contains($error->getMessage(),'fixture CRM native outcome rollback');
            }finally{$db()->unprepared('DROP TRIGGER fixture_customer_native_outcome');}
            $row=$db()->table('desktop_dashboard_remote_attempts')->where('id',$attempt['values']['id'])->first();
            check($failed&&$business()===$before&&$row->status==='ready'&&$row->operation_id===null&&$row->response_cipher===$reservationCipher,
                'failure to persist a native '.$route.' terminal receipt rolls back its original customer, source, evidence and branch receipt');
            $result=$handle($route,$values,$attempt);$after=$business();$beforeCalls=$calls;
            check($result['status']===200&&$handle($route,$values,$attempt)===$result&&$business()===$after&&$calls===$beforeCalls,
                'a rolled-back native '.$route.' reservation retries the original operation once and then returns its durable outcome');
        }

        // Real Spatie grants and old RR snapshots must not restore current CRM receipt authority.
        foreach(['roles','permissions'] as $table)if(!$schema()->hasTable($table))
            $schema()->create($table,function(\Illuminate\Database\Schema\Blueprint $t){
                $t->engine='InnoDB';$t->id();$t->string('name');$t->string('guard_name');$t->timestamps();$t->unique(['name','guard_name']);
            });
        foreach(['model_has_roles'=>'role_id','model_has_permissions'=>'permission_id'] as $table=>$column)if(!$schema()->hasTable($table))
            $schema()->create($table,function(\Illuminate\Database\Schema\Blueprint $t)use($column){
                $t->engine='InnoDB';$t->unsignedBigInteger($column);$t->string('model_type');$t->unsignedBigInteger('model_id');
                $t->primary([$column,'model_id','model_type']);
            });
        if(!$schema()->hasTable('role_has_permissions'))$schema()->create('role_has_permissions',function(\Illuminate\Database\Schema\Blueprint $t){
            $t->engine='InnoDB';$t->unsignedBigInteger('permission_id');$t->unsignedBigInteger('role_id');$t->primary(['permission_id','role_id']);
        });
        $permissionId=$db()->table('permissions')->where('name','order-list')->where('guard_name','admin')->value('id')
            ??$db()->table('permissions')->insertGetId(['name'=>'order-list','guard_name'=>'admin']);
        $roleId=$db()->table('roles')->insertGetId(['name'=>'CRM current order-list fixture','guard_name'=>'admin']);
        $registrar=app(\Spatie\Permission\PermissionRegistrar::class);
        $adapter=app(\App\Services\Dashboard\DesktopDashboardCustomerDirectory::class);
        $binding=$createSaved['customer_directory_binding'];
        foreach(['role'=>901,'direct'=>902] as $kind=>$limitedId){
            $db()->table('users')->insert(['id'=>$limitedId,'name'=>'أدمن بصلاحية محددة','account_type'=>'admin']);
            $limited=\App\Models\User::withoutGlobalScopes()->findOrFail($limitedId);$morph=$limited->getMorphClass();
            if($kind==='role'){
                $grant=['permission_id'=>(int)$permissionId,'role_id'=>(int)$roleId];$grantTable='role_has_permissions';
                $db()->table('model_has_roles')->insert(['role_id'=>$roleId,'model_id'=>$limitedId,'model_type'=>$morph]);
            }else{
                $grant=['permission_id'=>(int)$permissionId,'model_id'=>$limitedId,'model_type'=>$morph];$grantTable='model_has_permissions';
            }
            $db()->table($grantTable)->insert($grant);$registrar->forgetCachedPermissions();
            check($limited->can('order-list')&&app(\App\Services\Dashboard\TakeawayAccess::class)->actor($limited)->id===$limitedId,
                'the native CRM authority fixture grants real original order-list access through a '.$kind.' permission');
            $limitedLink=app(\App\Services\Dashboard\DesktopDashboardDevices::class)->enroll([
                'device_id'=>$uuid(),'name'=>'Current '.$kind.' CRM grant fixture','nonce'=>bin2hex(random_bytes(32))],$limited);
            $limitedDevice=app(\App\Services\Dashboard\DesktopDashboardDevices::class)->device($limitedLink['token']);
            foreach(['customers.directory-save','customers.consent'] as $route){
                $currentBinding=$binding;$currentBinding['route']=$route;
                $adapter->authorizeRemoteBinding($route,$currentBinding,$limited,$limitedDevice);
            }
            check(true,'both native CRM route bindings accept the original current '.$kind.' order-list grant');
            $db()->beginTransaction();
            try{
                check($db()->table($grantTable)->where($grant)->exists()&&$limited->can('order-list'),
                    'the '.$kind.' CRM authority race warms both a repeatable-read grant snapshot and real Spatie authorization');
                $conditions=implode(' AND ',array_map(fn($column)=>'`'.$column.'`=?',array_keys($grant)));
                $externalRevoke=$pdo->prepare('DELETE FROM `'.$server.'`.`'.$grantTable.'` WHERE '.$conditions);
                $externalRevoke->execute(array_values($grant));
                check($db()->table($grantTable)->where($grant)->exists()
                    &&app(\App\Services\Dashboard\TakeawayAccess::class)->actor($limited)->id===$limitedId&&$limited->can('order-list'),
                    'ordinary original '.$kind.' grant reads still see the old authority after independent revocation');
                foreach(['customers.directory-save','customers.consent'] as $route){
                    $currentBinding=$binding;$currentBinding['route']=$route;
                    denied(fn()=>$adapter->authorizeRemoteBinding($route,$currentBinding,$limited,$limitedDevice),403,
                        'current locking '.$kind.' permission checks reject '.$route.' receipt authority despite old RR and Spatie grants');
                }
            }finally{
                $db()->rollBack();$db()->table($grantTable)->insert($grant);$registrar->forgetCachedPermissions();
            }
        }
        $db()->beginTransaction();
        try{
            check($db()->table('users')->where('id',1)->value('status')===$ownerInitialStatus,
                'the native CRM owner-status race warms an enabled persisted actor in a repeatable-read snapshot');
            $externalDisable=$pdo->prepare('UPDATE `'.$server.'`.users SET status=? WHERE id=?');$externalDisable->execute(['disabled',1]);
            check($db()->table('users')->where('id',1)->value('status')===$ownerInitialStatus,
                'ordinary owner reads still see the older enabled status after independent account disablement');
            foreach(['customers.directory-save','customers.consent'] as $route){
                $currentBinding=$binding;$currentBinding['route']=$route;
                denied(fn()=>$adapter->authorizeRemoteBinding($route,$currentBinding,$actor,$device),403,
                    'a current locking owner-status read rejects '.$route.' receipt authority despite an older actor snapshot');
            }
        }finally{
            $db()->rollBack();$db()->table('users')->where('id',1)->update(['status'=>$ownerInitialStatus]);
        }
        check($financial()===$moneyBefore,'all native CRM receipt, denial, STOP and rollback cases leave money, inventory and purchase records unchanged');
    }finally{
        config($savedConfig);\Illuminate\Support\Facades\DB::purge();\Carbon\Carbon::setTestNow($savedClock);
        if($savedActor)$guard->setUser($savedActor);else (function(){$this->user=null;})->call($guard);
    }
})();
