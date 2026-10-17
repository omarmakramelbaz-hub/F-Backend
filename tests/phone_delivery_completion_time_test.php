<?php
// Real SQLite listing/settlement fixtures; no production bootstrap, network or schema writes outside memory.
require __DIR__.'/customer_directory_checkout_test.php';

use App\Services\Dashboard\{PhoneDeliveryBoard,TakeawayAccess,PosServiceTicket};
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;

$count=0;$queries=[];$listingBoard=app(PhoneDeliveryBoard::class);
DB::connection()->setEventDispatcher(new \Illuminate\Events\Dispatcher(app()));
DB::listen(function($query)use(&$queries){$queries[]=['sql'=>$query->sql,'bindings'=>$query->bindings];});
DB::table('users')->insert(['id'=>1,'name'=>'Central administrator','account_type'=>'admin']);
DB::table('branch_delivery_companies')->insert(['id'=>1,'branch'=>'f:100','name'=>'شركة اختبار','phone'=>'01000000000','active'=>true,'actor_id'=>10,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);

function timingTicket(string $status='out_for_delivery',string $branch='f:100',int $revision=1): int {
    global $cart,$phoneQuote;
    $quote=$phoneQuote;$quote['branch']=app(TakeawayAccess::class)->branch($branch,TestUser::find($branch==='f:100'?10:11));
    return DB::table('pos_service_tickets')->insertGetId(['branch'=>$branch,'channel'=>'phone','actor_id'=>$branch==='f:100'?10:11,
        'status'=>$status,'payment_status'=>'unpaid','revision'=>$revision,'customer_name'=>'عميل توقيت الإنهاء','customer_phone'=>'01233000101',
        'address'=>'عنوان اختبار','delivery_company_id'=>$branch==='f:100'?1:null,'delivery_company_snapshot'=>json_encode(['id'=>1,'name'=>'شركة اختبار']),
        'cart_snapshot'=>json_encode($cart),'quote_snapshot'=>json_encode($quote),'business_date'=>'2026-10-07',
        'created_at'=>'2026-10-07 11:00:00','updated_at'=>'2026-10-07 11:00:00']);
}
function timingCard(int $id,string $stage='finished',$viewer=null,array $extra=[]): array {
    global $listingBoard,$actor;
    $result=$listingBoard->listing(['branch'=>'f:100']+$extra,$viewer??$actor);
    foreach($result['columns'][$stage]['items'] as $item)if($item['id']===$id)return $item;
    throw new RuntimeException('Missing timing fixture '.$id.' in '.$stage);
}
function timingAudit(int $id,int $revision,string $when,string $kind='action',string $branch='f:100'): void {
    DB::table('pos_service_commands')->insert(['branch'=>$branch,'actor_id'=>10,'request_key'=>(string)Str::uuid(),
        'request_hash'=>str_repeat('a',64),'kind'=>$kind,'ticket_id'=>$id,'revision'=>$revision,'metadata'=>'[]','created_at'=>$when,'updated_at'=>$when]);
}
function timingState(): array {
    $state=[];foreach(['pos_service_tickets','pos_service_commands','phone_delivery_dispatches','takeaway_orders','takeaway_till_entries','takeaway_tills',
        'phone_delivery_batches','phone_delivery_batch_items','branch_inventory','branch_inventory_movements','crm_customers','crm_purchases'] as $table)
        if(Schema::hasTable($table))$state[$table]=hash('sha256',json_encode(DB::table($table)->orderBy('id')->get()->all()));
    return $state;
}

// Existing real batch settlement writes one immutable receipt per invoice.
foreach($phoneTickets as $paidTicket){
    $card=timingCard($paidTicket['id']);
    check($card['completed_at']==='2026-10-07T12:00:00+00:00','batch invoice reports the actual receipt payment time in explicit UTC');
    check($card['batch_print_url']!==null&&$card['payment_status']==='paid','completion enrichment preserves existing batch and payment fields');
}

// Single collection uses the same receipt timestamp, rather than later mutable ticket updates.
$single=timingTicket();Carbon::setTestNow(Carbon::parse('2026-10-07T12:34:56Z'));
$singleValues=['branch'=>'f:100','expected_revision'=>1,'idempotency_key'=>(string)Str::uuid(),'quote_hash'=>$phoneQuote['quote_hash'],
    'payment_method'=>'cash','cash_received'=>'100.00','payment_confirmed'=>true,'service_consent'=>true,'marketing_consent'=>false];
$collected=$tickets->settle('phone',$single,$singleValues,$actor,true);$receiptId=$collected['receipt']['id'];
check(timingCard($single)['completed_at']==='2026-10-07T12:34:56+00:00','single invoice reports its atomic collection timestamp');
DB::table('pos_service_tickets')->where('id',$single)->update(['updated_at'=>'2040-01-01 01:02:03']);
check(timingCard($single)['completed_at']==='2026-10-07T12:34:56+00:00','irrelevant later ticket updates do not become completion time');
$replay=$tickets->settle('phone',$single,$singleValues,$actor,true);
check($replay['replayed']&&timingCard($single)['completed_at']==='2026-10-07T12:34:56+00:00','idempotent collection replay cannot move the completion time');
config(['app.timezone'=>'Asia/Tokyo']);
check(timingCard($single)['completed_at']==='2026-10-07T12:34:56+00:00','completion timezone is explicit UTC independently of display timezone');
config(['app.timezone'=>'Africa/Cairo']);

// A legacy paid invoice is auditable even when no settlement command was imported.
$row=DB::table('pos_service_commands')->where('ticket_id',$single)->where('kind','settle')->first();
DB::table('pos_service_commands')->where('id',$row->id)->delete();
check(timingCard($single)['completed_at']==='2026-10-07T12:34:56+00:00','verified paid receipt remains authoritative without a legacy command row');
$originalReceipt=DB::table('takeaway_orders')->where('id',$receiptId)->first();
foreach(['ticket_id'=>$single+10000,'branch'=>'f:101','channel'=>'takeaway'] as $key=>$bad){
    DB::table('takeaway_orders')->where('id',$receiptId)->update([$key=>$bad]);
    check(timingCard($single)['completed_at']===null,'receipt '.$key.' mismatch cannot supply another invoice completion time');
    DB::table('takeaway_orders')->where('id',$receiptId)->update([$key=>$originalReceipt->$key]);
}
foreach([null,'not-a-time','2026-02-30 12:34:56','2026-10-07 10:59:59'] as $bad){
    DB::table('takeaway_orders')->where('id',$receiptId)->update(['created_at'=>$bad]);
    check(timingCard($single)['completed_at']===null,'missing, invalid or pre-creation receipt timestamp remains unknown');
}
DB::table('takeaway_orders')->where('id',$receiptId)->update(['created_at'=>$originalReceipt->created_at]);

// Actual cancellation records its terminal revision and survives unrelated updates/replays.
$cancelled=timingTicket('new');Carbon::setTestNow(Carbon::parse('2026-10-07T12:40:01Z'));
$cancelValues=['branch'=>'f:100','expected_revision'=>1,'idempotency_key'=>(string)Str::uuid(),'action'=>'cancel','reason'=>'اختبار إلغاء العميل'];
$tickets->action('phone',$cancelled,$cancelValues,$actor);
check(timingCard($cancelled)['completed_at']==='2026-10-07T12:40:01+00:00','cancelled invoice uses its actual terminal action audit');
DB::table('pos_service_tickets')->where('id',$cancelled)->update(['updated_at'=>'2041-01-01 00:00:00']);
check(timingCard($cancelled)['completed_at']==='2026-10-07T12:40:01+00:00','later updates never move cancellation time');
check($tickets->action('phone',$cancelled,$cancelValues,$actor)['replayed']&&timingCard($cancelled)['completed_at']==='2026-10-07T12:40:01+00:00','exact cancellation replay leaves terminal time unchanged');
timingAudit($cancelled,2,'2026-10-07 12:40:02');
check(timingCard($cancelled)['completed_at']===null,'duplicate terminal action audits are ambiguous and never guessed');
$legacyCancelled=timingTicket('cancelled', 'f:100',5);
check(timingCard($legacyCancelled)['completed_at']===null,'legacy cancellation without a terminal audit has no invented timestamp');
timingAudit($legacyCancelled,4,'2026-10-07 12:00:00');timingAudit($legacyCancelled,5,'2026-10-07 12:01:00','save');timingAudit($legacyCancelled,5,'2026-10-07 12:02:00','action','f:101');
check(timingCard($legacyCancelled)['completed_at']===null,'wrong revision, command kind and branch cannot identify cancellation');
timingAudit($legacyCancelled,5,'2026-10-07 12:03:00');
check(timingCard($legacyCancelled)['completed_at']==='2026-10-07T12:03:00+00:00','only one exact branch and terminal revision identifies cancellation');
$invalidCancelled=timingTicket('cancelled','f:100',6);timingAudit($invalidCancelled,6,'2026-10-07 10:00:00');
check(timingCard($invalidCancelled)['completed_at']===null,'cancellation cannot precede the order creation time');
$paidUnknown=timingTicket('finished');DB::table('pos_service_tickets')->where('id',$paidUnknown)->update(['payment_status'=>'paid']);
check(timingCard($paidUnknown)['completed_at']===null,'legacy paid invoice without a verified receipt remains unknown');

// Delivery and prep remain active; the legacy delivered-but-unpaid state is still a courier invoice.
$preparing=timingTicket('preparing');$courier=timingTicket();$legacyCourier=timingTicket('finished');
DB::table('phone_delivery_dispatches')->insert(['ticket_id'=>$courier,'branch'=>'f:100','company_id'=>1,'company_snapshot'=>json_encode(['id'=>1,'name'=>'شركة اختبار']),
    'actor_id'=>10,'created_at'=>'2026-10-07 12:00:00','updated_at'=>'2040-01-01 00:00:00']);
check(timingCard($preparing,'preparing')['completed_at']===null,'preparing invoice has no completion timestamp');
check(timingCard($courier,'courier')['completed_at']===null,'courier handoff is not an invoice completion time');
check(timingCard($legacyCourier,'courier')['completed_at']===null,'legacy finished-but-unpaid stays courier without a completed invoice timestamp');

// Existing branch/company scope and pagination also bound all added audit reads.
$foreign=timingTicket('cancelled','f:101',2);timingAudit($foreign,2,'2026-10-07 12:20:00','action','f:101');
$own=$listingBoard->listing(['branch'=>'f:100'],$actor);$ownIds=array_column($own['columns']['finished']['items'],'id');
check(!in_array($foreign,$ownIds,true),'foreign branch completion never leaks into the cashier board');
denied(fn()=>$listingBoard->listing(['branch'=>'f:101'],$actor),404,'foreign branch listing remains forbidden');
$admin=$listingBoard->listing(['branch'=>'all'],TestUser::find(1));$foreignCards=array_values(array_filter($admin['columns']['finished']['items'],fn($item)=>$item['id']===$foreign));
check(count($foreignCards)===1&&$foreignCards[0]['completed_at']==='2026-10-07T12:20:00+00:00','authorized all-branch viewer sees correctly matched foreign audit');
$filtered=$listingBoard->listing(['branch'=>'f:100','delivery_company_id'=>1],$actor);
check(in_array($courier,array_column($filtered['columns']['courier']['items'],'id'),true),'company filter still matches authoritative dispatch records');

for($i=0;$i<24;$i++)timingTicket('cancelled');
$state=timingState();$queries=[];$page=$listingBoard->listing(['branch'=>'f:100','finished_page'=>1],$actor);
check(count($page['columns']['finished']['items'])===20&&$page['columns']['finished']['pagination']['last_page']===2,'completion metadata preserves twenty-row finished pagination');
$auditQueries=array_values(array_filter($queries,fn($query)=>preg_match('/from "(pos_service_commands|takeaway_orders)"/',$query['sql'])));
check(count($auditQueries)<=2,'completion timestamps use page-batched audit reads instead of a query per card');
check($state===timingState()&&count(array_filter($queries,fn($query)=>preg_match('/\A\s*(insert|update|delete|replace)\b/i',$query['sql'])))===0,'listing, polling and timestamp enrichment never write audit, financial or customer data');
$pageAgain=$listingBoard->listing(['branch'=>'f:100','finished_page'=>1],$actor);
check($page['columns']===$pageAgain['columns'],'repeated polling returns stable order and completion timestamps');
$second=$listingBoard->listing(['branch'=>'f:100','finished_page'=>2],$actor);
check(in_array($single,array_column($second['columns']['finished']['items'],'id'),true),'earlier paid invoice remains on its correct finished page');
echo $count.' phone delivery completion-time checks passed'.PHP_EOL;
