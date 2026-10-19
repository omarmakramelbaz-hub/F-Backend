<?php
namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\{DB,Validator};

class DesktopDashboardReconciliation
{
    public function __construct(private DesktopDashboardDevices $devices,private DesktopDashboardJournal $journal,private DesktopDashboardReferences $references,private DesktopDashboardCommands $commands) {}
    public function ingest(object $device,array $values): array
    {
        $v=Validator::make($values,[
            'command_id'=>'required|uuid','actor_id'=>'required|integer|min:1','route_name'=>'required|string|max:150',
            'payload'=>'required|array','payload.values'=>'required|array','payload.parameters'=>'nullable|array','payload.files'=>'nullable|array','payload.facts'=>'nullable|array',
            'print_ownership'=>'sometimes|array|max:3','local_result'=>'required|array','local_references'=>'present|array','local_references.*'=>'required|integer|min:1','dependencies'=>'present|array|max:1000','dependencies.*'=>'required|uuid|distinct','occurred_at'=>'required|date',
        ])->validate();
        abort_unless((int)$v['actor_id']===(int)$device->actor_id,403);
        abort_unless(DesktopDashboardRoutes::journaled($v['route_name']),422);
        abort_unless(($v['payload']['values']['idempotency_key']??'')===$v['command_id'],422,'رقم طلب العملية مختلف عن سجلها.');
        // Journal and enrollment DATETIME columns are UTC, independently of Cairo's UI timezone.
        $when=Carbon::parse($v['occurred_at'],'UTC')->setTimezone('UTC');
        abort_unless($when->lte(now('UTC')->addMinutes(5)) && $when->gte(Carbon::parse($device->enrolled_at,'UTC')->subMinutes(5)),422,'وقت العملية خارج فترة ربط الجهاز.');
        $previous=Carbon::getTestNow();
        $work=function()use($device,$v,$when){return DB::transaction(function()use($device,$v,$when){
            // Serialize a device's imports and deduplicate before running any business service.
            $locked=DB::table('desktop_dashboard_devices')->where('id',$device->id)->lockForUpdate()->first();abort_unless($locked&&$locked->enabled,401);
            $actor=$this->devices->actor($locked);
            if(DesktopDashboardWalletCash::handles($v['route_name'])){
                abort_unless((int)$locked->actor_id===(int)$v['actor_id'],403);
                $adapter=app(DesktopDashboardWalletCash::class);$adapter->validateReferences($v['payload']);
                abort_unless($v['local_references']===$adapter->outputs($v['local_result']),409,'مراجع نتيجة المحفظة المحلية غير متطابقة.');
                $walletPayload=$this->references->resolve($device->id,$v['payload']);
                $actor=$adapter->authorize($v['route_name'],$walletPayload,$actor,$locked);
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
            $receipt=$this->journal->execute($device->id,$v['command_id'],(int)$actor->id,$v['route_name'],['envelope'=>$v],$v['dependencies'],function()use($device,$v,$when,$actor,$locked){
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
                elseif(!DesktopDashboardRestaurantControl::handles($v['route_name'])&&!DesktopDashboardWalletCash::handles($v['route_name'])&&!DesktopDashboardCustomerDirectory::handles($v['route_name'])&&!DesktopDashboardRestaurantFlags::handles($v['route_name'])&&!DesktopDashboardRestaurantProducts::handles($v['route_name'])&&!DesktopDashboardRestaurantProductCrud::handles($v['route_name'])&&!DesktopDashboardRestaurantMenuCopy::handles($v['route_name'])&&!DesktopDashboardReviewDeletion::handles($v['route_name'])&&!DesktopDashboardWishlistDeletion::handles($v['route_name'])&&!DesktopDashboardMenuAvailability::handles($v['route_name'])&&!DesktopDashboardNotificationReads::handles($v['route_name'])&&!in_array($v['route_name'],['branch-expenses.categorySave','dashboard-inbox.notifications.read'],true))
                    $this->devices->branch(DesktopDashboardCustomerFacts::handles($v['route_name'])?$locked:$device,(string)($payload['values']['branch']??''),$actor);
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
                    if(DesktopDashboardWalletCash::handles($v['route_name']))app(DesktopDashboardWalletCash::class)->verifyAfter($result,$v['local_result']);
                    if(DesktopDashboardCustomerDirectory::handles($v['route_name']))
                        app(DesktopDashboardCustomerDirectory::class)->verifyAfter($v['route_name'],$result,$v['local_result']);
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
                        DesktopDashboardRestaurantMenuCopy::handles($v['route_name'])=>app(DesktopDashboardRestaurantMenuCopy::class)->replayContext($v['route_name'],$v['local_result'],$v['payload'],$when,$execute),
                        DesktopDashboardRestaurantProductCrud::handles($v['route_name'])=>app(DesktopDashboardRestaurantProductCrud::class)->replayContext($v['route_name'],$v['local_result'],$v['payload'],$when,$execute),
                        default=>$execute(),
                    };
                }finally{Carbon::setTestNow($clock);}
                $local=$v['local_references'];$mapping=[];
                foreach($local as $entity=>$id){abort_unless(isset($server[$entity]),409,'نتيجة السيرفر لا تطابق مراجع العملية المحلية.');$mapping[]=['entity'=>$entity,'local_id'=>$id,'server_id'=>$server[$entity]];}
                return ['device_id'=>$device->id,'command_id'=>$v['command_id'],'committed'=>true,'result'=>$result,'references'=>$mapping];
            });
            $this->journal->acknowledge($device->id,$v['command_id'],$receipt);
            return $receipt;
        });};
        try{return (DesktopDashboardRestaurantProductCrud::handles($v['route_name'])||DesktopDashboardRestaurantMenuCopy::handles($v['route_name']))?app(DesktopDashboardProductImages::class)->guard(fn()=>app(DesktopDashboardProductEffects::class)->guard($work)):$work();}
        finally{Carbon::setTestNow($previous);}
    }
}
