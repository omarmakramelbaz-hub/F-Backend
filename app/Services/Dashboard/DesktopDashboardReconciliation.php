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
            'payload'=>'required|array','payload.values'=>'required|array','payload.parameters'=>'nullable|array','payload.files'=>'nullable|array',
            'local_result'=>'required|array','local_references'=>'present|array','local_references.*'=>'required|integer|min:1','dependencies'=>'present|array|max:1000','dependencies.*'=>'required|uuid|distinct','occurred_at'=>'required|date',
        ])->validate();
        abort_unless((int)$v['actor_id']===(int)$device->actor_id,403);
        abort_unless(DesktopDashboardRoutes::journaled($v['route_name']),422);
        abort_unless(($v['payload']['values']['idempotency_key']??'')===$v['command_id'],422,'رقم طلب العملية مختلف عن سجلها.');
        // Journal and enrollment DATETIME columns are UTC, independently of Cairo's UI timezone.
        $when=Carbon::parse($v['occurred_at'],'UTC')->setTimezone('UTC');
        abort_unless($when->lte(now('UTC')->addMinutes(5)) && $when->gte(Carbon::parse($device->enrolled_at,'UTC')->subMinutes(5)),422,'وقت العملية خارج فترة ربط الجهاز.');
        $previous=Carbon::getTestNow();
        try{return DB::transaction(function()use($device,$v,$when){
            // Serialize a device's imports and deduplicate before running any business service.
            $locked=DB::table('desktop_dashboard_devices')->where('id',$device->id)->lockForUpdate()->first();abort_unless($locked&&$locked->enabled,401);
            $actor=$this->devices->actor($locked);
            if(DesktopDashboardRoleFacts::handles($v['route_name']))app(DesktopDashboardLegacy::class)->authorizeRoleRoute($v['route_name'],$actor);
            $receipt=$this->journal->execute($device->id,$v['command_id'],(int)$actor->id,$v['route_name'],['envelope'=>$v],$v['dependencies'],function()use($device,$v,$when,$actor){
                foreach($v['dependencies'] as $id)abort_unless(DB::table('desktop_dashboard_commands')->where('device_id',$device->id)->where('command_id',$id)->where('status','acknowledged')->exists(),409,'العملية السابقة لم تصل للسيرفر بعد.');
                $payload=$this->references->resolve($device->id,$v['payload']);
                if(DesktopDashboardLegacy::handles($v['route_name']))app(DesktopDashboardLegacy::class)->authorize($actor);
                elseif(!DesktopDashboardNotificationReads::handles($v['route_name'])&&!in_array($v['route_name'],['branch-expenses.categorySave','dashboard-inbox.notifications.read'],true))
                    $this->devices->branch($device,(string)($payload['values']['branch']??''),$actor);
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
                    $result=$this->commands->execute($v['route_name'],$payload,$actor);
                    if($v['route_name']==='employees.attendance'){
                        $facts=function($row){return array_intersect_key($row,array_flip(['status','checked_in_at','checked_out_at','attendance_rule_snapshot']));};
                        abort_unless($this->journal->fingerprint($facts($result['attendance']??[]))===$this->journal->fingerprint($facts($v['local_result']['attendance']??[])),409,'مواعيد أو خصومات الحضور تغيرت على السيرفر؛ العملية المحلية محفوظة للمراجعة.');
                    }
                    $server=$this->references->outputs($v['route_name'],$result,$payload['values'],(int)$actor->id);
                }finally{Carbon::setTestNow($clock);}
                $local=$v['local_references'];$mapping=[];
                foreach($local as $entity=>$id){abort_unless(isset($server[$entity]),409,'نتيجة السيرفر لا تطابق مراجع العملية المحلية.');$mapping[]=['entity'=>$entity,'local_id'=>$id,'server_id'=>$server[$entity]];}
                return ['device_id'=>$device->id,'command_id'=>$v['command_id'],'committed'=>true,'result'=>$result,'references'=>$mapping];
            });
            $this->journal->acknowledge($device->id,$v['command_id'],$receipt);
            return $receipt;
        });}finally{Carbon::setTestNow($previous);}
    }
}
