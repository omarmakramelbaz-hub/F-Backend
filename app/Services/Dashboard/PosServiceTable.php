<?php

namespace App\Services\Dashboard;

use App\Services\GoServices\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Owner-configured physical tables; no example tables are inserted on deployment. */
class PosServiceTable
{
    private TakeawayAccess $access;
    public function __construct(TakeawayAccess $access){$this->access=$access;}
    public function ready(): bool {return $this->access->ready()&&Schema::hasTable('pos_service_tickets')&&Schema::hasTable('pos_service_commands')&&Schema::hasTable('pos_service_settings')
        &&Schema::hasTable('pos_service_tables')&&Schema::hasTable('pos_service_kitchen_tickets')&&Schema::hasColumn('takeaway_orders','channel')&&Schema::hasColumn('takeaway_orders','tenders_snapshot');}
    public function requireReady(): void {abort_unless($this->ready(),503,'قنوات الخدمة لم تُجهز بعد.');}

    public function policy(string $branch,bool $lock=false): array
    {
        $this->requireReady();
        if($lock) DB::table('pos_service_settings')->insertOrIgnore(['branch'=>$branch,'service_bps'=>0,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        $query=DB::table('pos_service_settings')->where('branch',$branch);if($lock)$query->lockForUpdate();$row=$query->first();
        return ['service_bps'=>(int)($row->service_bps??0),'service_rate'=>Money::decimal((int)($row->service_bps??0)),'settings_revision'=>(int)($row->revision??1)];
    }
    public function listing(string $value,$actor): array
    {
        $this->requireReady();$branch=$this->access->branch($value,$actor);$tables=[];
        foreach(DB::table('pos_service_tables')->where('branch',$value)->orderBy('id')->get() as $row)$tables[]=$this->present($row);
        return ['success'=>true,'branch'=>$branch,'tables'=>$tables,'policy'=>$this->policy($value),'permissions'=>$this->access->permissions($actor)];
    }
    public function present(object $row): array
    {
        $ticket=$row->active_ticket_id?DB::table('pos_service_tickets')->where('id',$row->active_ticket_id)->where('branch',$row->branch)->where('channel','dine')->where('table_id',$row->id)->first():null;
        return ['id'=>(int)$row->id,'branch'=>$row->branch,'name'=>$row->name,'capacity'=>(int)$row->capacity,'active'=>(bool)$row->active,
            'revision'=>(int)$row->revision,'status'=>$ticket?($ticket->status==='awaiting_bill'?'awaiting_bill':'in_service'):'free',
            'ticket_id'=>$ticket?(int)$ticket->id:null,'ticket'=>$ticket?app(PosServiceTicket::class)->present($ticket,false):null];
    }
    public function configure(array $values,$actor,bool $setting=false): array
    {
        $rules=['branch'=>['required','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],'idempotency_key'=>'required|uuid','expected_revision'=>'nullable|integer|min:1'];
        $rules+=$setting?['service_rate'=>'required|string|max:6','note'=>'required|string|max:500']:['id'=>'nullable|integer|min:1','name'=>'required|string|max:100','capacity'=>'required|integer|min:1|max:200','active'=>'required|boolean'];
        $v=Validator::make($values,$rules)->validate();$v['name']=trim($v['name']??'');$v['note']=trim($v['note']??'');
        if(($setting&&$v['note']==='')||(!$setting&&$v['name']===''))throw ValidationException::withMessages([$setting?'note':'name'=>'اكتب قيمة واضحة.']);
        if($setting){try{$bps=Money::rate($v['service_rate']);}catch(\InvalidArgumentException $e){throw ValidationException::withMessages(['service_rate'=>'النسبة من صفر إلى ١٠٠ بمنزلتين عشريتين.']);}}
        else $bps=0;
        $hash=PosServiceTicket::fingerprint([$setting?'setting':'table',$v]);$this->requireReady();
        return DB::transaction(function()use($v,$actor,$setting,$bps,$hash){
            $actor=$this->access->actor($actor);$branch=$this->access->branch($v['branch'],$actor,true);abort_unless($this->access->permissions($actor)['can_manage'],403);
            $old=DB::table('pos_service_commands')->where('branch',$v['branch'])->where('actor_id',$actor->id)->where('request_key',$v['idempotency_key'])->first();
            if($old){abort_unless(hash_equals($old->request_hash,$hash),409,'رقم العملية مستخدم لطلب مختلف.');return $this->configurationResult($v['branch'],$actor,$old,true);}
            $when=now('UTC');$tableId=null;$meta=[];
            if($setting){
                $policy=$this->policy($v['branch'],true);abort_unless(isset($v['expected_revision'])&&(int)$v['expected_revision']===$policy['settings_revision'],409,'تغير إعداد الخدمة.');
                $revision=$policy['settings_revision']+1;$meta=['previous_service_bps'=>$policy['service_bps'],'service_bps'=>$bps,'note'=>$v['note']];
                DB::table('pos_service_settings')->where('branch',$v['branch'])->update(['service_bps'=>$bps,'revision'=>$revision,'updated_at'=>$when]);
            }else{
                $row=!empty($v['id'])?DB::table('pos_service_tables')->where('branch',$v['branch'])->where('id',$v['id'])->lockForUpdate()->first():null;
                if(!empty($v['id']))abort_unless($row,404);
                if($row){abort_unless(isset($v['expected_revision'])&&(int)$v['expected_revision']===(int)$row->revision,409,'تغيرت الطاولة.');abort_unless(!$row->active_ticket_id,409,'الطاولة مشغولة؛ أغلق الفاتورة أولًا.');}
                abort_unless(!DB::table('pos_service_tables')->where('branch',$v['branch'])->where('name',$v['name'])->when($row,fn($q)=>$q->where('id','!=',$row->id))->exists(),409,'اسم الطاولة مستخدم.');
                $revision=$row?(int)$row->revision+1:1;$data=['branch'=>$v['branch'],'name'=>$v['name'],'capacity'=>$v['capacity'],'active'=>$v['active'],'revision'=>$revision,'updated_at'=>$when];
                if($row){$tableId=(int)$row->id;DB::table('pos_service_tables')->where('id',$tableId)->update($data);}else{$tableId=DB::table('pos_service_tables')->insertGetId($data+['created_at'=>$when]);}
                $meta=['name'=>$v['name'],'capacity'=>$v['capacity'],'active'=>$v['active']];
            }
            $opId=DB::table('pos_service_commands')->insertGetId(['branch'=>$v['branch'],'actor_id'=>$actor->id,'request_key'=>$v['idempotency_key'],'request_hash'=>$hash,
                'kind'=>$setting?'service_setting':'table_config','table_id'=>$tableId,'ticket_id'=>null,'revision'=>$revision,'metadata'=>json_encode($meta,JSON_UNESCAPED_UNICODE),'created_at'=>$when,'updated_at'=>$when]);
            return $this->configurationResult($branch['value'],$actor,DB::table('pos_service_commands')->where('id',$opId)->first(),false);
        },3);
    }
    private function configurationResult(string $branch,$actor,object $op,bool $replayed): array
    {
        $row=$op->table_id?DB::table('pos_service_tables')->where('branch',$branch)->where('id',$op->table_id)->first():null;
        return $this->listing($branch,$actor)+['table'=>$row?$this->present($row):null,'replayed'=>$replayed,
            'operation'=>['id'=>(int)$op->id,'kind'=>$op->kind,'idempotency_key'=>$op->request_key,'table_id'=>$op->table_id?(int)$op->table_id:null,'revision'=>(int)$op->revision]];
    }
    public function recovered(object $op,$actor): array {$this->access->branch($op->branch,$actor);return $this->configurationResult($op->branch,$actor,$op,true);}
}
