<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Schema};

/** Guard CRM side effects of existing offline operational writes before any business mutation. */
class DesktopDashboardCustomerFacts
{
    private static ?array $replayIdentities=null;
    public const ROUTES=['customers.save','takeaway.checkout','dining.save','phone-orders.save',
        'dining.action','phone-orders.action','dining.settle','phone-orders.settle','phone-orders.finish-batch'];
    private const COLUMNS=['id','phone','phone_key','name','address','area','delivery_notes','latitude','longitude',
        'service_consent','marketing_consent','service_consented_at','marketing_consented_at',
        'service_consent_evidence','marketing_consent_evidence','service_consented_by','marketing_consented_by','opted_out_at'];
    public static function handles(?string $route): bool {return in_array($route,self::ROUTES,true);}
    private function available(): bool
    {
        return Schema::hasTable('crm_customers')&&Schema::hasTable('crm_customer_sources')&&Schema::hasTable('crm_purchases');
    }
    private function phones(string $route,array $payload): array
    {
        $v=$payload['values']??[];$values=[];
        if(in_array($route,['customers.save','takeaway.checkout','dining.save','phone-orders.save'],true))
            $values[]=$v[$route==='customers.save'?'phone':'customer_phone']??null;
        elseif(in_array($route,['dining.settle','phone-orders.settle'],true)
            ||($route==='phone-orders.action'&&($v['action']??null)==='send_kitchen')){
            $id=$payload['parameters']['id']??null;
            if(is_numeric($id)){
                $phone=DB::table('pos_service_tickets')->where('branch',$v['branch']??'')->where('id',$id)
                    ->where('channel',str_starts_with($route,'dining.')?'dine':'phone')->lockForUpdate()->value('customer_phone');
                $values[]=$route==='dining.settle'?($v['customer_phone']??$phone):$phone;
            }
        }elseif($route==='phone-orders.finish-batch'){
            $ids=array_column($v['items']??[],'id');
            foreach($ids as $id)if(is_numeric($id))$values[]=DB::table('pos_service_tickets')->where('branch',$v['branch']??'')
                ->where('channel','phone')->where('id',$id)->lockForUpdate()->value('customer_phone');
        }
        $phones=[];
        foreach($values as $phone)if(is_string($phone)&&($phone=CustomerDirectory::normalizePhone($phone))!==null)$phones[$phone]=true;
        $phones=array_keys($phones);sort($phones,SORT_STRING);return $phones;
    }
    public function capture(string $route,array $payload): array
    {
        abort_unless(self::handles($route)&&DB::transactionLevel()>0,503);
        if(!$this->available())return ['version'=>1,'available'=>false,'targets'=>[]];
        $targets=[];$branch=$payload['values']['branch']??'';
        foreach($this->phones($route,$payload) as $phone){
            $customer=DB::table('crm_customers')->where('phone',$phone)->lockForUpdate()->first(self::COLUMNS);
            $sources=$customer?DB::table('crm_customer_sources')->where('customer_id',$customer->id)->where('branch',$branch)
                ->orderBy('channel')->lockForUpdate()->get(['branch','channel'])->map(fn($r)=>(array)$r)->all():[];
            $targets[]=['phone'=>$phone,'customer'=>$customer?(array)$customer:null,'sources'=>$sources];
        }
        return ['version'=>1,'available'=>true,'targets'=>$targets];
    }
    private function facts(array $payload): array
    {
        $facts=$payload['facts']['customer_directory']??null;
        abort_unless(is_array($facts)&&count($facts)===3&&array_diff(array_keys($facts),['version','available','targets'])===[]
            &&$facts['version']===1&&is_bool($facts['available'])&&is_array($facts['targets'])&&count($facts['targets'])<=200,409,
            'مصادر العميل وقت العملية غير مكتملة؛ العملية المحلية محفوظة للمراجعة.');
        $seen=[];$branch=$payload['values']['branch']??'';
        foreach($facts['targets'] as $target){
            abort_unless(is_array($target)&&count($target)===3&&array_diff(array_keys($target),['phone','customer','sources'])===[]
                &&is_string($target['phone'])&&CustomerDirectory::normalizePhone($target['phone'])===$target['phone']
                &&!isset($seen[$target['phone']])&&is_array($target['sources']),409);
            $seen[$target['phone']]=true;$row=$target['customer'];
            if($row!==null){
                abort_unless(is_array($row)&&count($row)===count(self::COLUMNS)&&array_diff(array_keys($row),self::COLUMNS)===[]
                    &&is_numeric($row['id'])&&(int)$row['id']>0&&$row['phone']===$target['phone']
                    &&$row['phone_key']===CustomerDirectory::phoneKey($target['phone']),409);
            }else abort_unless($target['sources']===[],409);
            foreach($target['sources'] as $source)abort_unless(is_array($source)&&count($source)===2
                &&($source['branch']??null)===$branch&&in_array($source['channel']??null,['takeaway','dine','app','delivery','whatsapp'],true),409);
        }
        abort_unless($facts['available']||$facts['targets']===[],409);return $facts;
    }
    private function positivePurposes(string $route,array $payload): array
    {
        $v=$payload['values'];$purposes=[];
        if(in_array($route,['takeaway.checkout','dining.save','dining.settle','phone-orders.save'],true)
            ||($route==='phone-orders.action'&&($v['action']??null)==='send_kitchen'))
            foreach(['service','marketing'] as $purpose)if(in_array($v[$purpose.'_consent']??null,[true,1,'1'],true))$purposes[]=$purpose;
        return $purposes;
    }
    /** Timestamp/evidence equality cannot prove the order of identical withdrawals within one DB second. */
    public function guardReopening(string $route,array $payload): void
    {
        $purposes=$this->positivePurposes($route,$payload);
        if(!$purposes)return;
        foreach($this->facts($payload)['targets'] as $target){
            $row=$target['customer'];if($row===null)continue;
            foreach($purposes as $purpose){
                $recorded=false;
                foreach(['_consented_at','_consent_evidence','_consented_by'] as $suffix)
                    if($row[$purpose.$suffix]!==null&&$row[$purpose.$suffix]!=='')$recorded=true;
                abort_if(!empty($row['opted_out_at'])||(!in_array($row[$purpose.'_consent'],[true,1,'1'],true)&&$recorded),409,
                    'إعادة تفعيل موافقة رسائل أُوقفت تتطلب مراجعة من الداشبورد المتصل قبل تنفيذ هذه العملية.');
            }
        }
    }
    public function guard(string $route,array $payload): void
    {
        $this->guardReopening($route,$payload);
        $before=$this->facts($payload);$current=$this->capture($route,$payload);
        $project=function(array $facts)use($route,$payload){
            $purposes=$this->positivePurposes($route,$payload);
            // Capturing a profile or collecting a phone ticket does not renew consent.
            // A later STOP remains authoritative while that original collection commits.
            foreach($facts['targets'] as &$target)if($target['customer']!==null){
                foreach(['service','marketing'] as $purpose)if(!in_array($purpose,$purposes,true))
                    foreach(['_consent','_consented_at','_consent_evidence','_consented_by'] as $suffix)unset($target['customer'][$purpose.$suffix]);
                if(!$purposes)unset($target['customer']['opted_out_at']);
            }
            unset($target);return $facts;
        };
        abort_unless(app(DesktopDashboardJournal::class)->fingerprint($project($before))===app(DesktopDashboardJournal::class)->fingerprint($project($current)),409,
            'بيانات العميل أو موافقته تغيرت على السيرفر؛ البيع المحلي محفوظ للمراجعة دون تعديل العميل أو الخزنة.');
    }
    public function replay(array $payload,callable $work): array
    {
        $previous=self::$replayIdentities;$expected=[];
        foreach($this->facts($payload)['targets'] as $target)$expected[$target['phone']]=$target['customer']['id']??null;
        self::$replayIdentities=$expected;
        try{return $work();}finally{self::$replayIdentities=$previous;}
    }
    /** INSERT ownership also protects absent-phone collisions at READ COMMITTED. */
    public static function captured(string $phone,int $inserted,object $customer): void
    {
        if(self::$replayIdentities===null)return;
        abort_unless(array_key_exists($phone,self::$replayIdentities),409,
            'مصدر عميل إضافي غير موجود في العملية المحلية؛ العملية محفوظة للمراجعة.');
        $expected=self::$replayIdentities[$phone];
        abort_unless($expected===null?$inserted===1:(int)$customer->id===(int)$expected,409,
            'ظهر سجل آخر لنفس رقم العميل؛ البيع المحلي محفوظ للمراجعة دون دمج بيانات العميل.');
        self::$replayIdentities[$phone]=(int)$customer->id;
    }
    public function inputs(array $payload,callable $reference): array
    {
        foreach($payload['facts']['customer_directory']['targets']??[] as $i=>$target){
            $row=$target['customer'];if($row===null)continue;
            if(!is_array($row['id']))$payload['facts']['customer_directory']['targets'][$i]['customer']['id']=$reference('crm_customer',$row['id']);
            foreach(['service_consent_evidence','marketing_consent_evidence'] as $field){
                $evidence=$row[$field]??null;
                if(is_string($evidence)&&preg_match('/^(pos-checkout:|pos-ticket:(?:phone|dine):)([1-9][0-9]*)$/D',$evidence,$m)){
                    $mapped=$reference($m[1]==='pos-checkout:'?'receipt':'ticket',(int)$m[2]);
                    if(is_array($mapped))$payload['facts']['customer_directory']['targets'][$i]['customer'][$field]
                        =['$desktop_crm_evidence'=>['prefix'=>$m[1],'reference'=>$mapped]];
                }
            }
        }
        return $payload;
    }
    public function validateReferences(array $payload): void
    {
        foreach($payload['facts']['customer_directory']['targets']??[] as $target){
            $row=$target['customer']??null;if(!is_array($row))continue;
            if(is_array($row['id']??null)){
                $ref=$row['id'];abort_unless(count($ref)===1&&is_array($ref['$desktop_ref']??null)
                    &&preg_match('/^crm_customer(?:\.[0-9]{1,3})?$/D',$ref['$desktop_ref']['entity']??''),422,'نوع مرجع العميل الموحد غير صحيح.');
            }
        }
    }
    public function outputs(array $payload): array
    {
        if(!$this->available())return [];
        $targets=$payload['facts']['customer_directory']['targets']??[];$outputs=[];
        foreach($targets as $i=>$target){
            $row=DB::table('crm_customers')->where('phone',$target['phone'])->first(['id']);
            if($row&&DB::table('crm_customer_sources')->where('customer_id',$row->id)->where('branch',$payload['values']['branch']??'')->exists())
                $outputs[count($targets)===1?'crm_customer':'crm_customer.'.$i]=(int)$row->id;
        }
        return $outputs;
    }
}
