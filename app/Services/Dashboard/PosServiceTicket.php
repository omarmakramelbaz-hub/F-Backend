<?php

namespace App\Services\Dashboard;

use App\Services\GoServices\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Revisioned unpaid dine/phone tickets. Final payment uses the shared register in one transaction. */
class PosServiceTicket
{
    private TakeawayAccess $access;private TakeawayService $sales;private PosServiceTable $tables;
    public function __construct(TakeawayAccess $access,TakeawayService $sales,PosServiceTable $tables){$this->access=$access;$this->sales=$sales;$this->tables=$tables;}
    public static function fingerprint(array $value): string
    {
        $sort=function($v)use(&$sort){if(!is_array($v))return $v;$list=$v===[]||array_keys($v)===range(0,count($v)-1);if(!$list)ksort($v);foreach($v as $k=>$item)$v[$k]=$sort($item);return $v;};
        return hash('sha256',json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    public function summary(string $channel,string $branch,$actor): array
    {
        $this->channel($channel);$data=$this->sales->summary($branch,$actor);$data['ready']=$data['ready']&&$this->tables->ready();
        $policy=$this->tables->ready()?$this->tables->policy($branch):['service_rate'=>'0.00','settings_revision'=>1];
        $data['policy']=array_merge($data['policy']??[],['service_rate'=>$channel==='dine'?$policy['service_rate']:'0.00','settings_revision'=>$policy['settings_revision'],'channel'=>$channel]);
        if($data['ready']){$paid=DB::table('takeaway_orders')->where('branch',$branch)->where('channel',$channel)->where('business_date',OperatingDay::date());
            $data['today']['count']=(clone $paid)->count();$data['today']['total']=Money::decimal((int)(clone $paid)->sum('total_cents'));}
        return $data;
    }
    public function quote(string $channel,array $values,$actor): array
    {
        $this->channel($channel);$this->tables->requireReady();$branch=$this->access->branch((string)($values['branch']??''),$actor);
        if(!empty($values['ticket_id'])&&!array_key_exists('items',$values)){
            $row=$this->row($channel,(int)$values['ticket_id'],$branch['value']);abort_unless($row->status!=='cancelled',409);
            return json_decode($row->quote_snapshot,true)+['ticket_id'=>(int)$row->id,'revision'=>(int)$row->revision];
        }
        $delivery=$channel==='phone'?$this->delivery($values,$actor)['cents']:0;
        return $this->sales->quote($values,$actor,$this->pricingContext($channel,$branch['value'],$delivery));
    }
    public function listing(string $channel,array $values,$actor): array
    {
        $this->channel($channel);$this->tables->requireReady();$branch=$this->access->branch($values['branch'],$actor);
        $query=DB::table('pos_service_tickets')->where('branch',$branch['value'])->where('channel',$channel);
        if(!empty($values['delivery_company_id']))$query->where('delivery_company_id',$values['delivery_company_id']);
        if(!empty($values['status'])&&$values['status']!=='all')$query->where('status',$values['status']);
        if(!empty($values['search']))$query->where(function($q)use($values){$q->where('customer_name','like','%'.$values['search'].'%')->orWhere('customer_phone','like','%'.$values['search'].'%')->orWhere('waiter_name','like','%'.$values['search'].'%');});
        $total=(clone $query)->count();$per=(int)($values['per_page']??20);$last=max(1,(int)ceil($total/$per));$page=min($last,(int)($values['page']??1));
        $items=[];foreach($query->orderByDesc('id')->offset(($page-1)*$per)->limit($per)->get() as $row)$items[]=$this->present($row,false);
        return ['success'=>true,'branch'=>$branch,'items'=>$items,'tickets'=>$items,'pagination'=>['page'=>$page,'last_page'=>$last,'per_page'=>$per,'total'=>$total]]+$this->summary($channel,$branch['value'],$actor);
    }
    public function show(string $channel,int $id,$actor): array
    {
        $this->tables->requireReady();$row=DB::table('pos_service_tickets')->where('id',$id)->where('channel',$channel)->first();abort_unless($row,404);$this->access->branch($row->branch,$actor);
        return ['success'=>true,'ticket'=>$this->present($row)];
    }
    public function save(string $channel,array $values,$actor): array
    {
        $this->channel($channel);$rules=$this->commandRules()+['ticket_id'=>'nullable|integer|min:1','table_id'=>'nullable|integer|min:1','expected_revision'=>'nullable|integer|min:1',
            'items'=>'present|array|max:100','discount'=>'nullable|string|max:14','discount_reason'=>'nullable|string|max:500','quote_hash'=>'nullable|string|size:64','reprice'=>'nullable|boolean',
            'notes'=>'nullable|string|max:500','send_to_kitchen'=>'nullable|boolean'];
        $rules+=$channel==='dine'?['customer_name'=>'nullable|string|max:100','waiter_name'=>'required|string|max:100','guest_count'=>'required|integer|min:1|max:200']:
            ['customer_name'=>'required|string|max:100','customer_phone'=>['required','string','max:30','regex:/^[+0-9 ()-]{6,30}$/D'],'address'=>'required|string|max:500','area'=>'nullable|string|max:150','delivery_notes'=>'nullable|string|max:500','delivery_fee'=>'nullable|string|max:14','delivery_fee_manual'=>'nullable|boolean','latitude'=>'required|numeric|between:-90,90','longitude'=>'required|numeric|between:-180,180','location_confirmed'=>'required|accepted','delivery_quote_hash'=>'required|string|size:64','customer_id'=>'nullable|integer|min:1','delivery_company_id'=>'nullable|integer|min:1'];
        $v=Validator::make($values,$rules)->validate();foreach(['waiter_name','customer_name','customer_phone','address','area','delivery_notes','notes','discount_reason'] as $key)if(isset($v[$key]))$v[$key]=trim($v[$key]);
        foreach($channel==='dine'?['waiter_name']:['customer_name','address'] as $key)if($v[$key]==='')throw ValidationException::withMessages([$key=>'هذا الحقل مطلوب.']);
        $delivery=0;
        $phoneKey=$channel==='phone'?BranchCustomers::phoneKey($v['customer_phone']):null;if($channel==='phone')abort_unless(preg_match('/^\+?[0-9]{6,20}$/D',$phoneKey),422,'رقم الهاتف غير صالح.');
        $hash=self::fingerprint([$channel,'save',$v]);$this->tables->requireReady();
        return DB::transaction(function()use($channel,$v,$actor,$hash,$delivery,$phoneKey){
            $actor=$this->access->actor($actor);$branch=$this->access->branch($v['branch'],$actor,true);abort_unless($this->access->permissions($actor)['can_checkout'],403);
            if($old=$this->command($v['branch'],$actor->id,$v['idempotency_key']))return $this->replay($old,$hash,$actor);
            $deliveryData=null;$company=null;
            if($channel==='phone'){
                app(BranchOperations::class)->ready();$deliveryData=$this->delivery($v,$actor);$delivery=$deliveryData['cents'];
                $company=app(DeliveryCompanies::class)->snapshot($v['delivery_company_id']??null,$v['branch']);
                if(!empty($v['customer_id']))abort_unless(DB::table('branch_customers')->where('branch',$v['branch'])->where('id',$v['customer_id'])->where('phone_key',$phoneKey)->exists(),422,'اختر بيانات العميل من الفرع الحالي.');
            }
            $existing=!empty($v['ticket_id']);$row=$existing?$this->locked($channel,(int)$v['ticket_id'],$v['branch']):null;$table=null;
            if($row){$this->mutable($row);abort_unless(isset($v['expected_revision'])&&(int)$v['expected_revision']===(int)$row->revision,409,'تغيرت الفاتورة.');}
            if($row&&$row->last_kitchen_id)abort_unless(count($v['items'])>0,409,'لا يمكن تفريغ فاتورة أُرسلت للمطبخ.');
            if($channel==='dine'){
                $tableId=$row?(int)$row->table_id:(int)($v['table_id']??0);$table=DB::table('pos_service_tables')->where('branch',$v['branch'])->where('id',$tableId)->lockForUpdate()->first();
                abort_unless($table&&$table->active,422,'اختر طاولة فعلية متاحة.');
                abort_unless($row?(int)$table->active_ticket_id===(int)$row->id:!$table->active_ticket_id,409,'الطاولة مشغولة.');
                abort_unless((int)$v['guest_count']<=(int)$table->capacity,422,'عدد الضيوف أكبر من سعة الطاولة.');
            }else abort_unless(count($v['items'])>0,422,'أضف أصناف الطلب.');
            $context=$this->pricingContext($channel,$branch['value'],$delivery,true);
            if(count($v['items'])){
                $savedCart=$row?json_decode($row->cart_snapshot,true):null;
                $same=$row&&count($savedCart['items'])>0&&!(bool)($v['reprice']??false)&&$delivery===(int)$row->delivery_cents
                    &&self::fingerprint($this->sales->canonicalCart($v))===self::fingerprint($this->sales->canonicalCart($savedCart));
                if($same)$q=$this->sales->quote($savedCart,$actor,['channel'=>$channel,'ticket_id'=>(int)$row->id,'saved_quote'=>json_decode($row->quote_snapshot,true)]);
                else{$q=$this->sales->quote($v,$actor,$context,true);abort_unless(!empty($v['quote_hash'])&&hash_equals($q['quote_hash'],$v['quote_hash']),409,'تغير السعر أو الإعداد. راجع الإجمالي.');}
                $cart=['branch'=>$v['branch'],'items'=>array_map(fn($line)=>['product_id'=>$line['product_id'],'quantity'=>$line['quantity'],'quantity_mode'=>$line['quantity_mode'],'option_id'=>$line['option_id']],$q['items']),
                    'discount'=>$q['discount'],'discount_reason'=>$v['discount_reason']??''];
            }else{
                abort_unless(empty($v['discount'])||$this->money($v['discount'],'discount')===0,422);
                $q=$this->emptyQuote($branch,$context,$actor);$cart=['branch'=>$v['branch'],'items'=>[],'discount'=>'0.00','discount_reason'=>''];
            }
            $when=now('UTC');$data=['branch'=>$v['branch'],'channel'=>$channel,'table_id'=>$table?(int)$table->id:null,
                'table_snapshot'=>$table?json_encode(['id'=>(int)$table->id,'name'=>$table->name,'capacity'=>(int)$table->capacity],JSON_UNESCAPED_UNICODE):null,
                'waiter_name'=>$v['waiter_name']??null,'guest_count'=>$v['guest_count']??null,'customer_name'=>$v['customer_name']??null,
                'customer_phone'=>$v['customer_phone']??null,'phone_key'=>$phoneKey,'address'=>$v['address']??null,'area'=>$v['area']??null,'delivery_notes'=>$v['delivery_notes']??null,
                'delivery_cents'=>$delivery,'notes'=>$v['notes']??null,'cart_snapshot'=>json_encode($cart,JSON_UNESCAPED_UNICODE),'quote_snapshot'=>json_encode($q,JSON_UNESCAPED_UNICODE),
                'revision'=>$row?(int)$row->revision+1:1,'updated_at'=>$when];
            if($channel==='phone')$data+=['customer_id'=>$v['customer_id']??null,'delivery_company_id'=>$company['id']??null,'delivery_company_snapshot'=>$company?json_encode($company,JSON_UNESCAPED_UNICODE):null,'delivery_snapshot'=>json_encode($deliveryData['snapshot'])];
            if($row){$id=(int)$row->id;DB::table('pos_service_tickets')->where('id',$id)->update($data);}
            else{$id=DB::table('pos_service_tickets')->insertGetId($data+['actor_id'=>$actor->id,'status'=>$channel==='dine'?'open':'new','payment_status'=>'unpaid','business_date'=>OperatingDay::date($when),'created_at'=>$when]);}
            if($table)DB::table('pos_service_tables')->where('id',$table->id)->update(['active_ticket_id'=>$id,'revision'=>(int)$table->revision+1,'updated_at'=>$when]);
            if($channel==='phone'&&($v['send_to_kitchen']??false))$this->queueKitchen($id,$v['branch'],$actor->id,$data['revision'],$when);
            $op=$this->record($v,$actor,$hash,'save',$id,$data['revision']);return $this->result($op,$actor,false);
        },3);
    }
    public function action(string $channel,int $id,array $values,$actor): array
    {
        $allowed=$channel==='dine'?'send_kitchen,request_bill,cancel':'send_kitchen,request_bill,prepare,dispatch,finish,cancel';
        $v=Validator::make($values,$this->commandRules()+['expected_revision'=>'required|integer|min:1','action'=>'required|in:'.$allowed,'reason'=>'nullable|string|max:500'])->validate();
        if($v['action']==='cancel'&&trim($v['reason']??'')==='')throw ValidationException::withMessages(['reason'=>'اكتب سبب الإلغاء.']);
        $hash=self::fingerprint([$channel,$id,'action',$v]);$this->tables->requireReady();
        return DB::transaction(function()use($channel,$id,$v,$actor,$hash){
            $actor=$this->access->actor($actor);$this->access->branch($v['branch'],$actor,true);abort_unless($this->access->permissions($actor)['can_checkout'],403);
            if($old=$this->command($v['branch'],$actor->id,$v['idempotency_key']))return $this->replay($old,$hash,$actor);
            $row=$this->locked($channel,$id,$v['branch']);$this->mutable($row);abort_unless((int)$v['expected_revision']===(int)$row->revision,409,'تغيرت الفاتورة.');
            $cart=json_decode($row->cart_snapshot,true);if($v['action']!=='cancel')abort_unless(count($cart['items']),422,'الفاتورة فارغة.');
            $status=$row->status;$revision=(int)$row->revision+1;$when=now('UTC');$changes=['revision'=>$revision,'updated_at'=>$when];$meta=[];
            if($v['action']==='cancel'){abort_unless(!$row->last_kitchen_id,409,'لا يمكن إلغاء فاتورة أُرسلت للمطبخ.');$status='cancelled';$changes['cancel_reason']=trim($v['reason']);}
            elseif($v['action']==='send_kitchen'){$status=$channel==='dine'?'preparing':($row->status==='new'?'preparing':$row->status);}
            elseif($v['action']==='request_bill'){
                if($channel==='dine')$status='awaiting_bill';
                $changes+=['bill_issued_at'=>$when,'bill_issued_by'=>$actor->id,'bill_issued_revision'=>$revision];
                $meta['bill_issued']=true;
            }
            else{
                $from=['prepare'=>['new'],'dispatch'=>['new','preparing'],'finish'=>['out_for_delivery']];
                abort_unless(in_array($row->status,$from[$v['action']],true),409,'المرحلة غير متاحة.');$status=['prepare'=>'preparing','dispatch'=>'out_for_delivery','finish'=>'finished'][$v['action']];
            }
            $changes['status']=$status;DB::table('pos_service_tickets')->where('id',$id)->update($changes);
            if($v['action']==='send_kitchen'){
                $snapshot=$this->present($this->row($channel,$id,$v['branch']));$kitchenId=DB::table('pos_service_kitchen_tickets')->insertGetId(['branch'=>$v['branch'],'ticket_id'=>$id,'revision'=>$revision,'actor_id'=>$actor->id,
                    'snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),'created_at'=>$when,'updated_at'=>$when]);
                DB::table('pos_service_tickets')->where('id',$id)->update(['last_kitchen_id'=>$kitchenId]);$meta['kitchen_id']=$kitchenId;
                if($channel==='phone')app(PosBranchPrinting::class)->enqueue($v['branch'],$id,$kitchenId);
            }
            if($status==='cancelled')$this->release($row,$when);
            $op=$this->record($v,$actor,$hash,'action',$id,$revision,$meta);return $this->result($op,$actor,false);
        },3);
    }
    public function settle(string $channel,int $id,array $values,$actor): array
    {
        $v=Validator::make($values,$this->commandRules()+['expected_revision'=>'required|integer|min:1','quote_hash'=>'required|string|size:64',
            'payment_method'=>'required|string','cash_received'=>'nullable|string','payment_confirmed'=>'nullable|boolean','payment_reference'=>'nullable|string|max:150','tenders'=>'nullable|array'])->validate();
        if($channel==='phone')abort_unless(($v['payment_confirmed']??false)===true,422,'أكد تحصيل قيمة الطلب فعليًا قبل إنهائه.');
        $hash=self::fingerprint([$channel,$id,'settle',$v]);$this->tables->requireReady();
        return DB::transaction(function()use($channel,$id,$v,$actor,$hash){
            $actor=$this->access->actor($actor);$this->access->branch($v['branch'],$actor,true);abort_unless($this->access->permissions($actor)['can_checkout'],403);
            if($old=$this->command($v['branch'],$actor->id,$v['idempotency_key']))return $this->replay($old,$hash,$actor);
            abort_unless($v['payment_method']==='cash'&&empty($v['tenders']),422,'الدفع متاح كاش فقط.');
            $row=$this->locked($channel,$id,$v['branch']);$this->collectible($row);abort_unless((int)$v['expected_revision']===(int)$row->revision,409,'تغيرت الفاتورة.');
            $q=json_decode($row->quote_snapshot,true);abort_unless(!empty($q['quote_hash'])&&hash_equals($q['quote_hash'],$v['quote_hash']),409,'راجع الفاتورة المحفوظة.');
            $cart=json_decode($row->cart_snapshot,true);$snapshot=$this->contextSnapshot($row);$context=['channel'=>$channel,'ticket_id'=>$id,'saved_quote'=>$q,
                'branch_snapshot'=>$q['branch'],'snapshot'=>$snapshot];
            $payment=$this->sales->checkout($cart+$v+['notes'=>$row->notes??''],$actor,$context);$when=now('UTC');
            DB::table('pos_service_tickets')->where('id',$id)->update(['status'=>$channel==='dine'?'paid':'finished','payment_status'=>'paid','paid_order_id'=>$payment['receipt']['id'],'revision'=>(int)$row->revision+1,'updated_at'=>$when]);
            $this->release($row,$when);$op=$this->record($v,$actor,$hash,'settle',$id,(int)$row->revision+1,['order_id'=>$payment['receipt']['id']]);
            return $this->result($op,$actor,false)+['register'=>$payment['register'],'today'=>$payment['today']];
        },3);
    }
    public function recover(string $channel,array $values,$actor): array
    {
        $v=Validator::make($values,$this->commandRules())->validate();$actor=$this->access->actor($actor);$this->access->branch($v['branch'],$actor);$this->tables->requireReady();
        $op=$this->command($v['branch'],$actor->id,$v['idempotency_key']);if(!$op)return ['success'=>true,'found'=>false,'ticket'=>null,'receipt'=>null];
        if(!$op->ticket_id){abort_unless($channel==='dine',404);return $this->tables->recovered($op,$actor)+['found'=>true,'ticket'=>null,'receipt'=>null];}
        $row=$this->row($channel,(int)$op->ticket_id,$v['branch']);return $this->result($op,$actor,true)+['found'=>true];
    }
    public function kitchen(string $channel,int $id,$actor): array
    {
        $this->tables->requireReady();$row=DB::table('pos_service_kitchen_tickets')->where('id',$id)->first();abort_unless($row,404);$this->access->branch($row->branch,$actor);
        $snapshot=json_decode($row->snapshot,true);abort_unless($snapshot['channel']===$channel,404);
        return ['id'=>(int)$row->id,'ticket_id'=>(int)$row->ticket_id,'revision'=>(int)$row->revision,'created_at'=>$this->iso($row->created_at),'ticket'=>$snapshot];
    }
    public function present(object $row,bool $full=true): array
    {
        $q=json_decode($row->quote_snapshot,true);$cart=json_decode($row->cart_snapshot,true);$actor=DB::table('users')->where('id',$row->actor_id)->first();
        $prefix=$row->channel==='dine'?'dining':'phone-orders';
        $data=['id'=>(int)$row->id,'channel'=>$row->channel,'branch'=>$q['branch'],'revision'=>(int)$row->revision,'status'=>$row->status,'payment_status'=>$row->payment_status,
            'table'=>json_decode($row->table_snapshot??'null',true),'waiter_name'=>$row->waiter_name??'','guest_count'=>$row->guest_count?(int)$row->guest_count:null,
            'customer_name'=>$row->customer_name??'','customer_phone'=>$row->customer_phone??'','address'=>$row->address??'','area'=>$row->area??'','delivery_notes'=>$row->delivery_notes??'',
            'customer_id'=>isset($row->customer_id)?(int)$row->customer_id:null,'customer_revision'=>!empty($row->customer_id)?(int)DB::table('branch_customers')->where('branch',$row->branch)->where('id',$row->customer_id)->value('revision'):null,'delivery_company'=>json_decode($row->delivery_company_snapshot??'null',true),'delivery_location'=>json_decode($row->delivery_snapshot??'null',true),
            'delivery_fee'=>Money::decimal((int)$row->delivery_cents),'notes'=>$row->notes??'','cancel_reason'=>$row->cancel_reason??'',
            'created_at'=>$this->iso($row->created_at),'updated_at'=>$this->iso($row->updated_at),'cashier'=>['id'=>(int)$row->actor_id,'name'=>$actor->name??''],
            'kitchen_sent'=>(bool)$row->last_kitchen_id,'bill_locked'=>$this->billLocked($row),
            'bill_issued_at'=>!empty($row->bill_issued_at)?$this->iso($row->bill_issued_at):null,'bill_issued_by'=>isset($row->bill_issued_by)?(int)$row->bill_issued_by:null,
            'bill_issued_revision'=>isset($row->bill_issued_revision)?(int)$row->bill_issued_revision:null,
            'details_url'=>route($prefix.'.details',['id'=>$row->id]),
            'quote_hash'=>$q['quote_hash']??null,'discount_reason'=>$cart['discount_reason']??'','bill_print_url'=>route($prefix.'.print',['id'=>$row->id]),
            'kitchen_print_url'=>$row->last_kitchen_id?route($prefix.'.kitchen',['id'=>$row->last_kitchen_id]):null,
            'receipt_url'=>$row->paid_order_id?route('takeaway.print',['id'=>$row->paid_order_id]):null];
        foreach(['subtotal','discount','tax','tax_rate','service','service_rate','delivery','total'] as $key)$data[$key]=$q[$key]??'0.00';
        if($full){$data['items']=$q['items'];$data['cart']=$cart+['delivery_fee'=>Money::decimal((int)$row->delivery_cents)];}
        return $data;
    }
    private function queueKitchen(int $id,string $branch,int $actor,int $revision,$when): void
    {
        $snapshot=$this->present($this->row('phone',$id,$branch));
        $kitchenId=DB::table('pos_service_kitchen_tickets')->insertGetId(['branch'=>$branch,'ticket_id'=>$id,'revision'=>$revision,'actor_id'=>$actor,
            'snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),'created_at'=>$when,'updated_at'=>$when]);
        DB::table('pos_service_tickets')->where('id',$id)->update(['last_kitchen_id'=>$kitchenId]);
        app(PosBranchPrinting::class)->enqueue($branch,$id,$kitchenId);
    }
    private function delivery(array $values,$actor): array
    {
        $snapshot=app(PhoneDelivery::class)->quote($values,$actor)['delivery'];
        abort_unless(!empty($values['delivery_quote_hash'])&&hash_equals($snapshot['delivery_quote_hash'],$values['delivery_quote_hash']),409,'موقع العميل أو سعر التوصيل تغير. أكد الدبوس وراجع الخدمة.');
        $manual=Validator::make($values,['delivery_fee_manual'=>'nullable|boolean','delivery_fee'=>'required_if:delivery_fee_manual,1,true|nullable|string|max:14'])->validate();
        if($manual['delivery_fee_manual']??false){
            $actor=$this->access->actor($actor);abort_unless($this->access->permissions($actor)['can_checkout'],403);
            $fee=$this->money($manual['delivery_fee'],'delivery_fee');
            $snapshot['calculated_delivery_fee']=$snapshot['delivery_fee'];
            $snapshot['delivery_fee']=Money::decimal($fee);$snapshot['fee_mode']='manual';$snapshot['fee_actor_id']=(int)$actor->id;
        }
        return ['cents'=>Money::minor($snapshot['delivery_fee']),'snapshot'=>$snapshot];
    }
    private function pricingContext(string $channel,string $branch,int $delivery,bool $lock=false): array
    {
        $policy=$this->tables->policy($branch,$lock);return ['channel'=>$channel,'service_bps'=>$channel==='dine'?$policy['service_bps']:0,'delivery_cents'=>$delivery];
    }
    private function emptyQuote(array $branch,array $context,$actor): array
    {
        $summary=$this->sales->summary($branch['value'],$actor);$q=['success'=>true,'branch'=>$branch,'items'=>[],'quote_hash'=>null,'service_bps'=>$context['service_bps'],'service_cents'=>0,'delivery_cents'=>0,
            'tax_rate'=>$summary['policy']['tax_rate'],'service_rate'=>Money::decimal($context['service_bps'])];
        foreach(['subtotal','discount','tax','service','delivery','total'] as $key){$q[$key]='0.00';$q[$key.'_cents']=0;}return $q;
    }
    private function row(string $channel,int $id,string $branch): object {$row=DB::table('pos_service_tickets')->where('branch',$branch)->where('channel',$channel)->where('id',$id)->first();abort_unless($row,404);return $row;}
    private function locked(string $channel,int $id,string $branch): object
    {
        $hint=$this->row($channel,$id,$branch);if($hint->table_id)DB::table('pos_service_tables')->where('branch',$branch)->where('id',$hint->table_id)->lockForUpdate()->first();
        $row=DB::table('pos_service_tickets')->where('branch',$branch)->where('channel',$channel)->where('id',$id)->lockForUpdate()->first();abort_unless($row,404);return $row;
    }
    private function billLocked(object $row): bool {return !empty($row->bill_issued_at)||$row->status==='awaiting_bill';}
    private function mutable(object $row): void {$this->collectible($row);abort_unless(!$this->billLocked($row),409,'صدرت فاتورة الدفع؛ المتاح فقط إنهاء الفاتورة وتحصيل قيمتها.');}
    private function collectible(object $row): void {abort_unless($row->payment_status==='unpaid'&&$row->status!=='cancelled',409,'الفاتورة أُغلقت.');}
    private function release(object $row,$when): void {if($row->table_id)DB::table('pos_service_tables')->where('branch',$row->branch)->where('id',$row->table_id)->where('active_ticket_id',$row->id)->update(['active_ticket_id'=>null,'revision'=>DB::raw('revision + 1'),'updated_at'=>$when]);}
    private function command(string $branch,int $actor,string $key): ?object {return DB::table('pos_service_commands')->where('branch',$branch)->where('actor_id',$actor)->where('request_key',$key)->first();}
    private function record(array $v,$actor,string $hash,string $kind,int $ticket,int $revision,array $meta=[]): object
    {
        $id=DB::table('pos_service_commands')->insertGetId(['branch'=>$v['branch'],'actor_id'=>$actor->id,'request_key'=>$v['idempotency_key'],'request_hash'=>$hash,'kind'=>$kind,
            'ticket_id'=>$ticket,'revision'=>$revision,'metadata'=>json_encode($meta),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);return DB::table('pos_service_commands')->where('id',$id)->first();
    }
    private function replay(object $op,string $hash,$actor): array {abort_unless(hash_equals($op->request_hash,$hash),409,'رقم العملية مستخدم لطلب مختلف.');return $this->result($op,$actor,true);}
    private function result(object $op,$actor,bool $replayed): array
    {
        $row=DB::table('pos_service_tickets')->where('id',$op->ticket_id)->first();abort_unless($row&&$row->branch===$op->branch,404);$this->access->branch($row->branch,$actor);$meta=json_decode($op->metadata??'[]',true)?:[];
        $result=['success'=>true,'replayed'=>$replayed,'operation'=>['id'=>(int)$op->id,'kind'=>$op->kind,'idempotency_key'=>$op->request_key,'ticket_id'=>(int)$op->ticket_id,'revision'=>(int)$op->revision],
            'ticket'=>$this->present($row),'receipt'=>null,'receipt_url'=>null];
        if($row->channel==='phone'&&$row->last_kitchen_id)$result['print_queued']=true;
        if(!empty($meta['kitchen_id']))$result['kitchen_print_url']=route(($row->channel==='dine'?'dining':'phone-orders').'.kitchen',['id'=>$meta['kitchen_id']]);
        if($row->paid_order_id){$result['receipt']=$this->sales->receipt((int)$row->paid_order_id,$actor);$result['receipt_url']=$result['receipt']['receipt_url'];}return $result;
    }
    private function contextSnapshot(object $row): array {return ['delivery_company'=>json_decode($row->delivery_company_snapshot??'null',true),'delivery_location'=>json_decode($row->delivery_snapshot??'null',true),'table'=>json_decode($row->table_snapshot??'null',true),'table_name'=>json_decode($row->table_snapshot??'null',true)['name']??'',
        'waiter_name'=>$row->waiter_name??'','guest_count'=>$row->guest_count,'customer_name'=>$row->customer_name??'','customer_phone'=>$row->customer_phone??'','address'=>$row->address??'','area'=>$row->area??'','delivery_notes'=>$row->delivery_notes??''];}
    private function commandRules(): array {return ['branch'=>['required','string','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],'idempotency_key'=>'required|uuid'];}
    private function money($value,string $key): int {try{$n=Money::minor($value);}catch(\InvalidArgumentException $e){throw ValidationException::withMessages([$key=>'المبلغ بمنزلتين عشريتين.']);}abort_unless($n>=0&&$n<=100000000,422);return $n;}
    private function channel(string $channel): void {abort_unless(in_array($channel,['dine','phone'],true),404);}
    private function iso(string $time): string {return Carbon::parse($time,'UTC')->setTimezone(config('app.timezone'))->toIso8601String();}
}

