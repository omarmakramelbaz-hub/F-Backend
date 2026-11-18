<?php
namespace App\Services\Dashboard;

use App\Services\GoServices\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Immutable reconciliation reports. Closing preserves the drawer and carries its expected cash. */
class BranchShiftClosing
{
    private TakeawayAccess $access;
    public function __construct(TakeawayAccess $access){$this->access=$access;}
    public function canClose($actor): bool
    {
        try {$actor=$this->access->actor($actor);return $actor->account_type==='admin'&&empty($actor->owner_resturant_id);}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){return false;}
    }
    private function manager($actor) {abort_unless($this->canClose($actor),403,'تقفيل الوردية متاح للأونر والأدمن فقط.');return $this->access->actor($actor);}
    public function branches($actor): array {if(!$this->canClose($actor))return [];return array_values(array_filter($this->access->branches($actor),fn($b)=>$b['kind']==='f'));}
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
        return $q->whereNotExists(function($s)use($branch,$source,$column){
            $s->selectRaw('1')->from('branch_shift_sources')->where('source',$source)->whereColumn('source_id',$column);
            // An app order keeps one financial identity when its store is fulfilled
            // by Shubra. Saved claims at an old store must never be collected twice.
            if(!in_array($source,['app','app_cash'],true))$s->where('branch',$branch);
        });
    }
    public function data(array $values,$actor): array
    {
        $actor=$this->manager($actor);
        if(($values['branch']??null)==='all')return $this->allData($actor);
        $v=Validator::make($values,['branch'=>'required|string|max:40'])->validate();$branch=$this->branch($v['branch'],$actor);$last=$this->latest($branch['value']);$built=$this->build($branch,$last);
        $report=$built['snapshot'];$visible=array_intersect_key($report,array_flip(['branch','started_at','closed_at','pending_expenses','unsettled_app_cash','pending_invoices','pending_expense_items','pending_expenses_url']));
        $history=DB::table('branch_shift_closings')->where('branch',$branch['value'])->orderByDesc('sequence')->limit(30)->get()->map(fn($r)=>$this->stub($r))->all();
        return ['success'=>true,'previous_closing_id'=>$last?(int)$last->id:0,'review_token'=>$this->token($built),'report'=>$visible,'history'=>$history];
    }
    private function allData($actor): array
    {
        $actor=$this->access->actor($actor);abort_unless($actor->account_type==='admin'&&empty($actor->owner_resturant_id),403);
        $branches=$this->branches($actor);abort_unless($branches,403);$reports=[];$history=[];$pending=0;$unsettled=0;$invoices=$this->emptyPendingInvoices();$expenseItems=[];
        foreach($branches as $branch){
            $data=$this->data(['branch'=>$branch['value']],$actor);$reports[]=$data['report'];
            $expenseItems=array_merge($expenseItems,$data['report']['pending_expense_items']);
            $pending+=(int)$data['report']['pending_expenses'];$unsettled+=(int)$data['report']['unsettled_app_cash'];
            $invoices['total']+=$data['report']['pending_invoices']['total'];
            foreach($invoices['counts'] as $channel=>$count)$invoices['counts'][$channel]+=$data['report']['pending_invoices']['counts'][$channel];
            $invoices['items']=array_merge($invoices['items'],$data['report']['pending_invoices']['items']);
            foreach($data['history'] as $entry)$history[]=$entry+['branch_name'=>$branch['name']];
        }
        usort($history,fn($a,$b)=>$b['id']<=>$a['id']);
        // Aggregate previews deliberately omit review tokens and all monetary totals.
        return ['success'=>true,'all_branches'=>true,'report'=>['branch'=>['value'=>'all','name'=>'جميع الفروع'],'started_at'=>'—','pending_expenses'=>$pending,'unsettled_app_cash'=>$unsettled,'pending_invoices'=>$invoices,'pending_expense_items'=>$expenseItems,'pending_expenses_url'=>route('branch-expenses.index',['branch'=>'all','status'=>'pending'])],'branch_reports'=>$reports,'history'=>array_slice($history,0,30)];
    }
    /** Unreviewed expenses stay with their branch, including earlier operating days.
     * Expense create/review and closing all hold the same restaurant row lock first.
     */
    private function pendingExpenses(array $branch,bool $lock=false): array
    {
        $query=DB::table('branch_expenses')->where('branch',$branch['value'])->where('status','pending')->orderBy('id');
        if($lock)$query->lockForUpdate();
        $rows=$query->get(['id','occurred_on','description','category']);$items=[];
        foreach($rows as $row)$items[]=['id'=>(int)$row->id,'number'=>'EXP-'.str_pad((string)$row->id,6,'0',STR_PAD_LEFT),
            'occurred_on'=>(string)$row->occurred_on,'description'=>(string)$row->description,'category'=>(string)$row->category,'branch_name'=>$branch['name']];
        return ['total'=>$rows->count(),'items'=>$items];
    }
    private function emptyPendingInvoices(): array
    {
        return ['total'=>0,'counts'=>['dine'=>0,'phone'=>0,'app'=>0],'items'=>[]];
    }
    /** All unclosed invoices belong to their branch even when opened before this shift.
     * Only identifiers and workflow state leave this service; no totals or customer data.
     * Close calls use current locking reads inside the already-held branch transaction.
     */
    private function pendingInvoices(array $branch,bool $lock=false): array
    {
        $pending=$this->emptyPendingInvoices();$value=$branch['value'];
        if(Schema::hasTable('pos_service_tickets')){
            $columns=['id','channel','status','table_snapshot'];
            if(Schema::hasColumns('pos_service_tickets',['shift_sequence','shift_order_number']))$columns=array_merge($columns,['shift_sequence','shift_order_number']);
            $query=DB::table('pos_service_tickets')->where('branch',$value)->whereIn('channel',['dine','phone'])
                ->where(function($q){$q->whereNull('payment_status')->orWhere('payment_status','!=','paid');})
                ->where(function($q){$q->whereNull('status')->orWhereNotIn('status',['paid','cancelled','canceled']);})->orderBy('id');
            if($lock)$query->lockForUpdate();
            foreach($query->get($columns) as $row){
                $channel=$row->channel;$table=json_decode($row->table_snapshot??'null',true);
                $number=class_exists(BranchOrderSequence::class)?BranchOrderSequence::display($row,$value,$channel,'#'.$row->id):'#'.$row->id;
                $pending['items'][]=['source'=>$channel,'id'=>(int)$row->id,'number'=>$number,'label'=>$channel==='dine'?'الصالة':'الدليفري',
                    'status'=>(string)($row->status??''),'status_label'=>$this->pendingStatus($channel,(string)($row->status??'')),
                    'table_name'=>(string)($table['name']??''),'branch_name'=>$branch['name'],
                    'url'=>route(($channel==='dine'?'dining':'phone-orders').'.details',['id'=>$row->id])];
                ++$pending['counts'][$channel];
            }
        }
        if(Schema::hasTable('orders')){
            $sources=FasakhanstaStoreFulfillment::sourceRestaurantIdsForBranch((int)$branch['id']);
            $query=DB::table('orders')->whereIn('resturant_id',$sources)->where('type','current')->whereNotNull('status')
                ->whereNotIn('status',['','completed','cancelled','canceled','declined','rejected','expired','failed','draft'])->orderBy('id');
            if($lock)$query->lockForUpdate();
            $columns=['id','status','payment_type'];
            foreach(['order_no','accepted_notify','delegate_from_out'] as $column)if(Schema::hasColumn('orders',$column))$columns[]=$column;
            if(Schema::hasColumns('orders',['shift_sequence','shift_order_number']))$columns=array_merge($columns,['shift_sequence','shift_order_number']);
            $rows=$query->get($columns);$failed=$this->failedPendingAppPayments($rows,$lock);
            foreach($rows as $row){
                if(isset($failed[$row->id]))continue;
                $number=(string)($row->order_no??'');
                if($number==='')$number=class_exists(BranchOrderSequence::class)?BranchOrderSequence::display($row,$value,'app','#'.$row->id):'#'.$row->id;
                $status=$this->pendingStatus('app',(string)$row->status);
                if($row->status==='accepted'&&($row->delegate_from_out??'')==='in_resturant')$status='مع المندوب';
                elseif(in_array($row->status,['pending','another_delegate','new_order'],true)&&($row->accepted_notify??'')==='yes')$status='قيد التجهيز';
                $pending['items'][]=['source'=>'app','id'=>(int)$row->id,'number'=>$number,'label'=>'التطبيق',
                    'status'=>(string)$row->status,'status_label'=>$status,'table_name'=>'','branch_name'=>$branch['name'],
                    'url'=>route('order-board.details',['source'=>'legacy','id'=>$row->id])];
                ++$pending['counts']['app'];
            }
        }
        $pending['total']=array_sum($pending['counts']);return $pending;
    }
    /** Match the order board: an unfinished gateway checkout is not a failed payment.
     * A retained successful attempt overrides earlier failures; otherwise the latest
     * explicit failure (including old false+transaction callbacks) ends the checkout.
     */
    private function failedPendingAppPayments($rows,bool $lock): array
    {
        $methods=['online','v_cash','card','credit_card','mobile_wallet','paymob','paymob_card_payment','paymob_mobile_wallet_payment'];
        $ids=$rows->filter(fn($row)=>in_array($row->payment_type,$methods,true))->pluck('id')->all();
        if(!$ids||!Schema::hasTable('payments')||!Schema::hasColumns('payments',['id','order_id','status']))return [];
        $columns=['id','order_id','status'];if(Schema::hasColumn('payments','transaction_id'))$columns[]='transaction_id';
        $failed=[];
        foreach(array_chunk($ids,500) as $chunk){
            $query=DB::table('payments')->whereIn('order_id',$chunk)->orderByDesc('id');if($lock)$query->lockForUpdate();
            foreach($query->get($columns)->groupBy('order_id') as $orderId=>$attempts){
                $success=$attempts->contains(fn($attempt)=>in_array(strtolower(trim((string)$attempt->status)),['1','true','paid','succeeded','success'],true));
                if($success)continue;$latest=$attempts->first();$status=strtolower(trim((string)$latest->status));
                if($status==='failed'||in_array($status,['0','false'],true)&&!empty($latest->transaction_id))$failed[$orderId]=true;
            }
        }
        return $failed;
    }
    private function pendingStatus(string $channel,string $status): string
    {
        if($channel==='app')return ['pending'=>'جديد','another_delegate'=>'بانتظار مندوب','new_order'=>'جديد','accepted'=>'قيد التجهيز',
            'shipped'=>'مع المندوب','preparing'=>'قيد التجهيز','ready'=>'جاهز','out_for_delivery'=>'مع المندوب',
            'awaiting_payment'=>'بانتظار الدفع'][$status]??'طلب غير مغلق';
        return ['open'=>'مفتوحة','new'=>'جديد','preparing'=>'قيد التجهيز','awaiting_bill'=>'بانتظار التحصيل',
            'out_for_delivery'=>'مع المندوب','finished'=>'بانتظار التحصيل'][$status]??'فاتورة غير مغلقة';
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
    /** Internal sync facts stay in the encrypted outbox, never the cashier's page response. */
    public function desktopReview(array $values,$actor): array
    {
        $branch=$this->branch((string)($values['branch']??''),$actor);$last=$this->latest($branch['value']);$built=$this->build($branch,$last);
        $snapshot=$built['snapshot'];unset($snapshot['closed_at']);
        return ['previous_closing_id'=>(int)($last->id??0),'snapshot'=>$snapshot,'sources'=>$built['sources'],'till_balance'=>$built['till_balance']];
    }
    public function reconcileDesktop(array $values,array $review,$actor): array
    {
        return DB::transaction(function()use($values,$review,$actor){
            $branch=$this->branch((string)($values['branch']??''),$actor,true);
            DB::table('takeaway_tills')->where('branch',$branch['value'])->lockForUpdate()->first();
            $current=$this->desktopReview($values,$actor);
            abort_unless(hash_equals(PosServiceTicket::fingerprint($current),PosServiceTicket::fingerprint($review)),409,'مصادر أو أرصدة الوردية على السيرفر مختلفة؛ التقفيل المحلي محفوظ للمراجعة.');
            $last=$this->latest($branch['value']);$values['review_token']=$this->token($this->build($branch,$last));
            return $this->close($values,$actor);
        });
    }
    private function appAmount(object $row,?\Illuminate\Support\Collection $lines=null,?int &$bps=null): array
    {
        $lines=$lines??DB::table('carts')->where('order_id',$row->id)->get();$subtotal=0;
        foreach($lines as $line){
            if(!empty($line->updated_total)){$subtotal+=$this->cents($line->updated_total);continue;}
            $quantity=(string)($line->qty??0);abort_unless(preg_match('/^\d{1,6}(?:\.\d{1,3})?$/D',$quantity),409,'كمية طلب التطبيق غير صالحة.');$parts=explode('.',$quantity);$millis=(int)$parts[0]*1000+(int)str_pad($parts[1]??'',3,'0');$subtotal+=intdiv($this->cents($line->price??0)*$millis+500,1000);
        }
        if(!$lines->count())$subtotal=$this->cents($row->total_price??0);
        if($bps===null){
            $rate=DB::table('settings')->where('name','service_fees')->value('payload');$percent=$rate===null?'0':json_decode($rate,true);$bps=Money::rate(is_scalar($percent)?(string)$percent:'0');
        }
        $delivery=$this->cents($row->delivery_price??0);$tax=$this->cents($row->user_tax??0);$total=$subtotal+$delivery+$tax+Money::commission($subtotal,$bps);
        abort_unless($subtotal>=0&&$delivery>=0&&$total>=0,409,'راجع قيم طلب التطبيق.');return [$total,$delivery];
    }
    private function build(array $branch,$last): array
    {
        $value=$branch['value'];$now=now('UTC');$activated=$last?$last->activated_at:OperatingDay::start()->utc()->toDateTimeString();$started=$last?$last->closed_at:$activated;
        $till=DB::table('takeaway_tills')->where('branch',$value)->first();$balance=(int)($till->balance_cents??0);
        $lastSnapshot=$last?json_decode($last->snapshot,true):[];
        // Preserve old reset boundaries, but carry expected cash for report-only closes.
        // Raw POS cash is the movement anchor; app cash and delivery adjustments carry separately.
        $anchor=$last?(int)($lastSnapshot['till_reset']['after_cents']??$last->till_balance_cents):0;
        $opening=$last?(isset($lastSnapshot['till_reset'])?(int)$lastSnapshot['till_reset']['after_cents']:(int)$last->expected_cents):$balance-(int)DB::table('takeaway_till_entries')->where('branch',$value)->where('created_at','>=',$activated)->sum('amount_cents');
        $movement=$last?$balance-$anchor:$balance-$opening;
        $channels=array_fill_keys(['dine','takeaway','phone','app'],['count'=>0,'gross_cents'=>0,'delivery_cents'=>0]);$sources=[];$cashDelivery=0;$appCash=0;$cashSales=0;$nonCash=0;$appOutside=0;$appPending=0;
        $receipts=$this->unclaimed(DB::table('takeaway_orders')->where('branch',$value)->where('created_at','>=',$activated),$value,'pos','takeaway_orders.id')->orderBy('id')->get();
        foreach($receipts as $row){$kind=in_array($row->channel,['dine','phone'],true)?$row->channel:'takeaway';$total=(int)$row->total_cents;$delivery=(int)$row->delivery_cents;$channels[$kind]['count']++;$channels[$kind]['gross_cents']+=$total;$channels[$kind]['delivery_cents']+=$delivery;$sources[]=['source'=>'pos','source_id'=>$row->id];
            $cash=$row->payment_method==='cash'?$total:0;if($row->payment_method==='mixed')foreach(json_decode($row->tenders_snapshot??'[]',true)?:[] as $part)if($part['method']==='cash')$cash+=(int)$part['amount_cents'];
            $cashSales+=$cash;$nonCash+=$total-$cash;$cashDelivery+=$row->payment_method==='cash'?$delivery:0;
        }
        // App orders use their own settlement stream. Vendor-collected cash is distinct
        // from delegate/electronic receipts; a later cash handover can be recognized once.
        $appSources=FasakhanstaStoreFulfillment::sourceRestaurantIdsForBranch((int)$branch['id']);
        $appRows=DB::table('orders')->whereIn('resturant_id',$appSources)->where('type','current')->where('status','completed')->where('updated_at','>=',$activated)->where(function($q)use($value){$this->unclaimed($q,$value,'app','orders.id');$q->orWhere(function($cash)use($value){$cash->where('payment_type','cash')->where('transfer_price_by','vendor');$this->unclaimed($cash,$value,'app_cash','orders.id');});})->orderBy('id')->get();
        $claimed=DB::table('branch_shift_sources')->whereIn('source',['app','app_cash'])->whereIn('source_id',$appRows->pluck('id'))->get()->groupBy('source')->map(fn($rows)=>array_fill_keys($rows->pluck('source_id')->all(),true));
        $unsettled=$appRows->filter(function($row)use($claimed){
            $saleClaimed=isset(($claimed['app']??[])[$row->id]);$cashClaimed=isset(($claimed['app_cash']??[])[$row->id]);
            $vendorCash=$row->payment_type==='cash'&&($row->transfer_price_by??null)==='vendor';
            return !$saleClaimed||($vendorCash&&!$cashClaimed);
        });
        // Retained Owner data can contain thousands of app orders. Read the
        // same scoped cart rows in bounded batches and resolve the service rate
        // only when the first original amount calculation actually needs it.
        // This context belongs to this build, never another request or closing.
        $bps=null;
        foreach($unsettled->chunk(500) as $batch){
            $cartRows=DB::table('carts')->whereIn('order_id',$batch->pluck('id'))->get()->groupBy('order_id');
            foreach($batch as $row){$saleClaimed=isset(($claimed['app']??[])[$row->id]);$cashClaimed=isset(($claimed['app_cash']??[])[$row->id]);$vendorCash=$row->payment_type==='cash'&&($row->transfer_price_by??null)==='vendor';
                [$total,$delivery]=$this->appAmount($row,$cartRows[$row->id]??collect(),$bps);
                if(!$saleClaimed){$channels['app']['count']++;$channels['app']['gross_cents']+=$total;$channels['app']['delivery_cents']+=$delivery;$sources[]=['source'=>'app','source_id'=>$row->id];if(!$vendorCash)$appOutside+=$total-$delivery;}
                if($vendorCash&&!$cashClaimed){$appCash+=$total-$delivery;$sources[]=['source'=>'app_cash','source_id'=>$row->id];}
                if($row->payment_type==='cash'&&($row->transfer_price_by??null)===null)$appPending++;
            }
        }
        $expenses=0;$cashExpenses=0;
        $commands=$this->unclaimed(DB::table('branch_expense_commands')->where('branch',$value)->where('created_at','>=',$activated),$value,'expense','branch_expense_commands.id')->orderBy('id')->get();
        foreach($commands as $command){$row=json_decode($command->snapshot,true);$sign=($row['status']??'')==='approved'?1:($command->kind==='void'?-1:0);if(!$sign)continue;$amount=$sign*(int)$row['amount_cents'];$expenses+=$amount;if($row['payment_method']==='cash')$cashExpenses+=$amount;$sources[]=['source'=>'expense','source_id'=>$command->id];}
        $gross=0;$delivery=0;$present=[];foreach($channels as $kind=>$totals){$gross+=$totals['gross_cents'];$delivery+=$totals['delivery_cents'];$present[$kind]=['count'=>$totals['count'],'gross'=>Money::decimal($totals['gross_cents']),'delivery'=>Money::decimal($totals['delivery_cents']),'net'=>Money::decimal($totals['gross_cents']-$totals['delivery_cents'])];}
        $expected=$opening+$movement+$appCash-$cashDelivery;
        $pendingExpenses=$this->pendingExpenses($branch);
        $snapshot=['branch'=>$branch,'started_at'=>$this->time($started),'closed_at'=>$this->time($now),'channels'=>$present,'sales_total'=>Money::decimal($gross),'delivery_total'=>Money::decimal($delivery),'expenses_total'=>Money::decimal($expenses),'net_sales'=>Money::decimal($gross-$delivery-$expenses),'opening_cash'=>Money::decimal($opening),'cash_sales'=>Money::decimal($cashSales),'noncash_sales'=>Money::decimal($nonCash),'app_branch_cash'=>Money::decimal($appCash),'app_outside_drawer'=>Money::decimal($appOutside),'cash_delivery'=>Money::decimal($cashDelivery),'cash_expenses'=>Money::decimal($cashExpenses),'other_cash_movements'=>Money::decimal($movement-$cashSales+$cashExpenses),'expected_cash'=>Money::decimal($expected),'pending_expenses'=>$pendingExpenses['total'],'pending_expense_items'=>$pendingExpenses['items'],'pending_expenses_url'=>route('branch-expenses.index',['branch'=>$value,'status'=>'pending']),'unsettled_app_cash'=>$appPending,'pending_invoices'=>$this->pendingInvoices($branch)];
        return ['snapshot'=>$snapshot,'sources'=>$sources,'expected'=>$expected,'till_balance'=>$balance,'activated_at'=>$activated,'started_at'=>$started,'closed_at'=>$now];
    }
    public function close(array $values,$actor): array
    {
        $actor=$this->manager($actor);
        $v=Validator::make($values,['branch'=>'required|string|max:40','idempotency_key'=>'required|uuid','previous_closing_id'=>'required|integer|min:0','review_token'=>'required|string|size:64','counted_cash'=>'required|string|max:14','notes'=>'nullable|string|max:1000'])->validate();$counted=$this->cents($v['counted_cash']);abort_unless($counted>=0&&$counted<=100000000000,422,'أدخل الكاش الفعلي بقيمة صحيحة.');$v['counted_cash']=Money::decimal($counted);$v['notes']=trim($v['notes']??'');$hash=PosServiceTicket::fingerprint($v);$actor=$this->access->actor($actor);abort_unless($this->access->permissions($actor)['can_checkout'],403);
        return DB::transaction(function()use($v,$hash,$counted,$actor){$branch=$this->branch($v['branch'],$actor,true);$old=DB::table('branch_shift_closings')->where('branch',$v['branch'])->where('actor_id',$actor->id)->where('request_key',$v['idempotency_key'])->first();if($old){abort_unless(hash_equals($old->request_hash,$hash),409,'رقم الإغلاق مستخدم لبيانات مختلفة.');return ['success'=>true,'replayed'=>true,'closing'=>$this->stub($old)];}
            $last=$this->latest($v['branch']);abort_unless((int)($last->id??0)===(int)$v['previous_closing_id'],409,'تم إغلاق وردية من جهاز آخر. حدّث الصفحة وراجع الفترة الجديدة.');
            abort_unless($this->pendingInvoices($branch,true)['total']===0,409,'برجاء إغلاق الفواتير أولًا.');
            abort_unless($this->pendingExpenses($branch,true)['total']===0,409,'لا يمكن إغلاق الوردية قبل تسوية المصروفات المعلقة باعتمادها أو رفضها.');
            DB::table('takeaway_tills')->insertOrIgnore(['branch'=>$v['branch'],'balance_cents'=>0,'tax_bps'=>0,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            $till=DB::table('takeaway_tills')->where('branch',$v['branch'])->lockForUpdate()->first();
            $built=$this->build($branch,$last);abort_unless(hash_equals($this->token($built),$v['review_token']),409,'تغيرت تحصيلات أو مصروفات الوردية أثناء العدّ. تم تحديث البيانات؛ أعد عدّ النقدية قبل الإغلاق.');$snapshot=$built['snapshot'];$snapshot+=['counted_cash'=>Money::decimal($counted),'variance'=>Money::decimal($counted-$built['expected']),'variance_label'=>$counted===$built['expected']?'مطابق':($counted>$built['expected']?'زيادة':'عجز'),'cashier'=>['id'=>(int)$actor->id,'name'=>(string)$actor->name],'notes'=>$v['notes']];
            $snapshot['next_shift_opening_cash']=Money::decimal($built['expected']);$snapshot['drawer_preserved']=true;
            $id=DB::table('branch_shift_closings')->insertGetId(['branch'=>$v['branch'],'sequence'=>(int)($last->sequence??0)+1,'actor_id'=>$actor->id,'request_key'=>$v['idempotency_key'],'request_hash'=>$hash,'activated_at'=>$built['activated_at'],'started_at'=>$built['started_at'],'closed_at'=>$built['closed_at'],'counted_cents'=>$counted,'expected_cents'=>$built['expected'],'variance_cents'=>$counted-$built['expected'],'till_balance_cents'=>$built['till_balance'],'snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),'notes'=>$v['notes'],'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            foreach(array_chunk($built['sources'],500) as $chunk)DB::table('branch_shift_sources')->insert(array_map(fn($s)=>$s+['closing_id'=>$id,'branch'=>$v['branch']],$chunk));
            app(BranchShiftPrinting::class)->enqueue($id,(int)$actor->id,$v['idempotency_key']);
            return ['success'=>true,'replayed'=>false,'closing'=>$this->stub(DB::table('branch_shift_closings')->where('id',$id)->first())];
        },3);
    }
    public function recover(array $values,$actor): array
    {
        $actor=$this->manager($actor);$v=Validator::make($values,['branch'=>'required|string|max:40','idempotency_key'=>'required|uuid'])->validate();$this->branch($v['branch'],$actor);$row=DB::table('branch_shift_closings')->where('branch',$v['branch'])->where('actor_id',$actor->id)->where('request_key',$v['idempotency_key'])->first();return ['success'=>true,'found'=>(bool)$row,'closing'=>$row?$this->stub($row):null];
    }
    public function receipt(int $id,$actor): array
    {
        $actor=$this->manager($actor);$row=DB::table('branch_shift_closings')->where('id',$id)->first();abort_unless($row,404);$this->branch($row->branch,$actor);return json_decode($row->snapshot,true)+$this->stub($row);
    }
    private function stub(object $row): array {return ['id'=>(int)$row->id,'number'=>'SHIFT-'.str_pad((string)$row->id,6,'0',STR_PAD_LEFT),'sequence'=>(int)$row->sequence,'branch'=>$row->branch,'started_at'=>$this->time($row->started_at),'closed_at'=>$this->time($row->closed_at),'print_url'=>route('branch-shifts.print',['id'=>$row->id])];}
    private function time($time): string {return Carbon::parse($time,'UTC')->setTimezone('Africa/Cairo')->format('Y-m-d H:i:s');}
}
