<?php
// Real in-memory CRM, cash register, stock and dashboard controller boundaries. No production bootstrap.
require __DIR__.'/customer_directory_checkout_test.php';

use App\Services\Dashboard\{CustomerDirectory,PhoneDeliveryBoard,PosServiceTicket,TakeawayService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;

class ClosureControllerBase { public function middleware($callback) {} }
class_alias(ClosureControllerBase::class,'App\\Http\\Controllers\\Controller');
require_once dirname(__DIR__).'/app/Http/Controllers/Dashboard/TakeawayController.php';
require_once dirname(__DIR__).'/app/Http/Controllers/Dashboard/PosServiceController.php';
require_once dirname(__DIR__).'/app/Http/Controllers/Dashboard/PhoneOrdersController.php';
class ClosureTakeawayController extends \App\Http\Controllers\Dashboard\TakeawayController { public function __construct() {} }
class ClosureDiningController extends \App\Http\Controllers\Dashboard\PosServiceController { protected string $channel='dine'; public function __construct() {} }
class ClosurePhoneController extends \App\Http\Controllers\Dashboard\PhoneOrdersController { public function __construct() {} }
function auth($guard=null) { return new class { public function user() { return $GLOBALS['actor']; } }; }
function response() { return new class { public function json($value) { return (object)['data'=>$value]; } }; }

$count=0; $writes=[]; $takeawayController=new ClosureTakeawayController; $diningController=new ClosureDiningController; $phoneController=new ClosurePhoneController;
DB::connection()->setEventDispatcher(new \Illuminate\Events\Dispatcher(app()));
DB::listen(function($query) use (&$writes) { if(preg_match('/\A\s*(insert|update|delete|replace|create|alter)\b/i',$query->sql))$writes[]=$query->sql; });
function closureState(): array {
    $tables=['takeaway_orders','takeaway_order_items','takeaway_till_entries','takeaway_tills','branch_inventory','branch_inventory_movements',
        'branch_stock','branch_stock_movements','pos_service_tickets','pos_service_commands','pos_service_tables','pos_service_kitchen_tickets',
        'phone_delivery_batches','phone_delivery_batch_items','branch_operation_commands','crm_customers','crm_customer_sources','crm_purchases','branch_customers'];
    $state=[]; foreach($tables as $table) if(Schema::hasTable($table))$state[$table]=hash('sha256',json_encode(DB::table($table)->orderBy('id')->get()->all()));
    return $state;
}
function closureRejected(callable $call,string $field,string $label): void {
    global $writes; $before=closureState(); $writes=[];
    try { $call(); }
    catch(\Illuminate\Validation\ValidationException $error) {
        check(isset($error->errors()[$field]),$label);
        check($writes===[]&&closureState()===$before,'rejected closure consent makes no cash, sale, stock, customer or ticket write'); return;
    }
    throw new RuntimeException('Expected required service consent: '.$label);
}
function httpCheckout(array $v): array { global $takeawayController,$sales; return $takeawayController->checkout(Request::create('/admin/takeaway/checkout','POST',$v),$sales)->data; }
function httpSettle(string $channel,int $id,array $v): array {
    global $diningController,$phoneController,$tickets; $controller=$channel==='dine'?$diningController:$phoneController;
    return $controller->settle(Request::create('/admin/'.$channel.'/settle','POST',$v),$id,$tickets)->data;
}
function httpBatch(array $v): array { global $phoneController,$board; return $phoneController->finishBatch(Request::create('/admin/phone-orders/finish-batch','POST',$v),$board)->data; }
function closurePayment(string $phone): array {
    global $cart,$sales,$actor; $q=$sales->quote($cart,$actor);
    return $cart+['idempotency_key'=>(string)Str::uuid(),'quote_hash'=>$q['quote_hash'],'payment_method'=>'cash','cash_received'=>'100.00','customer_phone'=>$phone];
}
function closureTicket(string $channel,string $phone): array {
    global $cart,$sales,$actor;
    $q=$sales->quote($cart,$actor,['channel'=>$channel,'service_bps'=>0,'delivery_cents'=>0]);
    $id=DB::table('pos_service_tickets')->insertGetId(['branch'=>'f:100','channel'=>$channel,'actor_id'=>10,
        'status'=>$channel==='phone'?'out_for_delivery':'awaiting_bill','payment_status'=>'unpaid','revision'=>1,
        'customer_name'=>'عميل موافقة إنهاء الفاتورة','customer_phone'=>$phone,'phone_key'=>CustomerDirectory::phoneKey($phone),
        'address'=>'عنوان اختبار','delivery_company_snapshot'=>$channel==='phone'?json_encode(['id'=>1,'name'=>'شركة اختبار']):null,
        'cart_snapshot'=>json_encode($cart),'quote_snapshot'=>json_encode($q),'business_date'=>'2026-10-07',
        'bill_issued_at'=>now('UTC'),'bill_issued_by'=>10,'bill_issued_revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    return [$id,['branch'=>'f:100','expected_revision'=>1,'idempotency_key'=>(string)Str::uuid(),'quote_hash'=>$q['quote_hash'],
        'payment_method'=>'cash','cash_received'=>'100.00','payment_confirmed'=>true,'customer_phone'=>$phone],$q];
}

// Current checkbox acceptance is required; marketing or an earlier stored opt-in cannot substitute.
$prior=$directory->capture(['phone'=>'01233000001'],'takeaway','f:100');
$directory->consent($prior->id,true,null,'earlier-customer-permission',10);
$base=closurePayment('01233000001');
foreach(['missing'=>null,'false'=>false,'zero'=>0,'string_zero'=>'0','null'=>null,'yes'=>'yes','string_true'=>'true','array'=>[]] as $name=>$value) {
    $v=$base; if($name!=='missing')$v['service_consent']=$value;
    closureRejected(fn()=>httpCheckout($v),'service_consent','takeaway dashboard rejects '.$name.' current service consent');
}
closureRejected(fn()=>httpCheckout($base+['marketing_consent'=>true,'require_service_consent'=>false]),'service_consent','marketing approval and user bypass flags cannot replace takeaway service consent');
foreach([true,1,'1'] as $value) {
    $v=closurePayment('01233000002')+['service_consent'=>$value,'marketing_consent'=>false];
    $result=httpCheckout($v); check(!$result['replayed']&&$result['receipt']['channel']==='takeaway','takeaway accepts a validated true service checkbox');
}
$customer=DB::table('crm_customers')->where('phone','+201233000002')->first();
check((bool)$customer->service_consent&&!(bool)$customer->marketing_consent,'required takeaway follow-up never creates marketing permission');
$directory->consent($customer->id,false,false,'whatsapp-inbound:opt-out',10); $state=closureState(); $writes=[];
check(httpCheckout($v)['replayed']&&closureState()===$state&&$writes===[],'exact approved checkout replay cannot revive a later customer opt-out or write cash');

// A legacy trusted collection may be safely recovered from the HTTP endpoint after enforcement.
$legacy=closurePayment('01233000003'); $internal=$sales->checkout($legacy,$actor); $state=closureState(); $writes=[];
check(httpCheckout($legacy)['replayed']&&closureState()===$state&&$writes===[],'legacy unchecked exact checkout retry remains read only');
$fresh=array_replace($legacy,['idempotency_key'=>(string)Str::uuid()]);
closureRejected(fn()=>httpCheckout($fresh),'service_consent','new unchecked sale cannot reuse trusted legacy checkout behavior');

foreach(['dine','phone'] as $channel) {
    [$id,$v]=closureTicket($channel,'01233000001');
    foreach(['missing'=>null,'false'=>false,'zero'=>0,'null'=>null,'invalid'=>'true'] as $name=>$value) {
        $input=$v; if($name!=='missing')$input['service_consent']=$value;
        closureRejected(fn()=>httpSettle($channel,$id,$input),'service_consent',$channel.' dashboard requires fresh accepted service consent: '.$name);
    }
    closureRejected(fn()=>httpSettle($channel,$id,$v+['marketing_consent'=>true]),'service_consent',$channel.' marketing approval cannot substitute for service follow-up');
    $approved=$v+['service_consent'=>true,'marketing_consent'=>false];
    $paid=httpSettle($channel,$id,$approved); check(!$paid['replayed']&&$paid['ticket']['payment_status']==='paid',$channel.' approved checkbox permits final collection');
    $state=closureState(); $writes=[];
    check(httpSettle($channel,$id,$approved)['replayed']&&closureState()===$state&&$writes===[],$channel.' exact approved settlement replay has no duplicate effects');
    [$legacyId,$legacyV]=closureTicket($channel,'01233000004');
    $tickets->settle($channel,$legacyId,$legacyV,$actor); $state=closureState(); $writes=[];
    check(httpSettle($channel,$legacyId,$legacyV)['replayed']&&closureState()===$state&&$writes===[],$channel.' legacy trusted unchecked settlement can still be recovered');
}

// Validate every batch item before settling the first item, preserving all-or-nothing closure.
[$one,$onePay,$oneQuote]=closureTicket('phone','01233000005'); [$two,$twoPay,$twoQuote]=closureTicket('phone','01233000006');
$batchValues=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'items'=>[
    ['id'=>$one,'revision'=>1,'quote_hash'=>$oneQuote['quote_hash'],'service_consent'=>true,'marketing_consent'=>false],
    ['id'=>$two,'revision'=>1,'quote_hash'=>$twoQuote['quote_hash']]],
    'total'=>\App\Services\GoServices\Money::decimal($oneQuote['total_cents']+$twoQuote['total_cents']),
    'payment_method'=>'cash','cash_received'=>'100.00','payment_confirmed'=>true];
foreach(['missing'=>null,'false'=>false,'zero'=>0,'invalid'=>'yes'] as $name=>$value) {
    $v=$batchValues; if($name!=='missing')$v['items'][1]['service_consent']=$value;
    closureRejected(fn()=>httpBatch($v),'items.1.service_consent','batch requires every invoice checkbox before any settlement: '.$name);
}
$v=$batchValues; $v['items'][1]['marketing_consent']=true;
closureRejected(fn()=>httpBatch($v),'items.1.service_consent','batch marketing approval cannot substitute for the missing invoice checkbox');
$v['items'][1]['service_consent']=true; $v['items'][1]['marketing_consent']=false;
$paid=httpBatch($v); check(!$paid['replayed']&&count($paid['batch']['items'])===2,'approved service consent on every invoice permits atomic batch collection');
foreach(['+201233000005','+201233000006'] as $phone) {
    $customer=DB::table('crm_customers')->where('phone',$phone)->first();
    check((bool)$customer->service_consent&&!(bool)$customer->marketing_consent,'batch grants service follow-up separately for each customer without marketing');
}
$state=closureState(); $writes=[];
check(httpBatch($v)['replayed']&&closureState()===$state&&$writes===[],'exact approved batch replay never repeats sales, stock, customer or money writes');
$state=closureState(); $writes=[];
check(httpBatch($batch)['replayed']&&closureState()===$state&&$writes===[],'legacy exact batch without every checkbox remains a read-only recovery');

// Simulate another matching request completing after the first lookup but before the register lock.
// SQLite cannot model MySQL contention; the grammar annotation observes the actual locking-read flag.
class ClosureLockingGrammar extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
    protected function compileLock(\Illuminate\Database\Query\Builder $query,$value) { return $value ? '/* current locking read */' : ''; }
}
$race=closurePayment('01233000007')+['service_consent'=>true,'marketing_consent'=>false];
$beforeSales=DB::table('takeaway_orders')->count(); $beforePurchases=DB::table('crm_purchases')->count();
$beforeCash=(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents');
$raceTotal=$sales->quote($cart,$actor)['total_cents'];
$armed=true; $injected=false; $committed=null; $raceReads=[];
$connection=DB::connection(); $grammar=$connection->getQueryGrammar(); $connection->setQueryGrammar(new ClosureLockingGrammar());
DB::listen(function($query)use(&$armed,&$injected,&$committed,&$raceReads,$race,$sales,$actor){
    if(!preg_match('/\A\s*select\b/i',$query->sql)||!str_contains($query->sql,'"takeaway_orders"')||!in_array($race['idempotency_key'],$query->bindings,true))return;
    $raceReads[]=['after_wait'=>$injected,'locking'=>str_contains($query->sql,'/* current locking read */')];
    if(!$armed)return;
    $armed=false; $committed=$sales->checkout($race,$actor); $injected=true;
});
try { $raced=httpCheckout($race); } finally { $connection->setQueryGrammar($grammar); }
check($injected&&$raced['replayed']&&$raced['receipt']['id']===$committed['receipt']['id'],'matching checkout that completes between lookups returns its exact original receipt');
check(count(array_filter($raceReads,fn($read)=>$read['after_wait']&&$read['locking']))===1,'checkout rechecks idempotency with a current locking read after waiting for the till');
check(DB::table('takeaway_orders')->count()===$beforeSales+1&&DB::table('crm_purchases')->count()===$beforePurchases+1,'interleaved matching checkout creates one sale and one purchase');
check((int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===$beforeCash+$raceTotal,'interleaved matching checkout records cash only once');
echo $count.' dashboard closure service consent checks passed'.PHP_EOL;
