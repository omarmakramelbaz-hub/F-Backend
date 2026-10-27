<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Schema,Validator,Crypt};

/** Central ownership is committed with replay; device-owned work never enters pending queues. */
final class DesktopDashboardPrintOwnership
{
    private static array $contexts=[];
    public static function owns(string $kind): bool {return in_array($kind,end(self::$contexts)?:[],true);}
    public function replay(object $device,array $envelope,callable $work,array $resolved=[]): array
    {
        $proof=$envelope['print_ownership']??[];abort_unless(is_array($proof)&&count($proof)<=3,422);
        $branches=json_decode($device->branches,true,512,JSON_THROW_ON_ERROR);sort($branches,SORT_STRING);
        $enrollment=$this->enrollmentIdentity($device,$branches);
        $kinds=[];
        foreach($proof as $item){
            Validator::make($item,['effect_id'=>'required|regex:/^[a-f0-9]{64}$/D','enrollment_id'=>'required|regex:/^[a-f0-9]{64}$/D','origin_command_id'=>'required|uuid',
                'kind'=>'required|in:sale_receipt,phone_kitchen,dining_kitchen,bill_receipt,shift_report,delivery_batch','slot'=>'required|in:primary','revision'=>'required|integer|min:1','local_references'=>'required|array',
                'snapshot_sha256'=>'required|regex:/^[a-f0-9]{64}$/D'])->validate();
            abort_unless($item['enrollment_id']===$enrollment&&$item['origin_command_id']===$envelope['command_id']
                &&$item['effect_id']===$this->hash([$device->id,$enrollment,$envelope['command_id'],$item['kind'],'primary'])&&!in_array($item['kind'],$kinds,true),409);
            $route=$envelope['route_name'];$refs=$item['local_references'];
            $allowed=match($item['kind']){'sale_receipt'=>['takeaway.checkout','dining.settle','phone-orders.settle'],'phone_kitchen'=>['phone-orders.save','phone-orders.action'],'dining_kitchen'=>['dining.action'],'bill_receipt'=>['dining.action','phone-orders.action'],'shift_report'=>['branch-shifts.close'],'delivery_batch'=>['phone-orders.finish-batch']};
            abort_unless(in_array($route,$allowed,true),409);
            $entity=match($item['kind']){'sale_receipt'=>'receipt','phone_kitchen','dining_kitchen','bill_receipt'=>'ticket','shift_report'=>'shift','delivery_batch'=>'delivery_batch'};
            abort_unless(isset($refs[$entity])&&$refs[$entity]===($envelope['local_references'][$entity]??null),409);
            foreach($refs as $id)abort_unless(is_int($id)&&$id>0,422);
            $expected=match($item['kind']){'sale_receipt'=>['receipt','sale_till_entry.primary'],'phone_kitchen'=>['ticket','kitchen.primary','print_job.primary'],'dining_kitchen'=>['ticket','kitchen.primary'],'bill_receipt'=>['ticket','bill_command.primary'],'shift_report'=>['shift','shift_print_job.primary'],'delivery_batch'=>array_values(array_filter(array_keys($envelope['local_references']),fn($key)=>$key==='delivery_batch'||preg_match('/^receipt\.[0-9]+$/D',$key)))};$actual=array_keys($refs);sort($actual,SORT_STRING);sort($expected,SORT_STRING);abort_unless($actual===$expected,422);
            if($item['kind']==='bill_receipt')abort_unless(($envelope['payload']['values']['action']??null)==='request_bill',409);
            if($item['kind']==='phone_kitchen')abort_unless(app(TakeawayAccess::class)->receiverBranch(app(DesktopDashboardDevices::class)->actor($device))===($envelope['payload']['values']['branch']??null),403);
            if($item['kind']==='shift_report')abort_unless(app(BranchShiftClosing::class)->canClose(app(DesktopDashboardDevices::class)->actor($device)),403);
            $kinds[]=$item['kind'];
        }
        if($proof)abort_unless(Schema::hasTable('desktop_dashboard_print_ownership'),503,'ملكية الطباعة تحتاج تحديث السيرفر.');
        self::$contexts[]=$kinds;
        try{$result=$work();}finally{array_pop(self::$contexts);}
        foreach($proof as $item){
            $branch=(string)$envelope['payload']['values']['branch'];$server=[];
            if($item['kind']==='delivery_batch'){
                $server=array_intersect_key(app(DesktopDashboardReferences::class)->outputs($envelope['route_name'],$result,$resolved['values']??[],(int)$device->actor_id),$item['local_references']);
                abort_unless(count($server)===count($item['local_references']),409);
            }elseif($item['kind']==='sale_receipt'){
                $order=DB::table('takeaway_orders')->where('branch',$branch)->where('actor_id',$device->actor_id)->where('request_key',$envelope['command_id'])->first();abort_unless($order,409);
                $entry=DB::table('takeaway_till_entries')->where('order_id',$order->id)->where('kind','sale')->where('request_key',$envelope['command_id'])->first();abort_unless($entry,409);
                $server=['receipt'=>(int)$order->id,'sale_till_entry.primary'=>(int)$entry->id];
            }elseif(in_array($item['kind'],['phone_kitchen','dining_kitchen','bill_receipt'],true)){
                $op=DB::table('pos_service_commands')->where('branch',$branch)->where('actor_id',$device->actor_id)->where('request_key',$envelope['command_id'])->first();abort_unless($op,409);
                if($item['kind']==='bill_receipt'){abort_unless((int)$op->revision===(int)$item['revision']&&!empty(json_decode($op->metadata??'{}',true)['bill_issued']),409);$server=['ticket'=>(int)$op->ticket_id,'bill_command.primary'=>(int)$op->id];}
                else {
                $kitchen=DB::table('pos_service_kitchen_tickets')->where('ticket_id',$op->ticket_id)->where('revision',$op->revision)->first();abort_unless($kitchen&&(int)$kitchen->revision===(int)$item['revision'],409);
                $server=['ticket'=>(int)$op->ticket_id,'kitchen.primary'=>(int)$kitchen->id];
                if($item['kind']==='phone_kitchen'){$job=DB::table('pos_branch_print_jobs')->where('kitchen_id',$kitchen->id)->where('branch',$branch)->first();abort_unless($job&&$job->status==='device_owned',409);$server['print_job.primary']=(int)$job->id;}
                }
            }else{
                $closing=DB::table('branch_shift_closings')->where('branch',$branch)->where('actor_id',$device->actor_id)->where('request_key',$envelope['command_id'])->first();abort_unless($closing,409);
                $job=DB::table('branch_shift_print_jobs')->where('closing_id',$closing->id)->where('actor_id',$device->actor_id)->where('request_key',$envelope['command_id'])->first();abort_unless($job&&$job->status==='device_owned',409);
                $server=['shift'=>(int)$closing->id,'shift_print_job.primary'=>(int)$job->id];
            }
            $row=['effect_id'=>$item['effect_id'],'device_id'=>$device->id,'command_id'=>$envelope['command_id'],'enrollment_id'=>$enrollment,'actor_id'=>$device->actor_id,'branch'=>$branch,
                'kind'=>$item['kind'],'revision'=>(int)$item['revision'],'descriptor_hash'=>$this->hash($item),'local_references'=>json_encode($item['local_references']),'server_references'=>json_encode($server),
                'outcome'=>null,'outcome_hash'=>null,'created_at'=>now('UTC'),'updated_at'=>now('UTC')];
            $old=DB::table('desktop_dashboard_print_ownership')->where('effect_id',$item['effect_id'])->lockForUpdate()->first();
            if($old)abort_unless(hash_equals($old->descriptor_hash,$row['descriptor_hash'])&&$old->server_references===$row['server_references'],409);
            else DB::table('desktop_dashboard_print_ownership')->insert($row);
        }
        return $result;
    }
    /** Keep the durable local print identity, and separately bind its real central command's authenticated epoch. */
    private function originatingEnrollment(object $device,string $command): void
    {
        $review='هوية ربط أمر الطباعة الأصلي تحتاج مراجعة؛ نتيجة الطباعة وسجل العملية محفوظان دون إعادة تنفيذ.';
        abort_unless(Schema::hasTable('desktop_dashboard_commands'),503,$review);
        $row=DB::table('desktop_dashboard_commands')->where('device_id',$device->id)->where('command_id',$command)->lockForUpdate()->first();
        abort_unless($row,503,$review);
        try{$payload=json_decode(Crypt::decryptString($row->command_cipher),true,512,JSON_THROW_ON_ERROR);}
        catch(\Throwable $error){abort(503,$review);}
        $binding=$payload['enrollment_binding']??null;$origin=$payload['envelope']??null;
        abort_unless(is_array($binding)&&array_keys($binding)===['format','fingerprint']&&$binding['format']===1
            &&is_string($binding['fingerprint'])&&preg_match('/^[a-f0-9]{64}$/D',$binding['fingerprint'])
            &&is_array($origin)&&($origin['command_id']??null)===$command&&(int)($origin['actor_id']??0)===(int)$device->actor_id
            &&(int)$row->actor_id===(int)$device->actor_id,503,$review);
        abort_unless(hash_equals(app(DesktopDashboardDevices::class)->enrollmentFingerprint($device),$binding['fingerprint']),401,
            'أمر الطباعة الأصلي يخص ربطًا سابقًا للجهاز؛ راجع نتيجته المحفوظة قبل استكمال المزامنة.');
    }
    public function outcome(object $device,array $receipt): array
    {
        $keys=array_keys($receipt);sort($keys,SORT_STRING);$allowed=['effect_id','enrollment_id','origin_command_id','actor_id','branch','revision','claim_token','native_session_id','invocation_id','permit_hash','state','reason'];sort($allowed,SORT_STRING);abort_unless($keys===$allowed,422);
        Validator::make($receipt,['revision'=>'required|integer|min:1','claim_token'=>'required|uuid','native_session_id'=>'required|uuid','permit_hash'=>'required|regex:/^[a-f0-9]{64}$/D','reason'=>'present|string|max:200','effect_id'=>'required|regex:/^[a-f0-9]{64}$/D','enrollment_id'=>'required|regex:/^[a-f0-9]{64}$/D','origin_command_id'=>'required|uuid',
            'actor_id'=>'required|integer|min:1','branch'=>'required|string|max:40','invocation_id'=>'required|uuid','state'=>'required|in:invoked,review'])->validate();
        return DB::transaction(function()use($device,$receipt){
            $fresh=app(DesktopDashboardDevices::class)->lockAuthenticated($device);
            $actor=app(DesktopDashboardDevices::class)->actor($fresh);app(DesktopDashboardDevices::class)->branch($fresh,$receipt['branch'],$actor);
            $this->originatingEnrollment($fresh,$receipt['origin_command_id']);
            $row=DB::table('desktop_dashboard_print_ownership')->where('effect_id',$receipt['effect_id'])->lockForUpdate()->first();abort_unless($row,409);
            abort_unless($row->device_id===$device->id&&$row->command_id===$receipt['origin_command_id']&&$row->enrollment_id===$receipt['enrollment_id']
                &&(int)$row->actor_id===(int)$receipt['actor_id']&&$row->branch===$receipt['branch']&&(int)$row->revision===(int)$receipt['revision']&&(int)$fresh->actor_id===(int)$receipt['actor_id'],403);
            $branches=json_decode($fresh->branches,true,512,JSON_THROW_ON_ERROR);sort($branches,SORT_STRING);
            abort_unless(hash_equals($row->enrollment_id,$this->enrollmentIdentity($fresh,$branches)),409);
            $hash=$this->hash($receipt);if($row->outcome_hash!==null)abort_unless(hash_equals($row->outcome_hash,$hash),409);
            else DB::table('desktop_dashboard_print_ownership')->where('effect_id',$row->effect_id)->update(['outcome'=>json_encode($receipt),'outcome_hash'=>$hash,'updated_at'=>now('UTC')]);
            return ['effect_id'=>$row->effect_id,'invocation_id'=>$receipt['invocation_id'],'state'=>$receipt['state'],'receipt_sha256'=>$hash];
        });
    }
    private function hash(array $value): string
    {
        $sort=function($v)use(&$sort){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);return array_map($sort,$v);};
        return hash('sha256',json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
    }
    private function enrollmentIdentity(object $device,array $branches): string
    {
        if($device instanceof DesktopDashboardWorkspaceScope){$device->assertIssued();return $device->rootFingerprint();}
        return $this->hash(['device_id'=>$device->id,'actor_id'=>(int)$device->actor_id,'enrollment_hash'=>$device->enrollment_hash,
            'enrolled_at'=>(string)$device->enrolled_at,'branches'=>$branches]);
    }
}
