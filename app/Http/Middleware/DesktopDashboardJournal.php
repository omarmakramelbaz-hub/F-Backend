<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\{DB,Schema};
use App\Services\Dashboard\DesktopDashboardJournal as Journal;
use App\Services\Dashboard\DesktopDashboardRoutes;
use App\Services\Dashboard\MetaInboxAccess;

class DesktopDashboardJournal
{
    public const CONNECTED_REQUEST_PROTOCOL=1;
    /** Authenticate this original request's session without changing its controller or transaction. */
    private function connectedActor($request): void
    {
        $id=$request->header('X-Fasakhansta-Connected-Device');$token=$request->header('X-Fasakhansta-Connected-Token');
        abort_unless(is_string($id)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$id)
            &&is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token),401,'ربط الداشبورد المتصلة غير مكتمل.');
        $devices=app(\App\Services\Dashboard\DesktopDashboardDevices::class);$devices->ready();
        // Only this short authorization check takes locks. Original online forms,
        // credentials and provider actions run through their unchanged pipeline.
        DB::transaction(function()use($request,$id,$token,$devices){
            $device=DB::table('desktop_dashboard_devices')->where('id',$id)->where('token_hash',hash('sha256',$token))->lockForUpdate()->first();
            abort_unless($device&&$device->enabled,401,'ربط الداشبورد المتصلة متوقف أو تغيّر.');
            $devices->enrollmentFingerprint($device);
            // Sign-in must restore an expired session; no other route can bypass
            // comparison with the actual user loaded after StartSession.
            if($request->isMethod('POST')&&$request->route()?->getName()==='admin.login'
                &&$request->route()?->getActionName()===\App\Http\Controllers\Dashboard\HomeController::class.'@signin')return;
            $fresh=DB::table('users')->where('id',$device->actor_id)->lockForUpdate()->first(['id','account_type','status']);
            $actor=auth('admin')->user();
            abort_unless($actor&&(int)$actor->id===(int)$device->actor_id&&$fresh
                &&in_array($fresh->account_type,['admin','vendor','resturant_owner','delegate'],true)
                &&!in_array($fresh->status??null,['disabled','declined'],true),403,
                'حساب هذا الطلب لا يطابق حساب الجهاز؛ سجّل الدخول بالحساب المرتبط.');
        });
    }
    public function handle($request,Closure $next)
    {
        if (!config('desktop_dashboard.local')) {
            $workspace=false;foreach(['Device','Token','Actor','Revision','Grant','Root','Attempt','Capability'] as $part)
                if($request->hasHeader('X-Fasakhansta-Workspace-'.$part))$workspace=true;
            if($workspace){
                $scope=\App\Services\Dashboard\DesktopDashboardWorkspaceExecutionContext::current();
                $signin=$request->attributes->get(\App\Http\Middleware\DesktopDashboardWorkspaceConnectedContext::SIGNIN_ATTRIBUTE);
                if(\App\Http\Middleware\DesktopDashboardWorkspaceConnectedContext::signin($request)
                    &&$signin instanceof \App\Services\Dashboard\DesktopDashboardWorkspaceScope){$signin->assertIssued();return $next($request);}
                abort_unless($scope&&!$scope->isLocal()&&(int)auth('admin')->id()===$scope->actor_id,403);
                if($request->hasHeader('X-Fasakhansta-Workspace-Attempt')||$request->hasHeader('X-Fasakhansta-Workspace-Capability'))
                    return app(\App\Services\Dashboard\DesktopDashboardWorkspaceRemoteAttempts::class)->handle($request,$next);
                return $next($request);
            }
            if($request->hasHeader('X-Fasakhansta-Connected-Device')||$request->hasHeader('X-Fasakhansta-Connected-Token')){
                abort_if($request->hasHeader('X-Fasakhansta-Remote-Attempt')||$request->hasHeader('X-Fasakhansta-Remote-Capability'),422);
                $this->connectedActor($request);
            }
            if($request->hasHeader('X-Fasakhansta-Remote-Attempt')||$request->hasHeader('X-Fasakhansta-Remote-Capability'))
                return app(\App\Services\Dashboard\DesktopDashboardRemoteAttempts::class)->handle($request,$next);
            return $next($request);
        }
        $route=$request->route()?->getName();
        if(in_array($route,['takeaway.print','dining.print','dining.kitchen','phone-orders.print','phone-orders.kitchen','branch-shifts.print','branch-shift-print.receipt','phone-orders.batch-print'],true)
            &&app(\App\Services\Dashboard\DesktopDashboardPrintLedger::class)->active((string)config('desktop_dashboard.device_id')))
            abort(409,'الطباعة المحلية متاحة من جسر البرنامج لضمان تسجيل نسخة واحدة.');
        if(in_array($route,['customers.messaging-status','customers.messaging-configure','customers.feedback-template-prepare',
            'customers.marketing-template-prepare','customers.campaign-queue'],true)){
            app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->actor(auth('admin')->user());
            return response('',404)->withHeaders(['Cache-Control'=>'private, no-store, max-age=0',
                'Pragma'=>'no-cache','X-Content-Type-Options'=>'nosniff']);
        }
        $metaAdmin=$request->is('admin/meta','admin/meta/*');
        if ($metaAdmin||$request->is('api/meta/messaging/*')) {
            if($metaAdmin)app(MetaInboxAccess::class)->actor(auth('admin')->user());
            // Meta capture and media stay on the server; the scoped desktop database
            // has no Meta tables or media capability files.
            return response('',404)->withHeaders(['Cache-Control'=>'private, no-store, max-age=0',
                'Pragma'=>'no-cache','X-Content-Type-Options'=>'nosniff']);
        }
        if (!DesktopDashboardRoutes::journaled($route)) {
            $action=strtolower((string)$request->route()?->getActionName());
            $login=$route==='admin.login';
            $logout=$request->isMethod('GET')&&$request->route()?->getActionName()
                ===\App\Http\Controllers\Dashboard\HomeController::class.'@adminLogout';
            $unsafeRead=preg_match('/@(?:[^@]*(?:delete|destroy|update|save|send|accept|finish|clear|notificationtest)|testnotification)$/D',$action)
                || in_array($request->path(),['clear-cache','clear-compiled','linkstoragse','send_order_email','admin/clear-cache-admin','admin/test-notification'],true);
            $readPost=in_array($route,DesktopDashboardRoutes::READ_POSTS,true)||($request->isMethod('POST')&&in_array($request->route()?->getActionName(),DesktopDashboardRoutes::READ_POST_ACTIONS,true));
            abort_if((!$request->isMethod('GET')&&!$request->isMethod('HEAD')&&!$login&&!$readPost)||$unsafeRead,501,'مزامنة هذا القسم لم تُجهّز بعد؛ لم تُنفّذ العملية.');
            if($login||$logout)return $next($request);
            // Some legacy GET actions write data. HTTP reads must never silently commit
            // a business change without a corresponding reconciled command.
            abort_unless(DB::transactionLevel()===0,503);
            DB::statement('SET TRANSACTION READ ONLY');
            try{return DB::transaction(fn()=>\App\Services\Dashboard\DesktopDashboardWorkspaceExecutionContext::readOnly(fn()=>$next($request)));}
            catch(\Illuminate\Database\QueryException $error){
                if((int)($error->errorInfo[1]??0)===1792)abort(501,'هذه الصفحة طلبت تعديلًا لم تُجهّز مزامنته؛ لم تُنفّذ العملية.');
                throw $error;
            }
        }
        abort_unless(Schema::hasTable('desktop_dashboard_commands'),503,'قاعدة العمليات المحلية لم تُجهّز بعد.');
        $actor=auth('admin')->user();abort_unless($actor,403);
        if(\App\Services\Dashboard\DesktopDashboardOrderBoard::handles($route)){
            return DB::transaction(function()use($request,$next,$actor,$route){
                $device=(string)config('desktop_dashboard.device_id');
                app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->writable($device,(int)$actor->id);
                $adapter=app(\App\Services\Dashboard\DesktopDashboardOrderBoard::class);$command=$adapter->command($request);
                $scope=app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->lock($device);abort_unless($scope,403);
                $payload=$adapter->payload($request,$command,$actor,$scope);$actor=$adapter->authorize($payload,$actor,$scope);
                foreach(['_desktop_command','idempotency_key'] as $field)$request->request->remove($field);
                $response=null;$guard=auth('admin');$previous=$guard->getUser();
                try{
                    $guard->setUser($actor);
                    $result=app(Journal::class)->execute($device,$command,(int)$actor->id,$route,$payload,[],
                        fn()=>$adapter->capture($payload,$actor,function()use($request,$next,&$response){return $response=$next($request);}));
                    return $response??$adapter->response($result);
                }finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
            });
        }
        if(\App\Services\Dashboard\DesktopDashboardSettings::handles($route)){
            $adapter=app(\App\Services\Dashboard\DesktopDashboardSettings::class);
            return $adapter->transaction(function()use($adapter,$request,$next,$actor,$route){
                $device=(string)config('desktop_dashboard.device_id');app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->writable($device,(int)$actor->id);
                $scope=app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->lock($device);abort_unless($scope,403);
                $command=$adapter->command($request);$payload=$adapter->payload($route,$request,$command,$actor,$scope);$actor=$adapter->authorize($route,$payload,$actor,$scope);
                $request->request->remove('_desktop_command');$request->query->remove('_desktop_command');$response=null;$guard=auth('admin');$previous=$guard->getUser();
                try{$guard->setUser($actor);
                    $result=app(Journal::class)->execute($device,$command,(int)$actor->id,$route,$payload,[],
                        fn()=>$adapter->capture($route,$payload,$request,function()use($request,$next,&$response){return $response=$next($request);}));
                    app(\App\Services\Dashboard\DesktopDashboardSettingsImages::class)->verifyResult($route,$result,$payload['files'],$command);
                    return $response??$adapter->response($result);
                }finally{if($previous)$guard->setUser($previous);else(function(){$this->user=null;})->call($guard);}
            });
        }
        if(\App\Services\Dashboard\DesktopDashboardWalletCash::handles($route)){
            return DB::transaction(function()use($request,$next,$actor,$route){
                $device=(string)config('desktop_dashboard.device_id');
                app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->writable($device,(int)$actor->id);
                $adapter=app(\App\Services\Dashboard\DesktopDashboardWalletCash::class);$command=$adapter->command($request);
                $scope=app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->lock($device);abort_unless($scope,403);
                $payload=$adapter->payload($route,$request,$command,$actor,$scope);$actor=$adapter->authorize($route,$payload,$actor,$scope);
                $response=null;$guard=auth('admin');$previous=$guard->getUser();
                try{
                    $guard->setUser($actor);
                    $result=app(Journal::class)->execute($device,$command,(int)$actor->id,$route,$payload,[],
                        fn()=>$adapter->capture($route,$request,function()use($request,$next,&$response){return $response=$next($request);}));
                    return $response??$adapter->response($result);
                }finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
            });
        }
        if(\App\Services\Dashboard\DesktopDashboardUserProfiles::handles($route)){
            return DB::transaction(function()use($request,$next,$actor){
                $device=(string)config('desktop_dashboard.device_id');app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->writable($device,(int)$actor->id);
                $scope=app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->lock($device);abort_unless($scope,403);$adapter=app(\App\Services\Dashboard\DesktopDashboardUserProfiles::class);$command=$adapter->command($request);
                $payload=$adapter->payload($request,$command,$actor,$scope);$actor=$adapter->authorize($payload,$actor,$scope);$request->request->remove('_desktop_command');$request->query->remove('_desktop_command');$response=null;$guard=auth('admin');$previous=$guard->getUser();
                try{$guard->setUser($actor);$result=app(Journal::class)->execute($device,$command,(int)$actor->id,'users.update',$payload,[],fn()=>$adapter->capture($payload,function()use($request,$next,&$response){return $response=$next($request);}));return $response??$adapter->response($result);}
                finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
            });
        }
        if(\App\Services\Dashboard\DesktopDashboardUserAccounts::handles($route)){
            return DB::transaction(function()use($request,$next,$actor,$route){
                $device=(string)config('desktop_dashboard.device_id');
                app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->writable($device,(int)$actor->id);
                $adapter=app(\App\Services\Dashboard\DesktopDashboardUserAccounts::class);$command=$adapter->command($request);
                $scope=app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->lock($device);abort_unless($scope,403);
                $payload=$adapter->payload($route,$request,$command,$actor,$scope);$actor=$adapter->authorize($route,$payload,$actor,$scope);
                $request->request->remove('_desktop_command');$request->query->remove('_desktop_command');$response=null;$guard=auth('admin');$previous=$guard->getUser();
                try{
                    $guard->setUser($actor);
                    $result=app(Journal::class)->execute($device,$command,(int)$actor->id,$route,$payload,[],
                        function()use($adapter,$route,$payload,$request,$next,&$response){return $adapter->capture($route,$payload,function()use($request,$next,&$response){return $response=$next($request);});});
                    return $response??$adapter->response($result);
                }finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
            });
        }
        if(\App\Services\Dashboard\DesktopDashboardCustomerDirectory::handles($route)){
            return DB::transaction(function()use($request,$next,$actor,$route){
                $device=(string)config('desktop_dashboard.device_id');
                app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->writable($device,(int)$actor->id);
                $scope=app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->lock($device);abort_unless($scope,403);
                $adapter=app(\App\Services\Dashboard\DesktopDashboardCustomerDirectory::class);
                $payload=$adapter->payload($route,$request,$actor,$scope);
                $actor=$adapter->authorize($route,$payload,$actor,$scope);
                $guard=auth('admin');$previous=$guard->getUser();
                try{
                    $guard->setUser($actor);
                    $result=app(Journal::class)->execute($device,$payload['values']['idempotency_key'],(int)$actor->id,$route,$payload,[],
                        fn()=>$adapter->capture($route,$payload,function()use($request,$next){
                            $response=$next($request);
                            if($response->getStatusCode()>=400)throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
                            $result=json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
                            if(!is_array($result))throw new \LogicException('Expected the original CRM JSON response.');
                            return $result;
                        }));
                    return response()->json($result)->header('Cache-Control','private, no-store');
                }finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
            });
        }
        if(\App\Services\Dashboard\DesktopDashboardRestaurantProductCrud::handles($route)||\App\Services\Dashboard\DesktopDashboardRestaurantMenuCopy::handles($route)){
            return app(\App\Services\Dashboard\DesktopDashboardProductImages::class)->guard(fn()=>app(\App\Services\Dashboard\DesktopDashboardProductEffects::class)->guard(fn()=>DB::transaction(function()use($request,$next,$actor,$route){
                $device=(string)config('desktop_dashboard.device_id');
                app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->writable($device,(int)$actor->id);
                $adapter=app(\App\Services\Dashboard\DesktopDashboardRestaurantMenuCopy::handles($route)?\App\Services\Dashboard\DesktopDashboardRestaurantMenuCopy::class:\App\Services\Dashboard\DesktopDashboardRestaurantProductCrud::class);
                $command=$adapter->command($request);$payload=$adapter->payload($route,$request,$command,$actor);
                $scope=app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->lock($device);abort_unless($scope,403);
                $actor=$adapter->authorize($route,$payload,$actor,$scope);
                // Effective routing is established; metadata must not reach the original unguarded model.
                foreach(['_desktop_command','_method'] as $field){$request->request->remove($field);$request->query->remove($field);}
                $response=null;
                $result=app(Journal::class)->execute($device,$command,(int)$actor->id,$route,$payload,[],function()use($adapter,$route,$payload,$actor,$request,$next,&$response){
                    $guard=auth('admin');$previous=$guard->getUser();
                    try{$guard->setUser($actor);return $adapter->capture($route,$payload,function()use($request,$next,&$response){return $response=$next($request);});}
                    finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
                });
                return $response??$adapter->response($result);
            })));
        }
        if(\App\Services\Dashboard\DesktopDashboardRestaurantManagement::handles($route)){
            return app(\App\Services\Dashboard\DesktopDashboardRestaurantImages::class)->transaction(fn()=>app(\App\Services\Dashboard\DesktopDashboardRestaurantEffects::class)->guard(function()use($request,$next,$actor,$route){
                $device=(string)config('desktop_dashboard.device_id');app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->writable($device,(int)$actor->id);
                $adapter=app(\App\Services\Dashboard\DesktopDashboardRestaurantManagement::class);$command=$adapter->command($request);$payload=$adapter->payload($route,$request,$command,$actor);
                $scope=app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->lock($device);abort_unless($scope,403);$actor=$adapter->authorize($route,$payload,$actor,$scope);
                $request->request->remove('_desktop_command');$response=null;
                $result=app(Journal::class)->execute($device,$command,(int)$actor->id,$route,$payload,[],function()use($adapter,$route,$payload,$actor,$request,$next,&$response){
                    $guard=auth('admin');$previous=$guard->getUser();try{$guard->setUser($actor);return $adapter->capture($route,$payload,$request,function()use($request,$next,&$response){return $response=$next($request);});}
                    finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
                });return $response??$adapter->response($result);
            }));
        }
        if(\App\Services\Dashboard\DesktopDashboardGoStoreCreation::handles($route)){
            return DB::transaction(function()use($request,$next,$actor,$route){
                $device=(string)config('desktop_dashboard.device_id');app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->writable($device,(int)$actor->id);
                $adapter=app(\App\Services\Dashboard\DesktopDashboardGoStoreCreation::class);$command=$adapter->command($request);$payload=$adapter->payload($route,$request,$command,$actor);
                $scope=app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->lock($device);abort_unless($scope,403);$actor=$adapter->authorize($route,$payload,$actor,$scope);
                $request->request->remove('_desktop_command');$response=null;
                $result=app(Journal::class)->execute($device,$command,(int)$actor->id,$route,$payload,[],function()use($adapter,$route,$payload,$actor,$request,$next,&$response){
                    $guard=auth('admin');$previous=$guard->getUser();try{$guard->setUser($actor);return $adapter->capture($route,$payload,$request,function()use($request,$next,&$response){return $response=$next($request);});}
                    finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
                });return $response??$adapter->response($result);
            });
        }
        if(\App\Services\Dashboard\DesktopDashboardGoProducts::handles($route)){
            return app(\App\Services\Dashboard\DesktopDashboardGoProductImages::class)->transaction(function()use($request,$next,$actor,$route){
                $device=(string)config('desktop_dashboard.device_id');app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->writable($device,(int)$actor->id);
                $adapter=app(\App\Services\Dashboard\DesktopDashboardGoProducts::class);$command=$adapter->command($request);$payload=$adapter->payload($route,$request,$command,$actor);
                $scope=app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->lock($device);abort_unless($scope,403);$actor=$adapter->authorize($route,$payload,$actor,$scope);
                $request->request->remove('_desktop_command');$response=null;
                $result=app(Journal::class)->execute($device,$command,(int)$actor->id,$route,$payload,[],function()use($adapter,$route,$payload,$actor,$request,$next,&$response){
                    $guard=auth('admin');$previous=$guard->getUser();try{$guard->setUser($actor);return $adapter->capture($route,$payload,$request,function()use($request,$next,&$response){return $response=$next($request);});}
                    finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
                });return $response??$adapter->response($result);
            });
        }
        if(\App\Services\Dashboard\DesktopDashboardRestaurantControl::handles($route)||\App\Services\Dashboard\DesktopDashboardRestaurantFlags::handles($route)||\App\Services\Dashboard\DesktopDashboardRestaurantProducts::handles($route)){
            return DB::transaction(function()use($request,$next,$actor,$route){
                app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->writable((string)config('desktop_dashboard.device_id'),(int)$actor->id);
                $adapter=app(match(true){\App\Services\Dashboard\DesktopDashboardRestaurantControl::handles($route)=>\App\Services\Dashboard\DesktopDashboardRestaurantControl::class,\App\Services\Dashboard\DesktopDashboardRestaurantFlags::handles($route)=>\App\Services\Dashboard\DesktopDashboardRestaurantFlags::class,default=>\App\Services\Dashboard\DesktopDashboardRestaurantProducts::class});
                $command=$adapter->command($request);$payload=$adapter->payload($route,$request,$command,$actor);
                $scope=app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->lock((string)config('desktop_dashboard.device_id'));abort_unless($scope,403);
                $actor=$adapter->authorize($route,$payload,$actor,$scope);
                $request->request->remove('_desktop_command');$request->query->remove('_desktop_command');$response=null;
                $result=app(Journal::class)->execute((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$route,$payload,[],
                    function()use($adapter,$route,$payload,$actor,$request,$next,&$response){
                        $guard=auth('admin');$previous=$guard->getUser();
                        try{$guard->setUser($actor);return $adapter->capture($route,$payload,function()use($request,$next,&$response){return $response=$next($request);});}
                        finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
                    });
                return $response??$adapter->response($result);
            });
        }
        if(\App\Services\Dashboard\DesktopDashboardReviewDeletion::handles($route)){
            return DB::transaction(function()use($request,$next,$actor,$route){
                app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->writable((string)config('desktop_dashboard.device_id'),(int)$actor->id);
                $adapter=app(\App\Services\Dashboard\DesktopDashboardReviewDeletion::class);
                $command=$adapter->command($request);
                $payload=$adapter->payload($request,$command,$actor);$request->request->remove('_desktop_command');$response=null;
                $result=app(Journal::class)->execute((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$route,$payload,[],
                    function()use($adapter,$request,$next,&$response){return $adapter->capture(function()use($request,$next,&$response){return $response=$next($request);});});
                return $response??$adapter->response($result);
            });
        }
        if(\App\Services\Dashboard\DesktopDashboardWishlistDeletion::handles($route)){
            return DB::transaction(function()use($request,$next,$actor,$route){
                app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->writable((string)config('desktop_dashboard.device_id'),(int)$actor->id);
                $adapter=app(\App\Services\Dashboard\DesktopDashboardWishlistDeletion::class);
                $command=$adapter->command($request);
                $payload=$adapter->payload($request,$command,$actor);$request->request->remove('_desktop_command');$response=null;
                $result=app(Journal::class)->execute((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$route,$payload,[],
                    function()use($adapter,$request,$next,&$response){return $adapter->capture(function()use($request,$next,&$response){return $response=$next($request);});});
                return $response??$adapter->response($result);
            });
        }
        if(\App\Services\Dashboard\DesktopDashboardMenuAvailability::handles($route)){
            return DB::transaction(function()use($request,$next,$actor,$route){
                app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->writable((string)config('desktop_dashboard.device_id'),(int)$actor->id);
                $adapter=app(\App\Services\Dashboard\DesktopDashboardMenuAvailability::class);
                $command=(string)$request->input('idempotency_key');$payload=$adapter->payload($request,$command,$actor);$status=200;
                $result=app(Journal::class)->execute((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$route,$payload,[],function()use($request,$next,&$status){
                    $response=$next($request);$status=$response->getStatusCode();
                    if($status>=400)throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
                    return json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
                });
                return response()->json($result,$status)->header('Cache-Control','private, no-store');
            });
        }
        if(\App\Services\Dashboard\DesktopDashboardNotificationReads::handles($route)){
            $reads=app(\App\Services\Dashboard\DesktopDashboardNotificationReads::class);
            $command=(string)($request->header('X-Fasakhansta-Command')?:$request->input('_desktop_command'));
            $payload=$reads->payload($request,$command,$actor);$response=null;
            $result=app(Journal::class)->execute((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$route,$payload,[],
                function()use($reads,$next,$request,&$response){return $reads->capture(function()use($next,$request,&$response){return $response=$next($request);});});
            return $response??$reads->response($result);
        }
        if(\App\Services\Dashboard\DesktopDashboardLegacy::handles($route)){
            $legacy=app(\App\Services\Dashboard\DesktopDashboardLegacy::class);
            $store=\App\Services\Dashboard\DesktopDashboardGoStoreProfile::handles($route);
            if(!$store)$legacy->authorize($actor);
            if(\App\Services\Dashboard\DesktopDashboardRoleFacts::handles($route))$legacy->authorizeRoleRoute($route,$actor);
            $command=$store?app(\App\Services\Dashboard\DesktopDashboardGoStoreProfile::class)->command($request):(string)($request->header('X-Fasakhansta-Command')?:$request->input('_desktop_command'));
            return DB::transaction(function()use($legacy,$route,$command,$actor,$request,$next,$store){
                // Capture selected-row facts only after the common write/refresh fence.
                // Concurrent drag requests then observe the preceding committed order.
                app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->writable((string)config('desktop_dashboard.device_id'),(int)$actor->id);
                if($store){
                    $adapter=app(\App\Services\Dashboard\DesktopDashboardGoStoreProfile::class);$parameters=$request->route()->parameters();
                    $actor=$adapter->authorize($parameters,$actor);$state=app(\App\Services\Dashboard\DesktopDashboardRefresh::class)->lock((string)config('desktop_dashboard.device_id'));
                    $adapter->enrolledBranch($state,$parameters);
                }
                $payload=$legacy->payload($request,$command);
                // Journal metadata must never reach the original mass-assignment repositories.
                $request->request->remove('_desktop_command');$response=null;
                $result=app(Journal::class)->execute((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$route,$payload,[],
                    function()use($legacy,$route,$next,$request,$actor,$store,&$response){
                        if(!$store)return $legacy->capture($route,function()use($next,$request,&$response){return $response=$next($request);});
                        $guard=auth('admin');$previous=$guard->getUser();
                        try{$guard->setUser($actor);return $legacy->capture($route,function()use($next,$request,&$response){return $response=$next($request);});}
                        finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
                    });
                return $response??$legacy->response($result);
            });
        }
        // Existing forms already supply an immutable UUID. Do not generate another after losing a reply.
        $command=(string)$request->input('idempotency_key');
        $payload=['parameters'=>$request->route()->parameters(),'values'=>$request->except('_token','attachment'),'files'=>app(\App\Services\Dashboard\DesktopDashboardExpenseAttachments::class)->files($request,$route)];
        if(in_array($route,['branch-shifts.close','employees.close'],true)){
            $facts=app(Journal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$route);
            if($facts!==null)$payload['facts']=$facts;
            elseif($route==='branch-shifts.close')$payload['facts']['shift']=app(\App\Services\Dashboard\BranchShiftClosing::class)->desktopReview($request->all(),$actor);
            else $payload['facts']['payroll']=app(\App\Services\Dashboard\BranchPayroll::class)->desktopReview($request->all(),$actor);
        }
        $status=200;
        $result=app(Journal::class)->execute((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$route,$payload,[],function()use($next,$request,&$status){
            $response=$next($request);$status=$response->getStatusCode();
            // Abort rolls back all writes made by the original service and records no success.
            if ($status>=400) throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
            $result=json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
            if (!is_array($result)) throw new \LogicException('Expected the original dashboard JSON response.');
            return $result;
        });
        return response()->json($result,$status)->header('Cache-Control','private, no-store');
    }
}
