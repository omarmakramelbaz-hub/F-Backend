<?php
/**
 * Exercises shift reporting, branch print claims and order numbering against an isolated SQLite database.
 * No application bootstrap, .env, credentials, network, or production database is used.
 * Run with PHP 8+: SHIFT_TEST_AUTOLOAD=/path/to/vendor/autoload.php php tests/expense_stock_integration_test.php
 */
$autoload = getenv('SHIFT_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
if (!is_file($autoload)) throw new RuntimeException('Set SHIFT_TEST_AUTOLOAD to an Illuminate 8 compatible Composer autoloader.');
require $autoload;
spl_autoload_register(function ($class) {
    if (strpos($class, 'App\\') !== 0) return;
    $path = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require_once $path;
}, true, true);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Facade, Schema};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Services\Dashboard\{BranchExpenses, ExpenseCategories, ExpenseStockPurchases, PosServiceTicket};

function app($abstract = null, array $parameters = []) { $c = Container::getInstance(); return $abstract ? $c->make($abstract, $parameters) : $c; }
function config($key = null, $default = null) { if (is_array($key)) { foreach ($key as $k => $v) app('config')->set($k, $v); return; } return app('config')->get($key, $default); }
function now($zone = null) { return \Carbon\Carbon::now($zone); }
function abort_unless($value, $status, $message = '') { if (!$value) throw new HttpException($status, $message); }
function abort_if($value, $status, $message = '') { if ($value) throw new HttpException($status, $message); }
function abort($status, $message = '') { throw new HttpException($status, $message); }
function route($name, $args = []) { return 'https://expense-test.invalid/'.$name.'?'.http_build_query($args); }
function database_path($path = '') { return dirname(__DIR__).'/database/'.$path; }
function trans($key, $replace = [], $locale = null) { return app('translator')->get($key, $replace, $locale); }
function __($key, $replace = [], $locale = null) { return trans($key, $replace, $locale); }
class ExpenseStockApplication extends Container { public function getLocale() { return 'ar'; } }
class ExpenseStockUser extends \Illuminate\Database\Eloquent\Model { protected $table = 'users'; public function can($permission) { return (int)$this->id===2&&$permission==='order-list'; } }
class_alias(ExpenseStockUser::class, 'App\\Models\\User');

$container = new ExpenseStockApplication;
Container::setInstance($container);
$container->instance('config', new \Illuminate\Config\Repository);
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
$capsule->setAsGlobal();
$capsule->bootEloquent();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn () => DB::connection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
$loader = new \Illuminate\Translation\ArrayLoader;
foreach (['ar', 'en'] as $locale) $loader->addMessages($locale, 'expenses', require dirname(__DIR__).'/resources/lang/'.$locale.'/expenses.php');
$translator = new \Illuminate\Translation\Translator($loader, 'ar');
$container->instance('translator', $translator);
$container->instance('validator', new \Illuminate\Validation\Factory($translator, $container));
config(['app.timezone' => 'Africa/Cairo']);
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-18T12:00:00Z'));

use App\Services\Dashboard\{BranchShiftClosing,BranchShiftPrinting,BranchOrderSequence,TakeawayAccess};
config(['app.key'=>'isolated-shift-test-key']);
Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
Schema::create('permissions',function(Blueprint $t){$t->id();$t->string('name');$t->string('guard_name');});
DB::table('permissions')->insert(['name'=>'order-list','guard_name'=>'admin']);
Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');});
Schema::create('orders',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->string('type')->default('current');$t->string('status')->default('completed');$t->string('payment_type')->default('cash');$t->string('transfer_price_by')->nullable();$t->decimal('total_price',14,2)->default(0);$t->decimal('delivery_price',14,2)->default(0);$t->decimal('user_tax',14,2)->default(0);$t->string('order_no')->nullable();$t->timestamps();});
Schema::create('carts',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');$t->decimal('price',14,2);$t->decimal('updated_total',14,2)->nullable();$t->decimal('qty',10,3);});
Schema::create('settings',function(Blueprint $t){$t->id();$t->string('name');$t->text('payload');});
DB::table('settings')->insert(['name'=>'service_fees','payload'=>'0']);
foreach([[1,'admin',null],[2,'admin',null],[4,'admin',100],[10,'vendor',null],[11,'vendor',null],[12,'resturant_owner',100]] as [$id,$type,$parent])DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'owner_resturant_id'=>$parent]);
DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'Mahalla'],['id'=>101,'user_id'=>11,'name'=>'Mansoura']]);
foreach([
 '2026_10_03_140000_create_takeaway_pos.php'=>'CreateTakeawayPos',
 '2026_10_03_150000_create_pos_service_tickets.php'=>'CreatePosServiceTickets',
 '2026_10_04_060000_lock_pos_service_bills.php'=>'LockPosServiceBills',
 '2026_10_04_030000_create_branch_expenses.php'=>'CreateBranchExpenses',
 '2026_10_04_100000_create_branch_shift_closings.php'=>'CreateBranchShiftClosings',
 '2026_10_18_210000_create_shift_report_printing_and_numbers.php'=>'CreateShiftReportPrintingAndNumbers',
] as $file=>$class){require_once database_path('migrations/'.$file);(new $class)->up();}
(new CreateShiftReportPrintingAndNumbers)->up(); // Partial/repeated installation is harmless.
function actor(int $id=1){return ExpenseStockUser::findOrFail($id);}
function keyFor(int $id):string{return sprintf('00000000-0000-4000-8000-%012d',$id);}
$checks=0;
function check($value,string $message):void{global $checks;if(!$value)throw new RuntimeException($message);++$checks;echo 'PASS '.$message.PHP_EOL;}
function denied(callable $call,int $code,string $message):void{try{$call();}catch(HttpException $e){check($e->getStatusCode()===$code,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
function values(int $key,string $cash='0.00',string $branch='f:100',int $actor=1):array{$d=app(BranchShiftClosing::class)->data(['branch'=>$branch],actor($actor));return ['branch'=>$branch,'idempotency_key'=>keyFor($key),'previous_closing_id'=>$d['previous_closing_id'],'review_token'=>$d['review_token'],'counted_cash'=>$cash];}
function balance(string $branch='f:100'):int{return app(BranchShiftClosing::class)->ownerBalances([$branch],actor())[$branch]['expected_cents'];}
function sale(int $key,string $channel,int $cash,int $delivery=0,string $branch='f:100'):void{
 $till=DB::table('takeaway_tills')->where('branch',$branch)->first();
 $id=DB::table('takeaway_orders')->insertGetId(['branch'=>$branch,'till_id'=>$till->id,'actor_id'=>10,'request_key'=>keyFor($key),'request_hash'=>str_repeat('a',64),'quote_hash'=>str_repeat('b',64),'business_date'=>'2026-10-18','payment_method'=>'cash','payment_confirmed'=>true,'subtotal_cents'=>$cash-$delivery,'discount_cents'=>0,'tax_cents'=>0,'total_cents'=>$cash,'cash_received_cents'=>$cash,'change_cents'=>0,'tax_bps'=>0,'branch_snapshot'=>'{}','cashier_snapshot'=>'{}','channel'=>$channel,'delivery_cents'=>$delivery,'service_cents'=>0,'service_bps'=>0,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
 DB::table('takeaway_tills')->where('id',$till->id)->update(['balance_cents'=>$till->balance_cents+$cash]);
 DB::table('takeaway_till_entries')->insert(['till_id'=>$till->id,'branch'=>$branch,'actor_id'=>10,'order_id'=>$id,'request_key'=>keyFor($key),'request_hash'=>str_repeat('a',64),'kind'=>'sale','amount_cents'=>$cash,'balance_cents'=>$till->balance_cents+$cash,'business_date'=>'2026-10-18','note'=>'Sale','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
}
DB::table('takeaway_tills')->insert([['branch'=>'f:100','balance_cents'=>10000,'tax_bps'=>0,'revision'=>7],['branch'=>'f:101','balance_cents'=>20000,'tax_bps'=>0,'revision'=>3]]);
$shift=app(BranchShiftClosing::class);$printing=app(BranchShiftPrinting::class);$numbers=app(BranchOrderSequence::class);
check($numbers->next('f:100','takeaway')===[],'old numbering survives until the first close');
foreach([4,10,11,12] as $id){check(!$shift->canClose(actor($id))&&$shift->branches(actor($id))===[],'branch account '.$id.' cannot see shift closing');denied(fn()=>$shift->close([],actor($id)),403,'branch account '.$id.' cannot invoke close directly');denied(fn()=>$shift->data(['branch'=>'f:100'],actor($id)),403,'branch account '.$id.' cannot retrieve closing data');}
$forged=actor(4);$forged->account_type='admin';$forged->owner_resturant_id=null;check(!$shift->canClose($forged),'persisted account defeats a forged management actor');
check($shift->canClose(actor(1))&&$shift->canClose(actor(2)),'owner and administrative admin can close');
sale(10,'phone',12000,2000);sale(11,'dine',3000);
DB::table('orders')->insert(['resturant_id'=>100,'total_price'=>'50.00','delivery_price'=>'5.00','payment_type'=>'cash','transfer_price_by'=>'vendor','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
check(balance()===28000,'expected cash includes opening float, all POS sales, app cash and excludes delivery');
$cashBefore=DB::table('takeaway_tills')->get()->toJson();$entriesBefore=DB::table('takeaway_till_entries')->get()->toJson();
$v=values(100,'279.00');$first=$shift->close($v,actor());$paper=$shift->receipt($first['closing']['id'],actor());
check($paper['expected_cash']==='280.00'&&$paper['variance']==='-1.00'&&$paper['next_shift_opening_cash']==='280.00','receipt records the counted variance and carried expected cash');
check($paper['drawer_preserved']===true&&!isset($paper['till_reset']),'new receipt explicitly records report-only closing');
check(DB::table('takeaway_tills')->get()->toJson()===$cashBefore&&DB::table('takeaway_till_entries')->get()->toJson()===$entriesBefore,'closing does not mutate any drawer, revision or cash ledger');
check(balance()===28000&&balance('f:101')===20000,'owner balance remains identical and other branch is untouched');
check(DB::table('branch_shift_print_jobs')->count()===1,'close atomically queues exactly one report at the chosen branch');
check($shift->close($v,actor())['replayed']&&DB::table('branch_shift_print_jobs')->count()===1,'retrying close cannot print twice');
check($shift->recover(['branch'=>'f:100','idempotency_key'=>keyFor(100)],actor())['found'],'uncertain close is recoverable');
foreach(['takeaway','dine','phone','app'] as $channel){$n=$numbers->next('f:100',$channel);check($n===['shift_sequence'=>2,'shift_order_number'=>1],$channel.' starts at one after closing');check($numbers->next('f:100',$channel)['shift_order_number']===2,$channel.' advances independently');}
check($numbers->next('f:101','takeaway')===[],'closing Mahalla does not reset Mansoura numbering');
$jobs=$printing->listing(['branch'=>'f:100'],actor(10));$job=$jobs['jobs'][0]['id'];
denied(fn()=>$printing->listing(['branch'=>'f:100'],actor(11)),404,'other branch cannot read the print queue');
denied(fn()=>$printing->listing(['branch'=>'f:100'],actor()),403,'owner device cannot claim branch printing');
denied(fn()=>$shift->receipt($first['closing']['id'],actor(10)),403,'branch cannot bypass job claims by opening management receipts');
$claim=['branch'=>'f:100','job_id'=>$job,'claim_token'=>keyFor(200)];$claimed=$printing->claim($claim,actor(10));check(strpos($claimed['print_url'],'branch-shift-print.receipt')!==false,'branch claim returns the dedicated report route');
denied(fn()=>$printing->claim($claim,actor(10)),409,'even the same claim token cannot invoke a second print');
denied(fn()=>$printing->receipt($job,keyFor(201),actor(10)),403,'wrong print capability cannot read report');
$printed=$printing->receipt($job,keyFor(200),actor(10));check($printed['expected_cash']===$paper['expected_cash'],'branch printer receives the immutable report');
$printing->complete($claim+['result'=>'invoked'],actor(10));$printing->complete($claim+['result'=>'invoked'],actor(10));
check($printing->listing(['branch'=>'f:100'],actor(10))['jobs']===[],'completed print leaves the pending queue');
denied(fn()=>$printing->receipt($job,keyFor(200),actor(10)),409,'used print capability cannot retrieve another copy');
denied(fn()=>$printing->reprint(['closing_id'=>$first['closing']['id'],'idempotency_key'=>keyFor(210)],actor(10)),403,'branch cannot enqueue reprints or close reports');
$rp=['closing_id'=>$first['closing']['id'],'idempotency_key'=>keyFor(210)];$printing->reprint($rp,actor(2));$printing->reprint($rp,actor(2));check(DB::table('branch_shift_print_jobs')->count()===2,'admin reprint is idempotent and still routed to the branch');
// New shift financial activity is counted once, while previous adjustments continue to carry.
sale(12,'takeaway',2500);check(balance()===30500,'new sale increases carried balance without replaying previous app cash');
$second=$shift->close(values(101,'305.00','f:100',2),actor(2));$secondPaper=$shift->receipt($second['closing']['id'],actor(2));
check($secondPaper['opening_cash']==='280.00'&&$secondPaper['expected_cash']==='305.00'&&$secondPaper['channels']['phone']['count']===0&&$secondPaper['channels']['takeaway']['count']===1,'second report carries opening cash and lists only newly claimed sales');
check($numbers->next('f:100','takeaway')===['shift_sequence'=>3,'shift_order_number'=>1],'a second close starts the next sequence without deleting history');
check($shift->receipt($first['closing']['id'],actor())===$paper,'old report remains byte-for-byte immutable');
$pendingValues=values(102,'305.00');sale(13,'takeaway',1000);denied(fn()=>$shift->close($pendingValues,actor()),409,'activity during cash counting requires a fresh review');
check(DB::table('branch_shift_closings')->count()===2,'stale review creates no report or print job');
// Roll back the report and sources too if its print command cannot be queued.
DB::unprepared("CREATE TEMP TRIGGER fail_shift_print BEFORE INSERT ON branch_shift_print_jobs BEGIN SELECT RAISE(ABORT,'shift print test failure'); END");
try{$shift->close(values(103,'315.00'),actor());throw new RuntimeException('Expected queue failure');}catch(\Illuminate\Database\QueryException $e){check(strpos($e->getMessage(),'shift print test failure')!==false,'queue failure propagates');}finally{DB::unprepared('DROP TRIGGER fail_shift_print');}
check(DB::table('branch_shift_closings')->count()===2&&balance()===31500,'queue failure rolls back report claims while retaining cash');
// Legacy real resets retain their zero boundary; no historical cash is resurrected.
DB::table('takeaway_tills')->where('branch','f:101')->update(['balance_cents'=>0]);
$legacy=['branch'=>'f:101','sequence'=>1,'actor_id'=>1,'request_key'=>keyFor(500),'request_hash'=>str_repeat('a',64),'activated_at'=>now('UTC'),'started_at'=>now('UTC'),'closed_at'=>now('UTC'),'counted_cents'=>20000,'expected_cents'=>20000,'variance_cents'=>0,'till_balance_cents'=>20000,'snapshot'=>json_encode(['till_reset'=>['before_cents'=>20000,'after_cents'=>0]]),'created_at'=>now('UTC'),'updated_at'=>now('UTC')];DB::table('branch_shift_closings')->insert($legacy);
check(balance('f:101')===0,'legacy resets are not reversed during upgrade');
// Use the real Order model creating hook: customer API and dashboard share its order_no.
$order=\App\Models\Order::withoutGlobalScopes()->create(['resturant_id'=>100,'type'=>'current','status'=>'pending']);
check($order->order_no==='R100-S3-0001'&&(int)$order->shift_order_number===1,'real app order receives a unique new shift number');
check(\App\Services\Dashboard\BranchOrderSequence::display((object)['shift_sequence'=>3,'shift_order_number'=>1],'f:100','phone','old')==='PH100-S3-0001','channel and shift labels distinguish repeated sequence numbers');
// Exercise the real POS creation wiring, beyond the counter service itself.
Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->string('product_name');$t->decimal('product_price',14,2);$t->string('status');$t->text('price');});
DB::table('resturant_products')->insert(['id'=>1,'resturant_id'=>100,'product_name'=>'Fish','product_price'=>'100.00','status'=>'show','price'=>'{}']);
$cart=['branch'=>'f:100','items'=>[['product_id'=>1,'quantity'=>'1','quantity_mode'=>'piece','option_id'=>'']],'customer_phone'=>'01012345678'];
$sales=app(\App\Services\Dashboard\TakeawayService::class);$quote=$sales->quote($cart,actor(10));
$payment=$cart+['idempotency_key'=>keyFor(600),'quote_hash'=>$quote['quote_hash'],'payment_method'=>'cash','payment_confirmed'=>true,'cash_received'=>'100.00'];
$saved=$sales->checkout($payment,actor(10));
check($saved['receipt']['number']==='TA100-S3-0002','real takeaway checkout persists the new branch shift number');
check($sales->checkout($payment,actor(10))['receipt']['number']===$saved['receipt']['number'],'replaying checkout preserves the original assigned number');
$table=app(\App\Services\Dashboard\PosServiceTable::class)->configure(['branch'=>'f:100','idempotency_key'=>keyFor(610),'name'=>'T1','capacity'=>4,'active'=>true],actor());
$tableId=DB::table('pos_service_tables')->value('id');$tickets=app(PosServiceTicket::class);
$dining=$cart+['idempotency_key'=>keyFor(611),'table_id'=>$tableId,'guest_count'=>1,'waiter_name'=>'Waiter','customer_name'=>'Customer'];
$dining['quote_hash']=$tickets->quote('dine',$dining,actor(10))['quote_hash'];$bill=$tickets->save('dine',$dining,actor(10));
check($bill['ticket']['number']==='DI100-S3-0001','real dining creation stores its separate shift sequence');
$closedAgain=$shift->close(values(612,'415.00'),actor());
$ticketRow=DB::table('pos_service_tickets')->where('id',$bill['ticket']['id'])->first();
check($numbers->forSale('f:100','dine',(int)$ticketRow->id)===['shift_sequence'=>3,'shift_order_number'=>1],'payment after a close retains the dining ticket number from its original shift');
$beforeExpense=balance();$till=DB::table('takeaway_tills')->where('branch','f:100')->first();
DB::table('takeaway_tills')->where('id',$till->id)->update(['balance_cents'=>$till->balance_cents-1000]);
DB::table('takeaway_till_entries')->insert(['till_id'=>$till->id,'branch'=>'f:100','actor_id'=>1,'request_key'=>keyFor(620),'request_hash'=>str_repeat('e',64),'kind'=>'expense','amount_cents'=>-1000,'balance_cents'=>$till->balance_cents-1000,'business_date'=>'2026-10-18','note'=>'Expense','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$expenseId=DB::table('branch_expenses')->insertGetId(['branch'=>'f:100','actor_id'=>1,'occurred_on'=>'2026-10-18','category'=>'purchases','description'=>'Expense','amount_cents'=>1000,'payment_method'=>'cash','status'=>'approved','revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
DB::table('branch_expense_commands')->insert(['branch'=>'f:100','actor_id'=>1,'request_key'=>keyFor(621),'request_hash'=>str_repeat('e',64),'expense_id'=>$expenseId,'kind'=>'create','revision'=>1,'snapshot'=>json_encode(['status'=>'approved','payment_method'=>'cash','amount_cents'=>1000]),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
check(balance()===$beforeExpense-1000,'new approved cash expense is deducted once from the carried drawer');
$expenseClose=$shift->close(values(622,'0.00'),actor());$expensePaper=$shift->receipt($expenseClose['closing']['id'],actor());
check($expensePaper['expenses_total']==='10.00'&&balance()===$beforeExpense-1000,'expense close preserves balance and does not deduct the expense twice');
DB::table('takeaway_tills')->where('branch','f:101')->update(['balance_cents'=>-5000]);
$negative=$shift->close(values(630,'0.00','f:101'),actor());
check(balance('f:101')===-5000&&$shift->receipt($negative['closing']['id'],actor())['expected_cash']==='-50.00','overdrawn cash carries as a negative balance after report-only closing');
echo 'SHIFT_REPORT_INTEGRATION_OK checks='.$checks.PHP_EOL;
