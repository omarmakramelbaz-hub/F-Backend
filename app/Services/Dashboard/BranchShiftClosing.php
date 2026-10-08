<?php
namespace App\Services\Dashboard;

use App\Services\GoServices\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Immutable branch shift snapshots. Closing resets the recorded drawer for the next shift. */
class BranchShiftClosing
{
    private TakeawayAccess $access;
    public function __construct(TakeawayAccess $access){$this->access=$access;}
    public function branches($actor): array {return array_values(array_filter($this->access->branches($actor),fn($b)=>$b['kind']==='f'));}
    private function branch(string $value,$actor,bool $lock=false): array
    {
        abort_unless(Schema::hasTable('branch_shift_sources')&&Schema::hasTable('branch_expense_commands')&&$this->access->ready(),503,'صفحة تقفيل الوردية تحتاج تحديث قاعدة البيانات.');
        $branch=$this->access->branch($value,$actor,$lock);abort_unless($branch['kind']==='f',404);return $branch;
    }
    /** Owner-only live expected cash, using exactly the shift reconciliation formula. */
    public function ownerBalances(array $branches,$actor): array
    {
        $actor=$this->access->actor($actor);
        abort_unless((int)$actor->id===1&&$actor->account_type==='admin'&&empty($actor->owner_resturant_id),403);
        $out=[];
        foreach($branches as $value){$branch=$this->branch($value,$actor);$built=$this->build($branch,$this->latest($value));$out[$value]=['expected_cents'=>$built['expected'],'as_of'=>now('Africa/Cairo')->toIso8601String()];}
        return $out;
    }
    private function latest(string $branch){return DB::table('branch_shift_closings')->where('branch',$branch)->orderByDesc('sequence')->first();}
    private function unclaimed($q,string $branch,string $source,string $column)
    {
        return $q->whereNotExists(function($s)use($branch,$source,$column){$s->selectRaw('1')->from('branch_shift_sources')->where('branch',$branch)->where('source',$source)->whereColumn('source_id',$column);});
    }
    public function data(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:40'])->validate();$branch=$this->branch($v['branch'],$actor);$last=$this->latest($branch['value']);$built=$this->build($branch,$last);
        $report=$built['snapshot'];$visible=array_intersect_key($report,array_flip(['branch','started_at','closed_at','pending_expenses','unsettled_app_cash']));
        $history=DB::table('branch_shift_closings')->where('branch',$branch['value'])->orderByDesc('sequence')->limit(30)->get()->map(fn($r)=>$this->stub($r))->all();
        return ['success'=>true,'previous_closing_id'=>$last?(int)$last->id:0,'review_token'=>$this->token($built),'report'=>$visible,'history'=>$history];
    }
    private function cents($amount): int
    {
        try{return Money::minor($amount??'0');}catch(\InvalidArgumentException $e){abort(409,'هناك مبلغ غير صالح في بيانات الوردية. راجع الفاتورة قبل الإغلاق.');}
    }
    private function token(array $built): string
    {
        $snapshot=$built['snapshot'];unset($snapshot['closed_at']);
        return hash_hmac('sha256',json_encode([$snapshot,$built['sources'],$built['till_balance']],JSON_UNESCAPED_UNICODE),(string)config('app.key'));
    }
    private function appAmount(object $row): array
    {
        $lines=DB::table('carts')->where('order_id',$row->id)->get();$subtotal=0;
        foreach($lines as $line){
            if(!empty($line->updated_total)){$subtotal+=$this->cents($line->updated_total);continue;}
            $quantity=(string)($line->qty??0);abort_unless(preg_match('/^\d{1,6}(?:\.\d{1,3})?$/D',$quantity),409,'كمية طلب التطبيق غير صالحة.');$parts=explode('.',$quantity);$millis=(int)$parts[0]*1000+(int)str_pad($parts[1]??'',3,'0');$subtotal+=intdiv($this->cents($line->price??0)*$millis+500,1000);
        }
        if(!$lines->count())$subtotal=$this->cents($row->total_price??0);
        $rate=DB::table('settings')->where('name','service_fees')->value('payload');$percent=$rate===null?'0':json_decode($rate,true);$bps=Money::rate(is_scalar($percent)?(string)$percent:'0');
        $delivery=$this->cents($row->delivery_price??0);$tax=$this->cents($row->user_tax??0);$total=$subtotal+$delivery+$tax+Money::commission($subtotal,$bps);
        abort_unless($subtotal>=0&&$delivery>=0&&$total>=0,409,'راجع قيم طلب التطبيق.');return [$total,$delivery];
    }
    private function build(array $branch,$last): array
    {
        $value=$branch['value'];$now=now('UTC');$activated=$last?$last->activated_at:OperatingDay::start()->utc()->toDateTimeString();$started=$last?$last->closed_at:$activated;
        $till=DB::table('takeaway_tills')->where('branch',$value)->first();$balance=(int)($till->balance_cents??0);
        $lastSnapshot=$last?json_decode($last->snapshot,true):[];
        // Historical closes keep their saved receipt; their captured till is the
        // boundary. New closes record an explicit reset and a zero next anchor.
        $anchor=$last?(int)($lastSnapshot['till_reset']['after_cents']??$last->till_balance_cents):0;
        $opening=$last?0:$balance-(int)DB::table('takeaway_till_entries')->where('branch',$value)->where('created_at','>=',$activated)->sum('amount_cents');
        $movement=$last?$balance-$anchor:$balance-$opening;
        $channels=array_fill_keys(['dine','takeaway','phone','app'],['count'=>0,'gross_cents'=>0,'delivery_cents'=>0]);$sources=[];$cashDelivery=0;$appCash=0;$cashSales=0;$nonCash=0;$appOutside=0;$appPending=0;
        $receipts=$this->unclaimed(DB::table('takeaway_orders')->where('branch',$value)->where('created_at','>=',$activated),$value,'pos','takeaway_orders.id')->orderBy('id')->get();
        foreach($receipts as $row){$kind=in_array($row->channel,['dine','phone'],true)?$row->channel:'takeaway';$total=(int)$row->total_cents;$delivery=(int)$row->delivery_cents;$channels[$kind]['count']++;$channels[$kind]['gross_cents']+=$total;$channels[$kind]['delivery_cents']+=$delivery;$sources[]=['source'=>'pos','source_id'=>$row->id];
            $cash=$row->payment_method==='cash'?$total:0;if($row->payment_method==='mixed')foreach(json_decode($row->tenders_snapshot??'[]',true)?:[] as $part)if($part['method']==='cash')$cash+=(int)$part['amount_cents'];
            $cashSales+=$cash;$nonCash+=$total-$cash;$cashDelivery+=$row->payment_method==='cash'?$delivery:0;
        }
        // App orders use their own settlement stream. Vendor-collected cash is distinct
        // from delegate/electronic receipts; a later cash handover can be recognized once.
        $appRows=DB::table('orders')->where('resturant_id',$branch['id'])->where('type','current')->where('status','completed')->where('updated_at','>=',$activated)->where(function($q)use($value){$this->unclaimed($q,$value,'app','orders.id');$q->orWhere(function($cash)use($value){$cash->where('payment_type','cash')->where('transfer_price_by','vendor');$this->unclaimed($cash,$value,'app_cash','orders.id');});})->orderBy('id')->get();
        $claimed=DB::table('branch_shift_sources')->where('branch',$value)->whereIn('source',['app','app_cash'])->whereIn('source_id',$appRows->pluck('id'))->get()->groupBy('source')->map(fn($rows)=>array_fill_keys($rows->pluck('source_id')->all(),true));
        foreach($appRows as $row){$saleClaimed=isset(($claimed['app']??[])[$row->id]);$cashClaimed=isset(($claimed['app_cash']??[])[$row->id]);$vendorCash=$row->payment_type==='cash'&&($row->transfer_price_by??null)==='vendor';if($saleClaimed&&(!$vendorCash||$cashClaimed))continue;[$total,$delivery]=$this->appAmount($row);
            if(!$saleClaimed){$channels['app']['count']++;$channels['app']['gross_cents']+=$total;$channels['app']['delivery_cents']+=$delivery;$sources[]=['source'=>'app','source_id'=>$row->id];if(!$vendorCash)$appOutside+=$total-$delivery;}
            if($vendorCash&&!$cashClaimed){$appCash+=$total-$delivery;$sources[]=['source'=>'app_cash','source_id'=>$row->id];}
            if($row->payment_type==='cash'&&($row->transfer_price_by??null)===null)$appPending++;
        }
        $expenses=0;$cashExpenses=0;
        $commands=$this->unclaimed(DB::table('branch_expense_commands')->where('branch',$value)->where('created_at','>=',$activated),$value,'expense','branch_expense_commands.id')->orderBy('id')->get();
        foreach($commands as $command){$row=json_decode($command->snapshot,true);$sign=($row['status']??'')==='approved'?1:($command->kind==='void'?-1:0);if(!$sign)continue;$amount=$sign*(int)$row['amount_cents'];$expenses+=$amount;if($row['payment_method']==='cash')$cashExpenses+=$amount;$sources[]=['source'=>'expense','source_id'=>$command->id];}
        $gross=0;$delivery=0;$present=[];foreach($channels as $kind=>$totals){$gross+=$totals['gross_cents'];$delivery+=$totals['delivery_cents'];$present[$kind]=['count'=>$totals['count'],'gross'=>Money::decimal($totals['gross_cents']),'delivery'=>Money::decimal($totals['delivery_cents']),'net'=>Money::decimal($totals['gross_cents']-$totals['delivery_cents'])];}
        $expected=$opening+$movement+$appCash-$cashDelivery;
        $snapshot=['branch'=>$branch,'started_at'=>$this->time($started),'closed_at'=>$this->time($now),'channels'=>$present,'sales_total'=>Money::decimal($gross),'delivery_total'=>Money::decimal($delivery),'expenses_total'=>Money::decimal($expenses),'net_sales'=>Money::decimal($gross-$delivery-$expenses),'opening_cash'=>Money::decimal($opening),'cash_sales'=>Money::decimal($cashSales),'noncash_sales'=>Money::decimal($nonCash),'app_branch_cash'=>Money::decimal($appCash),'app_outside_drawer'=>Money::decimal($appOutside),'cash_delivery'=>Money::decimal($cashDelivery),'cash_expenses'=>Money::decimal($cashExpenses),'other_cash_movements'=>Money::decimal($movement-$cashSales+$cashExpenses),'expected_cash'=>Money::decimal($expected),'pending_expenses'=>DB::table('branch_expenses')->where('branch',$value)->where('status','pending')->count(),'unsettled_app_cash'=>$appPending];
        return ['snapshot'=>$snapshot,'sources'=>$sources,'expected'=>$expected,'till_balance'=>$balance,'activated_at'=>$activated,'started_at'=>$started,'closed_at'=>$now];
    }
    public function close(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:40','idempotency_key'=>'required|uuid','previous_closing_id'=>'required|integer|min:0','review_token'=>'required|string|size:64','counted_cash'=>'required|string|max:14','notes'=>'nullable|string|max:1000'])->validate();$counted=$this->cents($v['counted_cash']);abort_unless($counted>=0&&$counted<=100000000000,422,'أدخل الكاش الفعلي بقيمة صحيحة.');$v['counted_cash']=Money::decimal($counted);$v['notes']=trim($v['notes']??'');$hash=PosServiceTicket::fingerprint($v);$actor=$this->access->actor($actor);abort_unless($this->access->permissions($actor)['can_checkout'],403);
        return DB::transaction(function()use($v,$hash,$counted,$actor){$branch=$this->branch($v['branch'],$actor,true);$old=DB::table('branch_shift_closings')->where('branch',$v['branch'])->where('actor_id',$actor->id)->where('request_key',$v['idempotency_key'])->first();if($old){abort_unless(hash_equals($old->request_hash,$hash),409,'رقم الإغلاق مستخدم لبيانات مختلفة.');return ['success'=>true,'replayed'=>true,'closing'=>$this->stub($old)];}
            $last=$this->latest($v['branch']);abort_unless((int)($last->id??0)===(int)$v['previous_closing_id'],409,'تم إغلاق وردية من جهاز آخر. حدّث الصفحة وراجع الفترة الجديدة.');
            DB::table('takeaway_tills')->insertOrIgnore(['branch'=>$v['branch'],'balance_cents'=>0,'tax_bps'=>0,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            $till=DB::table('takeaway_tills')->where('branch',$v['branch'])->lockForUpdate()->first();
            $built=$this->build($branch,$last);abort_unless(hash_equals($this->token($built),$v['review_token']),409,'تغيرت تحصيلات أو مصروفات الوردية أثناء العدّ. تم تحديث البيانات؛ أعد عدّ النقدية قبل الإغلاق.');$snapshot=$built['snapshot'];$snapshot+=['counted_cash'=>Money::decimal($counted),'variance'=>Money::decimal($counted-$built['expected']),'variance_label'=>$counted===$built['expected']?'مطابق':($counted>$built['expected']?'زيادة':'عجز'),'cashier'=>['id'=>(int)$actor->id,'name'=>(string)$actor->name],'notes'=>$v['notes']];
            $snapshot['next_shift_opening_cash']='0.00';$snapshot['till_reset']=['before_cents'=>$built['till_balance'],'after_cents'=>0];
            $id=DB::table('branch_shift_closings')->insertGetId(['branch'=>$v['branch'],'sequence'=>(int)($last->sequence??0)+1,'actor_id'=>$actor->id,'request_key'=>$v['idempotency_key'],'request_hash'=>$hash,'activated_at'=>$built['activated_at'],'started_at'=>$built['started_at'],'closed_at'=>$built['closed_at'],'counted_cents'=>$counted,'expected_cents'=>$built['expected'],'variance_cents'=>$counted-$built['expected'],'till_balance_cents'=>$built['till_balance'],'snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),'notes'=>$v['notes'],'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            foreach(array_chunk($built['sources'],500) as $chunk)DB::table('branch_shift_sources')->insert(array_map(fn($s)=>$s+['closing_id'=>$id,'branch'=>$v['branch']],$chunk));
            DB::table('takeaway_tills')->where('id',$till->id)->update(['balance_cents'=>0,'revision'=>(int)$till->revision+1,'updated_at'=>now('UTC')]);
            DB::table('takeaway_till_entries')->insert(['till_id'=>$till->id,'branch'=>$v['branch'],'actor_id'=>$actor->id,'request_key'=>(string)\Illuminate\Support\Str::uuid(),'request_hash'=>PosServiceTicket::fingerprint(['shift_close',$id]),'kind'=>'shift_close','amount_cents'=>-$built['till_balance'],'balance_cents'=>0,'business_date'=>OperatingDay::date(),'note'=>'تصفير الدرج بعد إغلاق الوردية SHIFT-'.str_pad((string)$id,6,'0',STR_PAD_LEFT),'metadata'=>json_encode(['closing_id'=>$id]),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return ['success'=>true,'replayed'=>false,'closing'=>$this->stub(DB::table('branch_shift_closings')->where('id',$id)->first())];
        },3);
    }
    public function recover(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:40','idempotency_key'=>'required|uuid'])->validate();$this->branch($v['branch'],$actor);$row=DB::table('branch_shift_closings')->where('branch',$v['branch'])->where('actor_id',$actor->id)->where('request_key',$v['idempotency_key'])->first();return ['success'=>true,'found'=>(bool)$row,'closing'=>$row?$this->stub($row):null];
    }
    public function receipt(int $id,$actor): array
    {
        $row=DB::table('branch_shift_closings')->where('id',$id)->first();abort_unless($row,404);$this->branch($row->branch,$actor);return json_decode($row->snapshot,true)+$this->stub($row);
    }
    private function stub(object $row): array {return ['id'=>(int)$row->id,'number'=>'SHIFT-'.str_pad((string)$row->id,6,'0',STR_PAD_LEFT),'sequence'=>(int)$row->sequence,'branch'=>$row->branch,'started_at'=>$this->time($row->started_at),'closed_at'=>$this->time($row->closed_at),'print_url'=>route('branch-shifts.print',['id'=>$row->id])];}
    private function time($time): string {return Carbon::parse($time,'UTC')->setTimezone('Africa/Cairo')->format('Y-m-d H:i:s');}
}
