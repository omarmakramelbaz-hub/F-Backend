<?php
/** Isolated real-service shift blocker checks; no .env, network, live DB, or printing.
 * SHIFT_PENDING_TEST_AUTOLOAD=/path/to/Illuminate8/vendor/autoload.php php tests/shift_pending_invoices_test.php
 */
$autoload=getenv('SHIFT_PENDING_TEST_AUTOLOAD')?:dirname(__DIR__).'/vendor/autoload.php';
if(!is_file($autoload))throw new RuntimeException('Set SHIFT_PENDING_TEST_AUTOLOAD to an Illuminate 8 compatible autoloader.');
require $autoload;
spl_autoload_register(function($class){if(strpos($class,'App\\')!==0)return;$path=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($path))require_once $path;},true,true);

use App\Services\Dashboard\BranchShiftClosing;
use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Facade,Schema};
use Symfony\Component\HttpKernel\Exception\HttpException;
function app($abstract=null,array $parameters=[]){$c=Container::getInstance();return $abstract?$c->make($abstract,$parameters):$c;}
function config($key=null,$default=null){if(is_array($key)){foreach($key as $k=>$v)app('config')->set($k,$v);return;}return app('config')->get($key,$default);}
function now($zone=null){return Carbon::now($zone);}
function abort_unless($ok,$status,$message=''){if(!$ok)throw new HttpException($status,$message);}
function abort_if($ok,$status,$message=''){if($ok)throw new HttpException($status,$message);}
function abort($status,$message=''){throw new HttpException($status,$message);}
function route($name,$args=[]){return 'https://shift-fixture.invalid/'.$name.'?'.http_build_query($args);}
function database_path($path=''){return dirname(__DIR__).'/database/'.$path;}
function __($key,$replace=[],$locale=null){return $key;}
class PendingShiftUser extends \Illuminate\Database\Eloquent\Model{protected $table='users';public function can($permission){return (int)$this->id===2&&$permission==='order-list';}}
class_alias(PendingShiftUser::class,'App\\Models\\User');
// Successful closes retain the real accounting path; only the external printer adapter is replaced.
class PendingShiftPrinter{public array $calls=[];public function enqueue($id,$actor,$key){$this->calls[]=[$id,$actor,$key];}}
class_alias(PendingShiftPrinter::class,'App\\Services\\Dashboard\\BranchShiftPrinting');
$container=new Container;Container::setInstance($container);$container->instance('config',new \Illuminate\Config\Repository);
$capsule=new Capsule($container);$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);$capsule->setAsGlobal();$capsule->bootEloquent();
$container->instance('db',$capsule->getDatabaseManager());$container->bind('db.schema',fn()=>DB::connection()->getSchemaBuilder());Facade::setFacadeApplication($container);
$translator=new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader,'ar');$container->instance('translator',$translator);$container->instance('validator',new \Illuminate\Validation\Factory($translator,$container));
$printer=new PendingShiftPrinter;$container->instance(PendingShiftPrinter::class,$printer);$container->instance('App\\Services\\Dashboard\\BranchShiftPrinting',$printer);
config(['app.timezone'=>'Africa/Cairo','app.key'=>'synthetic-shift-pending-test-key']);Carbon::setTestNow(Carbon::parse('2026-10-20T12:00:00Z'));
Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
Schema::create('permissions',function(Blueprint $t){$t->id();$t->string('name');$t->string('guard_name');});DB::table('permissions')->insert(['name'=>'order-list','guard_name'=>'admin']);
foreach([[1,'admin',null],[2,'admin',null],[4,'admin',100],[10,'vendor',null],[11,'vendor',null],[12,'vendor',null],[13,'vendor',null]] as [$id,$type,$parent])DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'owner_resturant_id'=>$parent]);
Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');});
DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'parent_id'=>null,'name'=>'الفرع الأول'],['id'=>101,'user_id'=>11,'parent_id'=>null,'name'=>'الفرع الآخر'],['id'=>301,'user_id'=>12,'parent_id'=>null,'name'=>'شبرا'],['id'=>309,'user_id'=>13,'parent_id'=>301,'name'=>'المقطم ستور']]);
Schema::create('orders',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id')->nullable();$t->string('type')->nullable();$t->string('status')->nullable();$t->string('order_no')->nullable();$t->string('payment_type')->default('card');$t->string('transfer_price_by')->nullable();$t->decimal('total_price',14,2)->default(0);$t->decimal('delivery_price',14,2)->default(0);$t->decimal('user_tax',14,2)->default(0);$t->unsignedInteger('shift_sequence')->nullable();$t->unsignedInteger('shift_order_number')->nullable();$t->timestamps();});
Schema::create('payments',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');$t->string('status')->nullable();$t->string('transaction_id')->nullable();$t->timestamps();});
Schema::create('carts',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');$t->decimal('price',14,2)->default(0);$t->decimal('updated_total',14,2)->nullable();$t->decimal('qty',10,3)->default(1);});
Schema::create('settings',function(Blueprint $t){$t->id();$t->string('name');$t->text('payload');});DB::table('settings')->insert(['name'=>'service_fees','payload'=>'0']);
foreach(['2026_10_03_140000_create_takeaway_pos.php'=>'CreateTakeawayPos','2026_10_04_030000_create_branch_expenses.php'=>'CreateBranchExpenses','2026_10_04_100000_create_branch_shift_closings.php'=>'CreateBranchShiftClosings'] as $file=>$class){require_once database_path('migrations/'.$file);(new $class)->up();}
Schema::table('takeaway_orders',function(Blueprint $t){$t->string('channel')->default('takeaway');$t->unsignedBigInteger('delivery_cents')->default(0);$t->text('tenders_snapshot')->nullable();});
Schema::create('pos_service_tickets',function(Blueprint $t){$t->id();$t->string('branch');$t->string('channel');$t->string('status')->nullable();$t->string('payment_status')->nullable();$t->unsignedBigInteger('paid_order_id')->nullable();$t->unsignedBigInteger('table_id')->nullable();$t->text('table_snapshot')->nullable();$t->unsignedInteger('shift_sequence')->nullable();$t->unsignedInteger('shift_order_number')->nullable();$t->timestamps();});
Schema::create('pos_service_tables',function(Blueprint $t){$t->id();$t->string('branch');$t->string('name');});
DB::table('pos_service_tables')->insert(['id'=>1,'branch'=>'f:100','name'=>'طاولة النافذة']);
$checks=0;
function check($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo 'PASS '.$label.PHP_EOL;}
function denied(callable $call,int $status,string $message=''){try{$call();}catch(HttpException $e){check($e->getStatusCode()===$status&&($message===''||$e->getMessage()===$message),'rejected '.$status.($message!==''?' with invoice-close message':''));return;}throw new RuntimeException('Expected rejection '.$status);}
function actor($id=1){return PendingShiftUser::findOrFail($id);}
function keyFor($id){return sprintf('00000000-0000-4000-8000-%012d',$id);}
function pendingTicket($id,$channel,$status='open',$payment='unpaid',$branch='f:100'){
 DB::table('pos_service_tickets')->insert(['id'=>$id,'branch'=>$branch,'channel'=>$channel,'status'=>$status,'payment_status'=>$payment,'table_id'=>$channel==='dine'?1:null,'table_snapshot'=>$channel==='dine'?json_encode(['name'=>'طاولة النافذة']):null,'created_at'=>'2026-09-01 10:00:00','updated_at'=>'2026-09-01 10:00:00']);
}
function order($id,$status='pending',$restaurant=100,$type='current'){
 DB::table('orders')->insert(['id'=>$id,'resturant_id'=>$restaurant,'type'=>$type,'status'=>$status,'order_no'=>'APP-'.$id,'payment_type'=>'card','created_at'=>'2026-09-01 10:00:00','updated_at'=>'2026-09-01 10:00:00']);
}
function financialState(){global $printer;$state=[];foreach(['branch_shift_closings','branch_shift_sources','takeaway_tills','takeaway_till_entries','branch_expenses','branch_expense_commands'] as $table)$state[$table]=DB::table($table)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();$state['print_calls']=$printer->calls;return json_encode($state,JSON_UNESCAPED_UNICODE);}
function closePayload($service,$key,$branch='f:100',$counted='345.00'){ $data=$service->data(['branch'=>$branch],actor());return ['branch'=>$branch,'previous_closing_id'=>$data['previous_closing_id'],'review_token'=>$data['review_token'],'counted_cash'=>$counted,'idempotency_key'=>keyFor($key)]; }
function pendingBy($report,$source,$id){foreach($report['pending_invoices']['items'] as $item)if($item['source']===$source&&(int)$item['id']===$id)return $item;return null;}
function privateFieldsAbsent($value){if(!is_array($value))return true;foreach($value as $key=>$child)if(in_array($key,['expected_cash','counted_cash','variance','channels','sales_total','total_cents','customer_phone','customer_name'],true)||!privateFieldsAbsent($child))return false;return true;}
$service=app(BranchShiftClosing::class);
DB::table('takeaway_tills')->insert(['branch'=>'f:100','balance_cents'=>34500,'tax_bps'=>0,'revision'=>7,'created_at'=>'2026-10-20 06:00:00','updated_at'=>'2026-10-20 06:00:00']);
DB::table('takeaway_orders')->insert(['id'=>1,'till_id'=>1,'branch'=>'f:100','actor_id'=>10,'request_key'=>keyFor(9000),'request_hash'=>str_repeat('a',64),'quote_hash'=>str_repeat('b',64),
 'business_date'=>'2026-10-20','payment_method'=>'cash','subtotal_cents'=>10000,'discount_cents'=>0,'tax_cents'=>0,'total_cents'=>10000,'cash_received_cents'=>10000,'change_cents'=>0,'tax_bps'=>0,
 'branch_snapshot'=>'{}','cashier_snapshot'=>'{}','channel'=>'takeaway','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
DB::table('takeaway_till_entries')->insert(['till_id'=>1,'branch'=>'f:100','actor_id'=>10,'order_id'=>1,'request_key'=>keyFor(9001),'request_hash'=>str_repeat('c',64),
 'kind'=>'sale','amount_cents'=>10000,'balance_cents'=>34500,'business_date'=>'2026-10-20','note'=>'Synthetic paid sale','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$emptyPreview=closePayload($service,1);
pendingTicket(1,'dine','awaiting_bill');pendingTicket(2,'phone','preparing');pendingTicket(3,'phone','finished','unpaid');pendingTicket(4,'dine','open',null);
pendingTicket(5,'dine','paid','paid');pendingTicket(6,'dine','cancelled');pendingTicket(7,'phone','finished','paid');pendingTicket(8,'phone','cancelled');pendingTicket(9,'dine','awaiting_bill','paid');
pendingTicket(10,'phone','preparing','unpaid','f:101');
order(10);order(11,'pending',101);order(12,'pending',309);
foreach(['completed','cancelled','canceled','declined','rejected','expired','failed','draft',null,''] as $index=>$status)order(20+$index,$status);
order(40,'pending',100,null);order(41,'pending',100,'schedule');
order(50);DB::table('payments')->insert(['order_id'=>50,'status'=>'failed','transaction_id'=>'failed-50']);
order(51);DB::table('payments')->insert(['order_id'=>51,'status'=>'0','transaction_id'=>null]);
order(52);DB::table('payments')->insert([['order_id'=>52,'status'=>'success','transaction_id'=>'success-52'],['order_id'=>52,'status'=>'failed','transaction_id'=>'later-failure-52']]);
order(53);DB::table('payments')->insert(['order_id'=>53,'status'=>'0','transaction_id'=>'failed-53']);
order(54);DB::table('payments')->insert([['order_id'=>54,'status'=>'failed','transaction_id'=>'failure-54'],['order_id'=>54,'status'=>'paid','transaction_id'=>'success-54']]);
$data=$service->data(['branch'=>'f:100'],actor());$pending=$data['report']['pending_invoices'];
check($pending['total']===8&&$pending['counts']===['dine'=>2,'phone'=>2,'app'=>4]&&count($pending['items'])===8,'all-age unpaid dine phone and real active app invoices are counted');
$identities=array_map(fn($item)=>$item['source'].':'.$item['id'],$pending['items']);sort($identities);
check($identities===['app:10','app:51','app:52','app:54','dine:1','dine:4','phone:2','phone:3'],'terminal paid cancelled failed draft null and noncurrent app rows are all excluded');
check(pendingBy($data['report'],'dine',1)!==null&&pendingBy($data['report'],'phone',3)!==null,'previous-month awaiting bill and finished-but-unpaid delivery both block closure');
check(pendingBy($data['report'],'dine',4)!==null,'unknown POS payment state remains conservatively pending');
check(pendingBy($data['report'],'app',50)===null&&pendingBy($data['report'],'app',53)===null,'confirmed failed online transactions are excluded');
check(pendingBy($data['report'],'app',51)!==null&&pendingBy($data['report'],'app',52)!==null&&pendingBy($data['report'],'app',54)!==null,'unattempted payment stays pending and any accepted payment overrides failure history');
check(pendingBy($data['report'],'phone',10)===null&&pendingBy($data['report'],'app',11)===null,'another branch invoices do not leak into a selected branch');
foreach($pending['items'] as $item)check(!array_diff(['source','id','number','label','status','status_label','table_name','branch_name','url'],array_keys($item))&&$item['label']!==''&&$item['url']!=='','pending invoice carries actionable read-only details');
check(pendingBy($data['report'],'dine',1)['table_name']==='طاولة النافذة','pending dining invoice identifies its table');
check(privateFieldsAbsent($data['report']),'read-only pending API reveals no expected cash or customer PII');
$before=financialState();$stale=$emptyPreview;$stale['review_token']=str_repeat('0',64);
denied(fn()=>$service->close($stale,actor()),409,'برجاء إغلاق الفواتير أولًا.');
check(financialState()===$before,'pending invoice guard wins over stale preview and makes no accounting or printer writes');
$other=closePayload($service,2,'f:101','0.00');$before=financialState();
denied(fn()=>$service->close($other,actor()),409,'برجاء إغلاق الفواتير أولًا.');
check(financialState()===$before&&!DB::table('takeaway_tills')->where('branch','f:101')->exists(),'blocked close cannot create an absent branch till');
$all=$service->data(['branch'=>'all'],actor(2));
check($all['report']['pending_invoices']['total']===11&&$all['report']['pending_invoices']['counts']===['dine'=>2,'phone'=>3,'app'=>6],'all-branch status aggregates pending counts exactly once');
check(!isset($all['review_token'])&&privateFieldsAbsent($all['report']),'all-branch pending summary stays read only and omits monetary values');
$store=$service->data(['branch'=>'f:309'],actor());$shubra=$service->data(['branch'=>'f:301'],actor());
check($store['report']['pending_invoices']['counts']['app']===0&&pendingBy($shubra['report'],'app',12)!==null,'storefront app invoice belongs to fulfillment branch Shubra without double counting');
foreach([4,10,11] as $id)denied(fn()=>$service->data(['branch'=>'all'],actor($id)),403);

// Once real invoices are closed the existing continuous drawer flow is unchanged.
DB::table('pos_service_tickets')->where('branch','f:100')->update(['status'=>'paid','payment_status'=>'paid']);
DB::table('orders')->whereIn('id',[10,51,52,54])->update(['status'=>'completed']);
$close=closePayload($service,3);$beforeTill=DB::table('takeaway_tills')->where('branch','f:100')->first();$beforeEntries=DB::table('takeaway_till_entries')->count();
$result=$service->close($close,actor());$saved=$service->receipt($result['closing']['id'],actor());
check(!$result['replayed']&&$saved['drawer_preserved']===true&&$saved['next_shift_opening_cash']==='345.00'&&!isset($saved['till_reset']),'closing resolved invoices preserves the live continuous drawer policy');
$afterTill=DB::table('takeaway_tills')->where('branch','f:100')->first();
check($afterTill->balance_cents===$beforeTill->balance_cents&&$afterTill->revision===$beforeTill->revision&&DB::table('takeaway_till_entries')->count()===$beforeEntries,'successful report does not reset balance revision or write synthetic cash movements');
check(DB::table('branch_shift_closings')->count()===1&&count($printer->calls)===1&&DB::table('branch_shift_sources')->where('source','pos')->where('source_id',1)->count()===1,'successful close claims the paid source once and creates one report and existing print job');

// A new invoice after an earlier clean review must block, while successful-key retries replay.
$next=closePayload($service,4);pendingTicket(100,'dine','open');$before=financialState();
denied(fn()=>$service->close($next,actor()),409,'برجاء إغلاق الفواتير أولًا.');
check(financialState()===$before,'new invoice appearing after clean preview blocks all close state changes');
$replay=$service->close($close,actor());
check($replay['replayed']&&financialState()===$before,'previous successful close replays before new pending invoice guard');
DB::table('pos_service_tickets')->where('id',100)->update(['status'=>'paid','payment_status'=>'paid']);
$next=closePayload($service,5);$service->close($next,actor());
check(DB::table('branch_shift_closings')->count()===2&&DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===34500,'next resolved shift carries the same continuous drawer balance');
Carbon::setTestNow();echo 'SHIFT_PENDING_INVOICES_OK checks='.$checks.PHP_EOL;
