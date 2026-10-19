<?php
namespace App\Services\Dashboard;

use App\Services\GoServices\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PhoneDeliveryBoard
{
    private BranchOperations $ops;
    private PosServiceTicket $tickets;
    public function __construct(BranchOperations $ops, PosServiceTicket $tickets) {$this->ops=$ops; $this->tickets=$tickets;}
    private function ready(): void
    {
        abort_unless(Schema::hasTable('phone_delivery_batch_items') && Schema::hasTable('phone_delivery_dispatches'),503,'متابعة الدليفري تحتاج تحديث قاعدة البيانات.');
    }
    public function listing(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>['nullable','regex:/^(all|(f|gs):[1-9][0-9]{0,18})$/D'],'delivery_company_id'=>'nullable|integer|min:1','preparing_page'=>'nullable|integer|min:1','courier_page'=>'nullable|integer|min:1','finished_page'=>'nullable|integer|min:1'])->validate();
        $this->ready(); $scope=$v['branch']??'all';$branches=$this->ops->branches($scope,$actor);$branchValues=array_column($branches,'value');$columns=[];
        $base=DB::table('pos_service_tickets as t')->leftJoin('phone_delivery_dispatches as d','d.ticket_id','=','t.id')->whereIn('t.branch',$branchValues)->where('t.channel','phone');
        if(!empty($v['delivery_company_id'])) $base->whereRaw('COALESCE(d.company_id,t.delivery_company_id) = ?',[$v['delivery_company_id']]);
        foreach(['preparing','courier','finished'] as $stage) {
            $q=clone $base;
            if($stage==='preparing')$q->where('t.payment_status','unpaid')->whereIn('t.status',['new','preparing']);
            elseif($stage==='courier')$q->where('t.payment_status','unpaid')->whereIn('t.status',['out_for_delivery','finished']); // legacy finished-but-uncollected orders
            else $q->where(function($w){$w->where('t.payment_status','paid')->orWhere('t.status','cancelled');});
            $count=(clone $q)->count(); $last=max(1,(int)ceil($count/20)); $page=min((int)($v[$stage.'_page']??1),$last);
            $rows=$q->select('t.*','d.company_snapshot as handoff_company')->orderBy('t.id',$stage==='finished'?'desc':'asc')->offset(($page-1)*20)->limit(20)->get();
            $batches=DB::table('phone_delivery_batch_items')->whereIn('ticket_id',$rows->pluck('id'))->pluck('batch_id','ticket_id');
            $completed=$stage==='finished'?$this->completionTimes($rows->all()):[];
            $presented = $this->tickets->presentMany($rows->all(), false, $actor);
            $items = $rows->map(function ($row, $index) use ($batches, $presented, $completed) {
                $item = $presented[$index];
                $item['courier_company']=json_decode($row->handoff_company??$row->delivery_company_snapshot??'null',true);
                $item['batch_print_url']=isset($batches[$row->id])?route('phone-orders.batch-print',['id'=>$batches[$row->id]]):null;
                $item['completed_at']=$completed[(int)$row->id]??null;
                return $item;
            })->all();
            $columns[$stage]=['items'=>$items,'pagination'=>['page'=>$page,'last_page'=>$last,'total'=>$count]];
        }
        $companies=DB::table('branch_delivery_companies')->whereIn('branch',$branchValues)->where('active',true)->orderBy('name')->get(['id','name','branch'])->map(fn($r)=>['id'=>(int)$r->id,'name'=>$r->name,'branch'=>$r->branch])->all();
        return ['success'=>true,'branch'=>$scope,'columns'=>$columns,'companies'=>$companies];
    }

    /** Page-scoped audit reads; later edits and polling never change the recorded completion time. */
    private function completionTimes(array $rows): array
    {
        $paid=[];$cancelled=[];$branches=[];
        foreach($rows as $row){
            $branches[]=$row->branch;
            if($row->payment_status==='paid'&&!empty($row->paid_order_id))$paid[]=(int)$row->paid_order_id;
            elseif($row->status==='cancelled')$cancelled[]=(int)$row->id;
        }
        $receipts=[];
        if($paid)foreach(DB::table('takeaway_orders')->whereIn('id',array_unique($paid))->whereIn('branch',array_unique($branches))->where('channel','phone')
            ->get(['id','ticket_id','branch','channel','created_at']) as $receipt)$receipts[(int)$receipt->id]=$receipt;
        $commands=[];
        if($cancelled){
            $query=DB::table('pos_service_commands as c')->join('pos_service_tickets as t',function($join){
                $join->on('t.id','=','c.ticket_id')->on('t.branch','=','c.branch')->on('t.revision','=','c.revision');
            })->whereIn('t.id',$cancelled)->whereIn('t.branch',array_unique($branches))
                ->where('t.channel','phone')->where('t.status','cancelled')->where('c.kind','action');
            foreach($query->get(['c.ticket_id','c.created_at']) as $command)$commands[(int)$command->ticket_id][]=$command;
        }
        $times=[];
        foreach($rows as $row){
            $time=null;
            if($row->payment_status==='paid'){
                $receipt=$receipts[(int)($row->paid_order_id??0)]??null;
                // The receipt and paid ticket are linked in the same settlement transaction.
                if($receipt&&(int)$receipt->ticket_id===(int)$row->id&&$receipt->branch===$row->branch&&$receipt->channel==='phone')$time=$receipt->created_at;
            }elseif($row->status==='cancelled'&&count($commands[(int)$row->id]??[])===1){
                // Cancellation is terminal, so its sole current-revision action is the transition audit.
                $time=$commands[(int)$row->id][0]->created_at;
            }
            $completed=$this->auditTime($time);$created=$this->auditTime($row->created_at??null);
            $times[(int)$row->id]=$completed&&(!$created||!$completed->lt($created))?$completed->toIso8601String():null;
        }
        return $times;
    }

    private function auditTime($value): ?Carbon
    {
        // Persisted POS audit timestamps are UTC. Never interpret a blank or free-form value as now.
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2} [0-2]\d:[0-5]\d:[0-5]\d(?:\.\d{1,6})?$/D',$value))return null;
        try{$time=Carbon::parse($value,'UTC');}
        catch(\Throwable $error){return null;}
        return $time->format('Y-m-d H:i:s')===substr($value,0,19)?$time:null;
    }
    public function dispatch(array $values,$actor): array
    {
        $v=Validator::make($values,$this->ops->rules()+['ticket_id'=>'required|integer|min:1','company_id'=>'required|integer|min:1'])->validate(); $this->ready();
        return $this->ops->write('phone.dispatch',$v,$actor,function($branch,$actor)use($v){
            $row=DB::table('pos_service_tickets')->where('id',$v['ticket_id'])->where('branch',$branch['value'])->where('channel','phone')->lockForUpdate()->first();
            abort_unless($row,404); $this->ops->revision($row,$v);
            abort_unless($row->payment_status==='unpaid'&&in_array($row->status,['new','preparing'],true),409,'الطلب انتقل من مرحلة التجهيز. حدّث القائمة.');
            abort_unless(count(json_decode($row->cart_snapshot,true)['items']??[]),422,'الفاتورة فارغة.');
            $company=app(DeliveryCompanies::class)->snapshot($v['company_id'],$branch['value']);
            DB::table('phone_delivery_dispatches')->insert(['ticket_id'=>$row->id,'branch'=>$branch['value'],'company_id'=>$company['id'],'company_snapshot'=>json_encode($company,JSON_UNESCAPED_UNICODE),'actor_id'=>$actor->id,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            // Do not change customer, company printed on an issued bill, cart, quote, or bill lock.
            DB::table('pos_service_tickets')->where('id',$row->id)->update(['status'=>'out_for_delivery','revision'=>(int)$row->revision+1,'updated_at'=>now('UTC')]);
            return ['operation'=>['kind'=>'phone.dispatch','idempotency_key'=>$v['idempotency_key']],'branch'=>$branch['value'],'ticket_id'=>(int)$row->id,'revision'=>(int)$row->revision+1,'company'=>$company];
        });
    }
    public function finish(array $values,$actor,bool $requireServiceConsent=false): array
    {
        $v=Validator::make($values,$this->ops->rules()+[
            'items'=>'required|array|min:1|max:50','items.*.id'=>'required|integer|min:1|distinct','items.*.revision'=>'required|integer|min:1','items.*.quote_hash'=>'required|string|size:64',
            'items.*.service_consent'=>'nullable|boolean','items.*.marketing_consent'=>'nullable|boolean',
            'courier_name'=>'nullable|string|max:100','total'=>'required|string|max:14','payment_method'=>'required|in:cash,card,mobile_wallet,other','cash_received'=>'nullable|string|max:14','payment_reference'=>'nullable|string|max:150','payment_confirmed'=>'required|accepted',
        ])->validate(); $this->ready();
        $expected=$this->ops->money($v['total']); $received=$v['payment_method']==='cash'?$this->ops->money($v['cash_received']??''):0;
        // Keep the legacy argument compatible; follow-up permission is recorded only at order composition.
        return $this->ops->write('phone.batch',$v,$actor,function($branch,$actor)use($v,$expected,$received){
            abort_unless($v['payment_method']==='cash',422,'الدفع متاح كاش فقط.');
            $requested=collect($v['items'])->keyBy('id');
            $rows=DB::table('pos_service_tickets')->where('branch',$branch['value'])->where('channel','phone')->whereIn('id',$requested->keys())->orderBy('id')->lockForUpdate()->get();
            abort_unless($rows->count()===$requested->count(),404);
            $dispatches=DB::table('phone_delivery_dispatches')->whereIn('ticket_id',$requested->keys())->get()->keyBy('ticket_id');
            $total=0; $company=null;
            foreach($rows as $row){
                abort_unless($row->payment_status==='unpaid'&&in_array($row->status,['out_for_delivery','finished'],true),409,'أحد الطلبات تم تحصيله أو تغيرت مرحلته. حدّث القائمة.');
                $this->ops->revision($row,['expected_revision'=>$requested[$row->id]['revision']]);
                $quote=json_decode($row->quote_snapshot,true);
                abort_unless(!empty($quote['quote_hash'])&&hash_equals($quote['quote_hash'],$requested[$row->id]['quote_hash']),409,'تغيرت قيمة أحد الطلبات.');
                $assigned=json_decode($dispatches[$row->id]->company_snapshot??$row->delivery_company_snapshot??'null',true);
                abort_unless($assigned&&isset($assigned['id']),422,'يجب إسناد الطلب إلى شركة توصيل أولًا.');
                if($company)abort_unless($company['id']===$assigned['id'],422,'اختر طلبات شركة واحدة لمندوب واحد.');
                else $company=$assigned;
                $total+=Money::minor($quote['total']);
            }
            abort_unless($total===$expected,409,'راجع إجمالي الطلبات المحددة.');
            abort_if($v['payment_method']==='cash'&&$received<$total,422,'النقد المستلم أقل من إجمالي الطلبات.');
            $lines=[];
            foreach($rows as $row){
                $quote=json_decode($row->quote_snapshot,true);
                $result=$this->tickets->settle('phone',(int)$row->id,['branch'=>$branch['value'],'expected_revision'=>(int)$row->revision,'idempotency_key'=>(string)Str::uuid(),'quote_hash'=>$quote['quote_hash'],'payment_method'=>$v['payment_method'],'cash_received'=>$v['payment_method']==='cash'?$quote['total']:null,'payment_confirmed'=>true,'payment_reference'=>$v['payment_reference']??''],$actor);
                $lines[]=['ticket_id'=>(int)$row->id,'order_id'=>(int)$result['receipt']['id'],'total'=>$quote['total']];
            }
            $snapshot=['branch'=>$branch,'cashier'=>['id'=>(int)$actor->id,'name'=>$actor->name],'company'=>$company,'courier_name'=>trim($v['courier_name']??''),'created_at'=>now('Africa/Cairo')->toIso8601String(),'items'=>$lines,'total'=>Money::decimal($total),'payment_method'=>$v['payment_method'],'cash_received'=>Money::decimal($received),'change'=>Money::decimal($v['payment_method']==='cash'?$received-$total:0),'payment_reference'=>$v['payment_reference']??'','idempotency_key'=>$v['idempotency_key']];
            $id=DB::table('phone_delivery_batches')->insertGetId(['branch'=>$branch['value'],'actor_id'=>$actor->id,'request_key'=>$v['idempotency_key'],'total_cents'=>$total,'snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            foreach($lines as $line)DB::table('phone_delivery_batch_items')->insert(['batch_id'=>$id,'ticket_id'=>$line['ticket_id'],'order_id'=>$line['order_id'],'total_cents'=>Money::minor($line['total'])]);
            return ['operation'=>['kind'=>'phone.batch','idempotency_key'=>$v['idempotency_key']],'batch'=>$snapshot+['id'=>(int)$id,'print_url'=>route('phone-orders.batch-print',['id'=>$id])]];
        });
    }
    public function receipt(int $id,$actor): array
    {
        $this->ready(); $row=DB::table('phone_delivery_batches')->where('id',$id)->first(); abort_unless($row,404);
        $this->ops->access->branch($row->branch,$actor);
        return json_decode($row->snapshot,true)+['id'=>(int)$row->id];
    }
}

