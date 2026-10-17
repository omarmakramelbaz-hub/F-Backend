<?php
// Owns only an in-memory SQLite database. Does not bootstrap the production app or .env.
require getenv('CRM_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
spl_autoload_register(function($class){if(str_starts_with($class,'App\\Services\\')){$file=dirname(__DIR__).'/app/Services/'.str_replace('\\','/',substr($class,13)).'.php';if(is_file($file))require_once $file;}});

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Facade, DB, Schema};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Services\Dashboard\{CustomerDirectory,PosServiceTicket,TakeawayService};

function app($abstract=null) { $c=Container::getInstance(); return $abstract?$c->make($abstract):$c; }
function config($key=null,$default=null) { if(is_array($key)){foreach($key as $k=>$v)app('config')->set($k,$v);return;}return app('config')->get($key,$default); }
function now($zone=null) { return \Carbon\Carbon::now($zone); }
function abort_unless($condition,$code,$message='') { if(!$condition)throw new HttpException($code,$message); }
function abort_if($condition,$code,$message='') { if($condition)throw new HttpException($code,$message); }
function abort($code,$message='') { throw new HttpException($code,$message); }
function route($name,$args=[]) { return 'https://test.invalid/'.$name.'?'.http_build_query($args); }
function database_path($path) { return dirname(__DIR__).'/database/'.$path; }
class TestApplication extends Container { public function getLocale() { return 'ar'; } }
class TestUser extends \Illuminate\Database\Eloquent\Model { protected $table='users'; public function can($permission) { return false; } }
class_alias(TestUser::class,'App\\Models\\User');
$c=new TestApplication; Container::setInstance($c);$c->instance('config',new \Illuminate\Config\Repository);
$capsule=new Capsule($c);$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);$capsule->setAsGlobal();$capsule->bootEloquent();
$c->instance('db',$capsule->getDatabaseManager());$c->bind('db.schema',fn()=>DB::connection()->getSchemaBuilder());Facade::setFacadeApplication($c);
$translator=new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader,'en');
$c->instance('validator',new \Illuminate\Validation\Factory($translator,$c));
config(['app.timezone'=>'Africa/Cairo','desktop_pos.enabled'=>true]);
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-07T12:00:00Z'));
Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->string('name');$t->string('phone')->default('01000000000');$t->string('address')->default('المنصورة');});
Schema::create('categories',function(Blueprint $t){$t->id();$t->string('name_ar');$t->string('name_en');});
Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->unsignedBigInteger('product_id');$t->unsignedBigInteger('category_id');$t->string('product_name');$t->decimal('product_price',14,2);$t->string('status');$t->text('price');});
foreach([[10,'كاشير','vendor',null],[11,'فرع آخر','vendor',null]] as [$id,$name,$type,$parent])DB::table('users')->insert(['id'=>$id,'name'=>$name,'account_type'=>$type,'owner_resturant_id'=>$parent]);
foreach([[100,10],[101,11]] as [$id,$user])DB::table('resturants')->insert(['id'=>$id,'user_id'=>$user,'name'=>'فرع '.$id]);
DB::table('categories')->insert(['id'=>1,'name_ar'=>'رنجة','name_en'=>'Herring']);
DB::table('resturant_products')->insert(['id'=>1,'resturant_id'=>100,'product_id'=>1,'category_id'=>1,'product_name'=>'رنجة','product_price'=>'100.00','status'=>'show','price'=>'{}']);
foreach([
 '2026_10_03_140000_create_takeaway_pos.php'=>'CreateTakeawayPos',
 '2026_10_03_150000_create_pos_service_tickets.php'=>'CreatePosServiceTickets',
 '2026_10_04_060000_lock_pos_service_bills.php'=>'LockPosServiceBills',
 '2026_10_04_190000_create_branch_stock.php'=>'CreateBranchStock',
 '2026_10_04_210000_create_branch_inventory_recipes.php'=>'CreateBranchInventoryRecipes',
 '2026_10_04_080000_create_branch_operations.php'=>'CreateBranchOperations',
 '2026_10_06_130000_create_phone_delivery_batches.php'=>'CreatePhoneDeliveryBatches',
 '2026_10_17_000001_create_crm_customer_directory.php'=>'CreateCrmCustomerDirectory',
] as $file=>$class){require_once database_path('migrations/'.$file);(new $class)->up();}
Schema::table('branch_stock_recipes',fn(Blueprint $t)=>$t->boolean('raw_stock')->default(false));
DB::table('branch_inventory')->insert(['branch'=>'f:100','ingredient_id'=>6,'quantity_units'=>10000000,'revision'=>1]);
DB::table('branch_stock_recipes')->insert(['branch'=>'f:100','product_id'=>1,'unit'=>'kg','revision'=>1,'updated_by'=>10,'variants'=>json_encode(['0'=>[['ingredient_id'=>6,'name'=>'رنجة سمينة','unit'=>'kg','quantity_units'=>1000000]]])]);
DB::table('takeaway_tills')->insert(['branch'=>'f:100','tax_bps'=>1400,'balance_cents'=>0,'revision'=>1]);
$actor=TestUser::find(10); $sales=app(TakeawayService::class); $directory=app(CustomerDirectory::class); $count=0;
function check($value,$message) {global $count;if(!$value)throw new RuntimeException($message);$count++;echo 'PASS '.$message.PHP_EOL;}
function denied(callable $fn,int $status,string $message) {try{$fn();}catch(HttpException $e){check($e->getStatusCode()===$status,$message);return;}catch(\Illuminate\Validation\ValidationException $e){check($status===422,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
foreach(['01012345678','٠١٠١٢٣٤٥٦٧٨','۰۱۰۱۲۳۴۵۶۷۸','+20 10 1234 5678','00201012345678','201012345678','1012345678'] as $number) check(CustomerDirectory::normalizePhone($number)==='+201012345678','canonical number '. $number);
foreach(['','01012','01912345678','+2010123','001','abc01012345678','+20+1012345678'] as $number)check(CustomerDirectory::normalizePhone($number)===null,'invalid phone rejected '. $number);
check(CustomerDirectory::normalizePhone('+966 50 123 4567')==='+966501234567','international number supported');
$one=$directory->capture(['phone'=>'01012345678','name'=>'عميل','address'=>'عنوان محفوظ','service_consent'=>true],'takeaway','f:100');
$two=$directory->capture(['phone'=>'+201012345678','name'=>'','address'=>''],'dine','f:101');
check($one->id===$two->id&&DB::table('crm_customers')->count()===1,'one identity across branches and channels');
check($two->name==='عميل'&&$two->address==='عنوان محفوظ','partial data preserves known profile');
check(!(bool)$two->service_consent&&!(bool)$two->marketing_consent,'capture cannot grant messaging consent');
$directory->capture(['phone'=>'01212345678'],'unclassified','');
check(DB::table('crm_customer_sources')->count()===2,'inquiry never acquires a sales channel');
$directory->consent($one->id,true,null,'customer explicit permission',10);
$directory->capture(['phone'=>'01012345678','service_consent'=>false],'app','');
check((bool)DB::table('crm_customers')->where('id',$one->id)->value('service_consent'),'ordinary capture cannot revoke consent');
$directory->consent($one->id,false,false,'whatsapp-inbound:opt-out');
check(DB::table('crm_customers')->where('id',$one->id)->value('opted_out_at')!==null,'opt-out is recorded');
$cart=['branch'=>'f:100','items'=>[['product_id'=>1,'quantity_mode'=>'weight','quantity'=>'0.250']]];
$q=$sales->quote($cart,$actor);
$pay=['idempotency_key'=>(string)Str::uuid(),'quote_hash'=>$q['quote_hash'],'payment_method'=>'cash','cash_received'=>'100.00'];
denied(fn()=>$sales->checkout($cart+$pay,$actor),422,'takeaway final checkout requires phone on server');
check(DB::table('takeaway_orders')->count()===0&&DB::table('crm_purchases')->count()===0,'missing phone creates no sale or feedback');
$values=$cart+$pay+['customer_phone'=>'٠١٠١٢٣٤٥٦٧٨','customer_name'=>'عميل تيك أواي','service_consent'=>true,'marketing_consent'=>false];
$first=$sales->checkout($values,$actor);$retry=$sales->checkout($values,$actor);
check($retry['replayed']&&$retry['receipt']['id']===$first['receipt']['id'],'checkout replay preserves paid receipt');
check(DB::table('takeaway_orders')->count()===1&&DB::table('crm_purchases')->count()===1,'one sale creates one purchase under retries');
$p=DB::table('crm_purchases')->first();
check($p->paid_at==='2026-10-07 12:00:00'&&$p->feedback_due_at==='2026-10-08 12:00:00','feedback due exactly twenty-four hours from payment');
check($first['receipt']['context']['customer_phone']==='+201012345678','canonical customer phone retained in receipt snapshot');
$cust=DB::table('crm_customers')->where('id',$p->customer_id)->first();
check((bool)$cust->service_consent&&!(bool)$cust->marketing_consent&&$cust->opted_out_at===null,'checkout grants only explicit consent and clears previous opt-out');
check($cust->service_consent_evidence==='pos-checkout:'.$first['receipt']['id']&&(int)$cust->service_consented_by===10,'checkout consent has receipt and staff audit evidence');
$changed=$values;$changed['customer_phone']='01112345678';denied(fn()=>$sales->checkout($changed,$actor),409,'idempotency key cannot change customer');
$bad=$values;$bad['idempotency_key']=(string)Str::uuid();$bad['cash_received']='1.00';denied(fn()=>$sales->checkout($bad,$actor),422,'failed payment does not schedule feedback');
check(DB::table('crm_purchases')->count()===1,'failed payment has no purchase');
DB::table('pos_service_tables')->insert(['id'=>1,'branch'=>'f:100','name'=>'1','capacity'=>4,'active'=>true,'revision'=>1]);
$tickets=app(PosServiceTicket::class);$dq=$tickets->quote('dine',$cart,$actor);
$saved=$tickets->save('dine',$cart+['table_id'=>1,'customer_name'=>'عميل صالة','waiter_name'=>'كاشير','guest_count'=>1,'quote_hash'=>$dq['quote_hash'],'idempotency_key'=>(string)Str::uuid()],$actor);
$ticket=$saved['ticket'];
check(DB::table('crm_purchases')->count()===1,'unpaid dine draft does not schedule feedback');
$bill=$tickets->action('dine',$ticket['id'],['branch'=>'f:100','expected_revision'=>$ticket['revision'],'idempotency_key'=>(string)Str::uuid(),'action'=>'request_bill'],$actor);$ticket=$bill['ticket'];
$settle=['branch'=>'f:100','expected_revision'=>$ticket['revision'],'idempotency_key'=>(string)Str::uuid(),'quote_hash'=>$ticket['quote_hash'],'payment_method'=>'cash','cash_received'=>'100.00'];
denied(fn()=>$tickets->settle('dine',$ticket['id'],$settle,$actor),422,'dine final collection requires phone after bill printing');
check(DB::table('pos_service_tickets')->where('id',$ticket['id'])->value('payment_status')==='unpaid','missing phone leaves dine bill unpaid');
$paid=$tickets->settle('dine',$ticket['id'],$settle+['customer_phone'=>'01012345678'],$actor);
check($paid['ticket']['payment_status']==='paid'&&$paid['ticket']['customer_phone']==='+201012345678','dine phone can be collected after bill is locked');
check(DB::table('crm_purchases')->count()===2&&DB::table('crm_purchases')->where('channel','dine')->count()===1,'dine payment creates one categorized purchase');
$tickets->settle('dine',$ticket['id'],$settle+['customer_phone'=>'01012345678'],$actor);
check(DB::table('crm_purchases')->count()===2,'dine settle replay creates no duplicate feedback');
$late=$directory->purchase(['phone'=>'01012345678'],'delivery','f:100','offline_test','1','2026-10-01T07:30:00+03:00');
$lateRetry=$directory->purchase(['phone'=>'01012345678'],'delivery','f:100','offline_test','1',now('UTC'));
check($lateRetry->id===$late->id&&$lateRetry->feedback_due_at==='2026-10-02 04:30:00','purchase replay preserves original actual payment timestamp');
check($directory->purchaseIsCompleted(DB::table('crm_purchases')->where('channel','dine')->first()),'feedback guard recognizes paid dine bill');
DB::table('pos_service_tickets')->where('id',$ticket['id'])->update(['status'=>'cancelled']);
check(!$directory->purchaseIsCompleted(DB::table('crm_purchases')->where('channel','dine')->first()),'feedback guard blocks cancelled original bill');
DB::table('pos_service_tickets')->where('id',$ticket['id'])->update(['status'=>'paid']);
check(!$directory->purchaseIsCompleted((object)['source_type'=>'unknown','source_id'=>'1','branch'=>'f:100']),'unknown purchase sources fail closed');
check((bool)DB::table('crm_customers')->where('id',$p->customer_id)->value('service_consent'),'unchecked checkout consent preserves prior explicit opt-in');
$phoneQuote=$sales->quote($cart,$actor,['channel'=>'phone','service_bps'=>0,'delivery_cents'=>0]);
$phoneTickets=[];
foreach(['01112345678','01512345678'] as $phone){
    $id=DB::table('pos_service_tickets')->insertGetId(['branch'=>'f:100','channel'=>'phone','actor_id'=>10,'status'=>'out_for_delivery','payment_status'=>'unpaid','revision'=>1,
        'customer_name'=>'عميل دليفري','customer_phone'=>$phone,'phone_key'=>CustomerDirectory::phoneKey($phone),'address'=>'عنوان العميل',
        'delivery_company_snapshot'=>json_encode(['id'=>1,'name'=>'شركة توصيل']),
        'cart_snapshot'=>json_encode($cart),'quote_snapshot'=>json_encode($phoneQuote),'business_date'=>'2026-10-07','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    $phoneTickets[]=['id'=>$id,'revision'=>1,'quote_hash'=>$phoneQuote['quote_hash']];
}
$phoneTickets[0]['service_consent']=true;$phoneTickets[0]['marketing_consent']=true;
$batch=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'items'=>$phoneTickets,'total'=>'57.00','payment_method'=>'cash','cash_received'=>'60.00','payment_confirmed'=>true];
$board=app(\App\Services\Dashboard\PhoneDeliveryBoard::class);
$invalidConsent=$batch;$invalidConsent['items'][1]['service_consent']='all';
denied(fn()=>$board->finish($invalidConsent,$actor),422,'batch rejects non-boolean per-customer consent');
$batchResult=$board->finish($batch,$actor);
$firstDelivery=DB::table('crm_customers')->where('phone','+201112345678')->first();$secondDelivery=DB::table('crm_customers')->where('phone','+201512345678')->first();
check((bool)$firstDelivery->service_consent&&(bool)$firstDelivery->marketing_consent,'delivery batch grants only explicitly selected customer consents');
check(!(bool)$secondDelivery->service_consent&&!(bool)$secondDelivery->marketing_consent,'delivery batch absent consent remains off for other customer');
check(DB::table('crm_purchases')->where('channel','delivery')->where('source_type','takeaway_orders')->count()===2,'delivery batch creates individual feedback opportunities');
$repeatedBatch=$board->finish($batch,$actor);
check($repeatedBatch['replayed']&&$repeatedBatch['batch']['id']===$batchResult['batch']['id'],'delivery batch replay preserves exact customer consent and purchases');
require_once database_path('migrations/2026_10_17_000003_create_crm_customer_sync_states.php');
(new \CreateCrmCustomerSyncStates)->up();
$cutover=now('UTC');
check(\App\Services\Dashboard\DesktopPos::legacyContactAllowed([], $cutover->copy()->subSecond()),'legacy paid offline backlog is recognized before server cutover');
check(!\App\Services\Dashboard\DesktopPos::legacyContactAllowed([], $cutover->copy()),'old offline snapshot cannot bypass phone for a new sale');
check(!\App\Services\Dashboard\DesktopPos::legacyContactAllowed(['customer_phone_required'=>true],$cutover->copy()->subSecond()),'new server-stored snapshot always enforces customer phone');
$offlineQuote=$sales->quote($cart,$actor);$offlineKey=(string)Str::uuid();
$offlineContext=['channel'=>'takeaway','trusted_offline'=>true,'legacy_customer_phone_missing'=>true,'offline_operation'=>$offlineKey,
    'saved_quote'=>$offlineQuote,'business_date'=>'2026-10-06','paid_at'=>$cutover->copy()->subDay(),'snapshot'=>['customer_phone'=>'','legacy_original_customer_phone'=>'']];
$offlinePayload=$cart+['idempotency_key'=>$offlineKey,'quote_hash'=>$offlineQuote['quote_hash'],'payment_method'=>'cash','cash_received'=>'100.00'];
$oldSales=DB::table('takeaway_orders')->count();$oldPurchases=DB::table('crm_purchases')->count();
$oldCash=(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents');
$oldStock=(int)DB::table('branch_inventory')->where('branch','f:100')->where('ingredient_id',6)->value('quantity_units');
$legacyReceipt=$sales->checkout($offlinePayload,$actor,$offlineContext);$legacyReplay=$sales->checkout($offlinePayload,$actor,$offlineContext);
check($legacyReplay['replayed']&&DB::table('takeaway_orders')->count()===$oldSales+1,'legacy offline sale is imported exactly once');
check((int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===$oldCash+$offlineQuote['total_cents'],'legacy offline cash is retained without a synthetic customer');
check((int)DB::table('branch_inventory')->where('branch','f:100')->where('ingredient_id',6)->value('quantity_units')<$oldStock,'legacy offline sale still deducts real inventory');
check(DB::table('crm_purchases')->count()===$oldPurchases,'legacy unknown customer does not create a feedback message');
echo $count.' CRM checkout checks passed'.PHP_EOL;
