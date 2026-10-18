<?php
/** Isolated real-service checks. BRANCH_SCOPE_TEST_AUTOLOAD=/path/to/vendor/autoload.php php tests/dashboard_stock_shift_all_branches_test.php */
$autoload = getenv('BRANCH_SCOPE_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
if (!is_file($autoload)) throw new RuntimeException('Set BRANCH_SCOPE_TEST_AUTOLOAD to an Illuminate 8 compatible Composer autoloader.');
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
class BranchScopeApplication extends Container { public function getLocale() { return 'ar'; } }
class BranchScopeUser extends \Illuminate\Database\Eloquent\Model { protected $table = 'users'; public function can($permission) { return (int)$this->id===2&&$permission==='order-list'; } }
class_alias(BranchScopeUser::class, 'App\\Models\\User');

$container = new BranchScopeApplication;
Container::setInstance($container);
$container->instance('config', new \Illuminate\Config\Repository);
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
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


config(['app.key'=>'scope-test-key']);
Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
Schema::create('permissions',function(Blueprint $t){$t->id();$t->string('name');$t->string('guard_name');});
DB::table('permissions')->insert(['name'=>'order-list','guard_name'=>'admin']);
foreach([[1,'admin',null],[2,'admin',null],[4,'admin',100],[10,'vendor',null],[11,'vendor',null],[12,'resturant_owner',100]] as [$id,$type,$parent])DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'owner_resturant_id'=>$parent]);
Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');});
DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'Main'],['id'=>101,'user_id'=>11,'name'=>'Other']]);
Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->unsignedBigInteger('product_id')->nullable();$t->string('product_name');$t->string('status')->default('show');});
DB::table('resturant_products')->insert([['id'=>1,'resturant_id'=>100,'product_name'=>'Fish'],['id'=>2,'resturant_id'=>101,'product_name'=>'Other fish']]);
Schema::create('branch_operation_commands',function(Blueprint $t){$t->id();$t->string('branch');$t->unsignedBigInteger('actor_id');$t->string('request_key');$t->string('request_hash');$t->string('kind');$t->text('result');$t->timestamps();});
Schema::create('branch_payrolls',function(Blueprint $t){$t->id();});
Schema::create('orders',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->string('type')->default('current');$t->string('status');$t->string('payment_type')->default('cash');$t->string('transfer_price_by')->nullable();$t->decimal('total_price',14,2)->default(0);$t->decimal('delivery_price',14,2)->default(0);$t->decimal('user_tax',14,2)->default(0);$t->timestamps();});
foreach(['2026_10_03_140000_create_takeaway_pos.php'=>'CreateTakeawayPos','2026_10_04_030000_create_branch_expenses.php'=>'CreateBranchExpenses','2026_10_04_100000_create_branch_shift_closings.php'=>'CreateBranchShiftClosings','2026_10_04_190000_create_branch_stock.php'=>'CreateBranchStock'] as $file=>$class){require_once database_path('migrations/'.$file);(new $class)->up();}
function check($ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
function denied(callable $call,int $status):void {try{$call();}catch(HttpException $e){check($e->getStatusCode()===$status,'scope rejection '.$status);return;}catch(ValidationException $e){check($status===422,'write validation rejects all');return;}throw new RuntimeException('Expected rejection');}
function actor(int $id=1){return BranchScopeUser::findOrFail($id);}
function keyFor(int $id):string{return sprintf('00000000-0000-4000-8000-%012d',$id);}
function receipt(int $id,string $branch,int $product):array{return ['branch'=>$branch,'product_id'=>$product,'quantity'=>'2.5','unit'=>'kg','idempotency_key'=>keyFor($id)];}
$stock=app(\App\Services\Dashboard\BranchStock::class);
$stock->receive(receipt(1,'f:100',1),actor());$stock->receive(receipt(2,'f:101',2),actor());
foreach([1,2] as $id){$all=$stock->listing(['branch'=>'all'],actor($id));check(count($all['items'])===2&&array_unique(array_column($all['items'],'branch'))===['f:100','f:101'],'owner/admin sees both SKU branches');check(count($all['history'])===2&&isset($all['history'][0]['branch_name']),'SKU history carries branch names');}
// Global pagination must include SKU pages beyond each branch's first 100 rows.
$extra=[];for($i=0;$i<105;$i++)foreach([100,101] as $branch)$extra[]=['id'=>1000+$i*2+($branch-100),'resturant_id'=>$branch,'product_name'=>'Extra '.$i,'status'=>'show'];
DB::table('resturant_products')->insert($extra);$page=$stock->listing(['branch'=>'all','page'=>3],actor());check($page['pagination']['total']===212&&count($page['items'])===12,'combined SKU pagination retains later branch pages');DB::table('resturant_products')->where('id','>=',1000)->delete();
foreach([4,10,12] as $id)denied(fn()=>$stock->listing(['branch'=>'all'],actor($id)),403);
$forged=actor(10);$forged->account_type='admin';denied(fn()=>$stock->listing(['branch'=>'all'],$forged),403);
$before=DB::table('branch_stock_movements')->count();denied(fn()=>$stock->receive(receipt(3,'all',1),actor()),422);check(DB::table('branch_stock_movements')->count()===$before,'all receipt never writes');
require_once database_path('migrations/2026_10_04_210000_create_branch_inventory_recipes.php');(new \CreateBranchInventoryRecipes)->up();
$ingredient=(int)DB::table('stock_ingredients')->where('name','فلفل')->value('id');
foreach([['f:100','1.25'],['f:101','4.5']] as $index=>[$branch,$qty])$stock->receive(['branch'=>$branch,'ingredient_id'=>$ingredient,'unit'=>'kg','quantity'=>$qty,'idempotency_key'=>keyFor(10+$index)],actor());
$all=$stock->listing(['branch'=>'all','search'=>'فلفل'],actor(2));check(array_column($all['items'],'branch')===['f:100','f:101']&&array_column(array_column($all['items'],'stock'),'quantity')===['1.25','4.5'],'ingredient balances stay distinct across branches');check(count($all['history'])===2&&$all['history'][0]['branch_name']==='Other','ingredient history is combined and labeled');
check(!$all['can_manage_recipes']&&$all['branch']['value']==='all','all stock listing stays read only');
$inventory=app(\App\Services\Dashboard\BranchInventory::class);denied(fn()=>$inventory->recipes(['branch'=>'all'],actor()),404);denied(fn()=>$stock->receive(['branch'=>'all','ingredient_id'=>$ingredient,'unit'=>'kg','quantity'=>'1','idempotency_key'=>keyFor(20)],actor()),422);
require_once database_path('migrations/2026_10_18_210000_create_shift_report_printing_and_numbers.php');(new \CreateShiftReportPrintingAndNumbers)->up();
$shifts=app(\App\Services\Dashboard\BranchShiftClosing::class);
DB::table('takeaway_tills')->insert([['branch'=>'f:100','balance_cents'=>34500,'tax_bps'=>0,'revision'=>1],['branch'=>'f:101','balance_cents'=>78900,'tax_bps'=>0,'revision'=>1]]);
foreach([1,2] as $id){$all=$shifts->data(['branch'=>'all'],actor($id));check(count($all['branch_reports'])===2&&array_column(array_column($all['branch_reports'],'branch'),'value')===['f:100','f:101'],'owner/admin sees status for every shift');check(!isset($all['review_token']),'all shift preview cannot authorize closure');foreach(array_merge([$all['report']],$all['branch_reports']) as $report)foreach(['expected_cash','counted_cash','channels','sales_total','variance','till_balance'] as $key)check(!array_key_exists($key,$report),'private shift field omitted: '.$key);}
foreach([4,10,12] as $id)denied(fn()=>$shifts->data(['branch'=>'all'],actor($id)),403);
$preview=$shifts->data(['branch'=>'f:100'],actor());$close=['branch'=>'f:100','review_token'=>$preview['review_token'],'previous_closing_id'=>0,'counted_cash'=>'345.00','idempotency_key'=>keyFor(30)];
$shifts->close($close,actor());$all=$shifts->data(['branch'=>'all'],actor(2));check(count($all['history'])===1&&$all['history'][0]['branch_name']==='Main','all shift history identifies its branch');check(!isset($all['history'][0]['counted_cash'])&&!isset($all['history'][0]['snapshot']),'all shift history excludes receipt totals');
check((int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===34500&&(int)DB::table('takeaway_tills')->where('branch','f:101')->value('balance_cents')===78900,'explicit closure preserves both branch cash balances');
$count=DB::table('branch_shift_closings')->count();denied(fn()=>$shifts->close(array_replace($close,['branch'=>'all','idempotency_key'=>keyFor(31)]),actor()),404);check(DB::table('branch_shift_closings')->count()===$count,'all shift closure never writes');
echo "STOCK_SHIFT_ALL_BRANCHES_OK".PHP_EOL;
