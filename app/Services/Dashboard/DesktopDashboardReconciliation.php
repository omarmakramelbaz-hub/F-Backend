<?php
namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\{DB,Validator,Crypt};

class DesktopDashboardReconciliation
{
    public function __construct(private DesktopDashboardDevices $devices,private DesktopDashboardJournal $journal,private DesktopDashboardReferences $references,private DesktopDashboardCommands $commands) {}
    private function receiptEnrollment(object $device,string $command): array
    {
        $binding=['format'=>1,'fingerprint'=>$this->devices->enrollmentFingerprint($device)];
        $old=DB::table('desktop_dashboard_commands')->where('device_id',$device->id)->where('command_id',$command)->lockForUpdate()->first();
        if($old){
            try{$payload=json_decode(Crypt::decryptString($old->command_cipher),true,512,JSON_THROW_ON_ERROR);}
            catch(\Throwable $error){abort(503,'هوية ربط العملية المحفوظة تحتاج مراجعة؛ لم تُلغَ العملية ولم تُعد المحاولة تلقائيًا.');}
            $saved=$payload['enrollment_binding']??null;
            abort_unless(is_array($saved)&&array_keys($saved)===['format','fingerprint']&&$saved['format']===1
                &&is_string($saved['fingerprint'])&&preg_match('/^[a-f0-9]{64}$/D',$saved['fingerprint']),503,
                'هوية ربط العملية المحفوظة غير مكتملة؛ راجع نتيجتها على السيرفر قبل استكمال المزامنة.');
            abort_unless(hash_equals($binding['fingerprint'],$saved['fingerprint']),401,
                'العملية المحفوظة تخص ربطًا سابقًا للجهاز؛ راجع نتيجتها على السيرفر.');
        }
        return $binding;
    }
    public function ingest(object $device,array $values): array
    {
        if($device instanceof DesktopDashboardWorkspaceScope){
            // Only the workspace envelope verifier can install this exact actor
            // facade. A bearer token or a caller-supplied actor ID cannot do so.
            abort_unless(DesktopDashboardWorkspaceExecutionContext::current()===$device,403);
            $device->revalidated();
        }
        $v=Validator::make($values,[
            'command_id'=>'required|uuid','actor_id'=>'required|integer|min:1','route_name'=>'required|string|max:150',
            'payload'=>'required|array','payload.values'=>'required|array','payload.parameters'=>'nullable|array','payload.files'=>'nullable|array','payload.facts'=>'nullable|array',
            'print_ownership'=>'sometimes|array|max:3','local_result'=>'required|array','local_references'=>'present|array','local_references.*'=>'required|integer|min:1','dependencies'=>'present|array|max:1000','dependencies.*'=>'required|uuid|distinct','occurred_at'=>'required|date',
        ])->validate();
        // Preserve the captured associative envelope layout while taking every
        // value from validation; newer engines otherwise reorder nested keys.
        $v['payload']=array_replace(array_intersect_key($values['payload'],$v['payload']),$v['payload']);
        abort_unless((int)$v['actor_id']===(int)$device->actor_id,403);
        abort_unless(DesktopDashboardRoutes::journaled($v['route_name']),422);
        abort_unless(($v['payload']['values']['idempotency_key']??'')===$v['command_id'],422,'رقم طلب العملية مختلف عن سجلها.');
        // Journal and enrollment DATETIME columns are UTC, independently of Cairo's UI timezone.
        $when=Carbon::parse($v['occurred_at'],'UTC')->setTimezone('UTC');
        abort_unless($when->lte(now('UTC')->addMinutes(5)) && $when->gte(Carbon::parse($device->enrolled_at,'UTC')->subMinutes(5)),422,'وقت العملية خارج فترة ربط الجهاز.');
        $previous=Carbon::getTestNow();
        $work=function()use($device,$v,$when){$transaction=DesktopDashboardSettings::handles($v['route_name'])?fn($callback)=>app(DesktopDashboardSettings::class)->transaction($callback):fn($callback)=>DB::transaction($callback);return $transaction(function()use($device,$v,$when){
            // Serialize a device's imports and deduplicate before running any business service.
            $locked=$this->devices->lockAuthenticated($device);
            abort_unless((int)$locked->actor_id===(int)$v['actor_id'],403);
            $enrollmentBinding=$this->receiptEnrollment($locked,$v['command_id']);
            $actor=DesktopDashboardCoreAuthority::handles($v['route_name'])
                ?app(DesktopDashboardCoreAuthority::class)->authorize($v['route_name'],$v['payload']['values'],$locked)
                :$this->devices->actor($locked);
            if(DesktopDashboardOrderBoard::handles($v['route_name'])){
                abort_unless((int)$locked->actor_id===(int)$v['actor_id'],403);
                $adapter=app(DesktopDashboardOrderBoard::class);$adapter->validateReferences($v['payload']);
                abort_unless($v['local_references']===$adapter->outputs($v['local_result']),409,'مراجع نتيجة الطلب المحلية غير متطابقة.');
                $boardPayload=$this->references->resolve($device->id,$v['payload']);
                $actor=$adapter->authorize($boardPayload,$actor,$locked);
            }
            if(DesktopDashboardSettings::handles($v['route_name'])){
                $adapter=app(DesktopDashboardSettings::class);$adapter->validateReferences($v['payload']);
                abort_unless($v['local_references']===[],409);
                $settingsPayload=$this->references->resolve($device->id,$v['payload']);
                $actor=$adapter->authorize($v['route_name'],$settingsPayload,$actor,$locked);
            }
            if(DesktopDashboardWalletCash::handles($v['route_name'])){
                abort_unless((int)$locked->actor_id===(int)$v['actor_id'],403);
                $adapter=app(DesktopDashboardWalletCash::class);$adapter->validateReferences($v['payload']);
                abort_unless($v['local_references']===$adapter->outputs($v['local_result']),409,'مراجع نتيجة المحفظة المحلية غير متطابقة.');
                $walletPayload=$this->references->resolve($device->id,$v['payload']);
                $actor=$adapter->authorize($v['route_name'],$walletPayload,$actor,$locked);
            }
            if(DesktopDashboardUserProfiles::handles($v['route_name'])){
                abort_unless((int)$locked->actor_id===(int)$v['actor_id'],403);$adapter=app(DesktopDashboardUserProfiles::class);$adapter->validateReferences($v['payload']);$adapter->verifyLocal($v['payload'],$v['local_result'],$v['local_references']);
                $profilePayload=$this->references->resolve($device->id,$v['payload']);$actor=$adapter->authorize($profilePayload,$actor,$locked);
            }
            if(DesktopDashboardUserAccounts::handles($v['route_name'])){
                abort_unless((int)$locked->actor_id===(int)$v['actor_id'],403);
                $adapter=app(DesktopDashboardUserAccounts::class);$adapter->validateReferences($v['route_name'],$v['payload']);
                $adapter->verifyLocal($v['payload'],$v['local_result'],$v['local_references']);
                $accountPayload=$this->references->resolve($device->id,$v['payload']);
                $actor=$adapter->authorize($v['route_name'],$accountPayload,$actor,$locked);
            }
            if(DesktopDashboardCustomerDirectory::handles($v['route_name'])){
                abort_unless((int)$locked->actor_id===(int)$v['actor_id'],403);
                $adapter=app(DesktopDashboardCustomerDirectory::class);$adapter->validateReferences($v['payload']);
                abort_unless($v['local_references']===$adapter->outputs($v['route_name'],$v['local_result']),409,'مراجع نتيجة العميل المحلي غير متطابقة.');
                $crmPayload=$this->references->resolve($device->id,$v['payload']);
                $actor=$adapter->authorize($v['route_name'],$crmPayload,$actor,$locked);
            }
            if(DesktopDashboardCustomerFacts::handles($v['route_name'])){
                abort_unless((int)$locked->actor_id===(int)$v['actor_id'],403);
                app(DesktopDashboardCustomerFacts::class)->validateReferences($v['payload']);
                $this->devices->branch($locked,(string)($v['payload']['values']['branch']??''),$actor);
            }
            if(DesktopDashboardRestaurantManagement::handles($v['route_name'])){
                abort_unless((int)$locked->actor_id===(int)$v['actor_id'],403);$adapter=app(DesktopDashboardRestaurantManagement::class);$adapter->validateReferences($v['payload']);
                abort_unless($v['local_references']===$adapter->outputs($v['local_result']),409);$goPayload=$this->references->resolve($device->id,$v['payload']);
                $actor=$adapter->authorize($v['route_name'],$goPayload,$actor,$locked);
            }
            if(DesktopDashboardGoStoreCreation::handles($v['route_name'])){
                abort_unless((int)$locked->actor_id===(int)$v['actor_id'],403);$adapter=app(DesktopDashboardGoStoreCreation::class);$adapter->validateReferences($v['payload']);
                abort_unless($v['local_references']===$adapter->outputs($v['local_result']),409);$goPayload=$this->references->resolve($device->id,$v['payload']);
                $actor=$adapter->authorize($v['route_name'],$goPayload,$actor,$locked);
            }
            if(DesktopDashboardGoProducts::handles($v['route_name'])){
                abort_unless((int)$locked->actor_id===(int)$v['actor_id'],403);$adapter=app(DesktopDashboardGoProducts::class);$adapter->validateReferences($v['payload']);
                abort_unless($v['local_references']===$adapter->outputs($v['local_result']),409);$goPayload=$this->references->resolve($device->id,$v['payload']);
                $actor=$adapter->authorize($v['route_name'],$goPayload,$actor,$locked);
            }
            if(DesktopDashboardRestaurantControl::handles($v['route_name'])||DesktopDashboardRestaurantFlags::handles($v['route_name'])||DesktopDashboardRestaurantProducts::handles($v['route_name'])||DesktopDashboardRestaurantProductCrud::handles($v['route_name'])||DesktopDashboardRestaurantMenuCopy::handles($v['route_name'])){
                abort_unless((int)$locked->actor_id===(int)$v['actor_id'],403,'حساب الجهاز تغيّر قبل تنفيذ العملية المحلية.');
                $adapter=app(match(true){DesktopDashboardRestaurantControl::handles($v['route_name'])=>DesktopDashboardRestaurantControl::class,DesktopDashboardRestaurantFlags::handles($v['route_name'])=>DesktopDashboardRestaurantFlags::class,DesktopDashboardRestaurantMenuCopy::handles($v['route_name'])=>DesktopDashboardRestaurantMenuCopy::class,DesktopDashboardRestaurantProductCrud::handles($v['route_name'])=>DesktopDashboardRestaurantProductCrud::class,default=>DesktopDashboardRestaurantProducts::class});$adapter->validateReferences($v['payload']);
                if(DesktopDashboardRestaurantControl::handles($v['route_name'])||DesktopDashboardRestaurantProducts::handles($v['route_name'])||DesktopDashboardRestaurantProductCrud::handles($v['route_name'])||DesktopDashboardRestaurantMenuCopy::handles($v['route_name']))
                    abort_unless($v['local_references']===$adapter->outputs($v['local_result']),409,'مراجع نتيجة صنف المطعم المحلية غير متطابقة.');
                $flagPayload=$this->references->resolve($device->id,$v['payload']);
                $actor=$adapter->authorize($v['route_name'],$flagPayload,$actor,$locked);
            }
            if(DesktopDashboardReviewDeletion::handles($v['route_name'])){
                app(DesktopDashboardReviewDeletion::class)->validateReferences($v['payload']);
                $reviewPayload=$this->references->resolve($device->id,$v['payload']);
                $actor=app(DesktopDashboardReviewDeletion::class)->authorize($reviewPayload,$actor,$locked);
            }
            if(DesktopDashboardWishlistDeletion::handles($v['route_name'])){
                app(DesktopDashboardWishlistDeletion::class)->validateReferences($v['payload']);
                $wishlistPayload=$this->references->resolve($device->id,$v['payload']);
                $actor=app(DesktopDashboardWishlistDeletion::class)->authorize($wishlistPayload,$actor,$locked);
            }
            if(DesktopDashboardRoleFacts::handles($v['route_name']))app(DesktopDashboardLegacy::class)->authorizeRoleRoute($v['route_name'],$actor);
            if(DesktopDashboardGoStoreProfile::handles($v['route_name'])){
                $adapter=app(DesktopDashboardGoStoreProfile::class);$adapter->validateReferences($v['payload']);
                $storePayload=$this->references->resolve($device->id,$v['payload']);
                $actor=$adapter->authorize($storePayload['parameters']??[],$actor);$adapter->enrolledBranch($locked,$storePayload['parameters']);
            }
            if(DesktopDashboardMenuAvailability::handles($v['route_name'])){
                app(DesktopDashboardMenuAvailability::class)->validateReferences($v['payload']);
                $menuPayload=$this->references->resolve($device->id,$v['payload']);
                $actor=app(DesktopDashboardMenuAvailability::class)->authorize($menuPayload['parameters']??[],$actor)['actor'];
                app(DesktopDashboardMenuAvailability::class)->enrolledBranch($locked,$menuPayload['parameters']);
            }
            try{$receipt=$this->journal->execute($device->id,$v['command_id'],(int)$actor->id,$v['route_name'],['enrollment_binding'=>$enrollmentBinding,'envelope'=>$v],$v['dependencies'],function()use($device,$v,$when,$actor,$locked){
                foreach($v['dependencies'] as $id)abort_unless(DB::table('desktop_dashboard_commands')->where('device_id',$device->id)->where('command_id',$id)->where('status','acknowledged')->exists(),409,'العملية السابقة لم تصل للسيرفر بعد.');
                $payload=$this->references->resolve($device->id,$v['payload']);
                if(DesktopDashboardCustomerFacts::handles($v['route_name'])){
                    // Match the original service lock order before locking ticket/CRM facts.
                    app(TakeawayAccess::class)->branch((string)($payload['values']['branch']??''),$actor,true);
                    app(DesktopDashboardCustomerFacts::class)->guard($v['route_name'],$payload);
                }
                // New expense categories are branch scoped; legacy receipts without a
                // branch remain eligible only for the original service's read-only retry.
                if($v['route_name']==='branch-expenses.categorySave'&&!empty($payload['values']['branch']))
                    $this->devices->branch($locked,(string)$payload['values']['branch'],$actor);
                if(DesktopDashboardLegacy::handles($v['route_name']))app(DesktopDashboardLegacy::class)->authorize($actor,$v['route_name']);
                elseif(!DesktopDashboardGoStoreCreation::handles($v['route_name'])&&!DesktopDashboardRestaurantManagement::handles($v['route_name'])&&!DesktopDashboardSettings::handles($v['route_name'])&&!DesktopDashboardOrderBoard::handles($v['route_name'])&&!DesktopDashboardUserProfiles::handles($v['route_name'])&&!DesktopDashboardUserAccounts::handles($v['route_name'])&&!DesktopDashboardGoProducts::handles($v['route_name'])&&!DesktopDashboardRestaurantControl::handles($v['route_name'])&&!DesktopDashboardWalletCash::handles($v['route_name'])&&!DesktopDashboardCustomerDirectory::handles($v['route_name'])&&!DesktopDashboardRestaurantFlags::handles($v['route_name'])&&!DesktopDashboardRestaurantProducts::handles($v['route_name'])&&!DesktopDashboardRestaurantProductCrud::handles($v['route_name'])&&!DesktopDashboardRestaurantMenuCopy::handles($v['route_name'])&&!DesktopDashboardReviewDeletion::handles($v['route_name'])&&!DesktopDashboardWishlistDeletion::handles($v['route_name'])&&!DesktopDashboardMenuAvailability::handles($v['route_name'])&&!DesktopDashboardNotificationReads::handles($v['route_name'])&&!in_array($v['route_name'],['branch-expenses.categorySave','dashboard-inbox.notifications.read'],true))
                    $this->devices->branch($locked,(string)($payload['values']['branch']??''),$actor);
                // Authorization above uses CURRENT persisted roles. Business dates below use the original occurrence.
                $clock=Carbon::getTestNow();
                if($v['route_name']==='employees.attendance'){
                    $field=($payload['values']['action']??'')==='check_out'?'checked_out_at':'checked_in_at';
                    $actual=$v['local_result']['attendance'][$field]??null;
                    // A later repeat click preserves the first punch; only use a new punch close to this command's time.
                    if($actual){$punch=Carbon::parse($actual,'UTC');if(abs($punch->getTimestamp()-$when->getTimestamp())<=5)$when=$punch;}
                }
                Carbon::setTestNow($when);
                try{
                    $execute=function()use($locked,$v,$payload,$actor,$when){
                    $run=fn()=>app(DesktopDashboardPrintOwnership::class)->replay($locked,$v,fn()=>$this->commands->execute($v['route_name'],$payload,$actor),$payload);
                    $result=DesktopDashboardCustomerFacts::handles($v['route_name'])?app(DesktopDashboardCustomerFacts::class)->replay($payload,$run):$run();
                    if(DesktopDashboardOrderBoard::handles($v['route_name']))app(DesktopDashboardOrderBoard::class)->verifyAfter($result,$v['local_result'],$payload,$v['payload']);
                    if(DesktopDashboardSettings::handles($v['route_name']))app(DesktopDashboardSettings::class)->verifyAfter($result,$v['local_result'],$payload,$v['payload']);
                    if(DesktopDashboardWalletCash::handles($v['route_name']))app(DesktopDashboardWalletCash::class)->verifyAfter($result,$v['local_result']);
                    if(DesktopDashboardCustomerDirectory::handles($v['route_name']))
                        app(DesktopDashboardCustomerDirectory::class)->verifyAfter($v['route_name'],$result,$v['local_result']);
                    if(DesktopDashboardRestaurantManagement::handles($v['route_name']))app(DesktopDashboardRestaurantManagement::class)->verifyAfter($result,$v['local_result'],$payload,$v['payload']);
                    if(DesktopDashboardGoStoreCreation::handles($v['route_name']))app(DesktopDashboardGoStoreCreation::class)->verifyAfter($result,$v['local_result'],$payload,$v['payload']);
                    if(DesktopDashboardGoProducts::handles($v['route_name']))app(DesktopDashboardGoProducts::class)->verifyAfter($result,$v['local_result'],$payload,$v['payload']);
                    if(DesktopDashboardRestaurantControl::handles($v['route_name']))
                        app(DesktopDashboardRestaurantControl::class)->verifyAfter($result,$v['local_result'],$payload,$v['payload']);
                    if(DesktopDashboardRestaurantFlags::handles($v['route_name']))
                        app(DesktopDashboardRestaurantFlags::class)->verifyAfter($result,$v['local_result'],$payload,$v['payload']);
                    if(DesktopDashboardRestaurantProducts::handles($v['route_name']))
                        app(DesktopDashboardRestaurantProducts::class)->verifyAfter($result,$v['local_result'],$payload,$v['payload']);
                    if(DesktopDashboardRestaurantProductCrud::handles($v['route_name']))
                        app(DesktopDashboardRestaurantProductCrud::class)->verifyAfter($result,$v['local_result'],$payload,$v['payload']);
                    if(DesktopDashboardRestaurantMenuCopy::handles($v['route_name']))
                        app(DesktopDashboardRestaurantMenuCopy::class)->verifyAfter($result,$v['local_result'],$payload,$v['payload']);
                    if($v['route_name']==='employees.attendance'){
                        $facts=function($row){return array_intersect_key($row,array_flip(['status','checked_in_at','checked_out_at','attendance_rule_snapshot']));};
                        abort_unless($this->journal->fingerprint($facts($result['attendance']??[]))===$this->journal->fingerprint($facts($v['local_result']['attendance']??[])),409,'مواعيد أو خصومات الحضور تغيرت على السيرفر؛ العملية المحلية محفوظة للمراجعة.');
                    }
                    $server=DesktopDashboardMenuAvailability::handles($v['route_name'])?app(DesktopDashboardMenuAvailability::class)->outputs($result,$payload['parameters']):$this->references->outputs($v['route_name'],$result,$payload['values'],(int)$actor->id,$payload['facts']??[]);
                    return [$result,$server];
                    };
                    [$result,$server]=match(true){
                        DesktopDashboardRestaurantManagement::handles($v['route_name'])=>app(DesktopDashboardRestaurantManagement::class)->replayContext($v['route_name'],$v['local_result'],$v['payload'],$when,$execute),
                        DesktopDashboardGoProducts::handles($v['route_name'])=>app(DesktopDashboardGoProducts::class)->replayContext($v['route_name'],$v['local_result'],$v['payload'],$when,$execute),
                        DesktopDashboardOrderBoard::handles($v['route_name'])=>app(DesktopDashboardOrderBoard::class)->replayContext($v['local_result'],$v['payload'],$execute),
                        DesktopDashboardRestaurantMenuCopy::handles($v['route_name'])=>app(DesktopDashboardRestaurantMenuCopy::class)->replayContext($v['route_name'],$v['local_result'],$v['payload'],$when,$execute),
                        DesktopDashboardRestaurantProductCrud::handles($v['route_name'])=>app(DesktopDashboardRestaurantProductCrud::class)->replayContext($v['route_name'],$v['local_result'],$v['payload'],$when,$execute),
                        default=>$execute(),
                    };
                }finally{Carbon::setTestNow($clock);}
                $local=$v['local_references'];$mapping=[];
                foreach($local as $entity=>$id){abort_unless(isset($server[$entity]),409,'نتيجة السيرفر لا تطابق مراجع العملية المحلية.');$mapping[]=['entity'=>$entity,'local_id'=>$id,'server_id'=>$server[$entity]];}
                return ['device_id'=>$device->id,'command_id'=>$v['command_id'],'committed'=>true,'result'=>$result,'references'=>$mapping];
            });
            if(DesktopDashboardSettings::handles($v['route_name']))app(DesktopDashboardSettingsImages::class)->verifyResult($v['route_name'],$receipt['result'],$v['payload']['files'],$v['command_id']);
            if($v['route_name']===DesktopDashboardCreatedScopes::ROUTE){$createdPayload=$this->references->resolve($device->id,$v['payload']);$createdResult=$receipt['result'];
                app(DesktopDashboardCreatedScopes::class)->captureCentral($locked,$v,$createdPayload,$createdResult,app(DesktopDashboardGoStoreCreation::class)->outputs($createdResult));}
            $this->journal->acknowledge($device->id,$v['command_id'],$receipt);
            return $receipt;}catch(\Throwable $error){if(DesktopDashboardRestaurantManagement::handles($v['route_name']))app(DesktopDashboardRestaurantImages::class)->rollbackKnown();if(DesktopDashboardGoProducts::handles($v['route_name']))app(DesktopDashboardGoProductImages::class)->rollbackKnown();throw $error;}
        });};
        try{if(DesktopDashboardRestaurantManagement::handles($v['route_name']))return app(DesktopDashboardRestaurantImages::class)->guard(fn()=>app(DesktopDashboardRestaurantEffects::class)->guard($work));if(DesktopDashboardSettings::handles($v['route_name']))return app(DesktopDashboardSettings::class)->guard($work);if(DesktopDashboardGoProducts::handles($v['route_name']))return app(DesktopDashboardGoProductImages::class)->guard($work);return (DesktopDashboardRestaurantProductCrud::handles($v['route_name'])||DesktopDashboardRestaurantMenuCopy::handles($v['route_name']))?app(DesktopDashboardProductImages::class)->guard(fn()=>app(DesktopDashboardProductEffects::class)->guard($work)):$work();}
        finally{Carbon::setTestNow($previous);}
    }
}
