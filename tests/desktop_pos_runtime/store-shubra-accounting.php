<?php
// Exercises the actual accounting services against an isolated in-memory database.
// No production application, configuration or database is bootstrapped.
$runtime = getenv('FASAKHANSTA_TEST_VENDOR_AUTOLOAD') ?: __DIR__.'/vendor/autoload.php';
$loader = require $runtime;
$loader->addPsr4('App\\Services\\', dirname(__DIR__, 2).'/app/Services/', true);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Facade, DB, Schema};
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Services\Dashboard\{BranchShiftClosing, HomeOverview, OperatingDay};

function app($abstract=null) { $c=Container::getInstance();return $abstract?$c->make($abstract):$c; }
function config($key=null,$default=null) { if(is_array($key)){foreach($key as $k=>$v)app('config')->set($k,$v);return;}return app('config')->get($key,$default); }
function now($zone=null) { return \Carbon\Carbon::now($zone); }
function abort_unless($condition,$code,$message='') { if(!$condition)throw new HttpException($code,$message); }
function abort_if($condition,$code,$message='') { if($condition)throw new HttpException($code,$message); }
function abort($code,$message='') { throw new HttpException($code,$message); }
function route($name,$args=[]) { return 'https://test.invalid/'.$name.'?'.http_build_query($args); }
function __($key) { return $key; }
function database_path($path) { return dirname(__DIR__,2).'/database/'.$path; }
class AccountingTestApplication extends Container { public function getLocale() { return 'ar'; } }
class AccountingTestUser extends \Illuminate\Database\Eloquent\Model { protected $table='users';public function can($permission) { return false; } }
class_alias(AccountingTestUser::class,'App\\Models\\User');

$c=new AccountingTestApplication;Container::setInstance($c);$c->instance('config',new \Illuminate\Config\Repository);
$capsule=new Capsule($c);$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);$capsule->setAsGlobal();$capsule->bootEloquent();
$c->instance('db',$capsule->getDatabaseManager());$c->bind('db.schema',fn()=>DB::connection()->getSchemaBuilder());Facade::setFacadeApplication($c);
$c->instance('validator',new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader,'en'),$c));
config(['app.timezone'=>'Africa/Cairo','app.key'=>'isolated-accounting-test-key']);
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-05 15:00:00','Africa/Cairo'));

Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->string('app_scope')->default('fasakhansta');$t->string('status')->default('accepted');$t->unsignedBigInteger('owner_resturant_id')->nullable();$t->unsignedBigInteger('pending_vendor_id')->nullable();$t->timestamps();});
Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');$t->string('status')->default('opened');$t->string('control')->default('show');$t->timestamps();});
Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->string('status')->default('show');});
Schema::create('orders',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->unsignedBigInteger('user_id')->nullable();$t->string('order_no')->nullable();$t->string('type')->default('current');$t->string('status')->default('completed');$t->string('accepted_notify')->nullable();$t->string('delegate_from_out')->nullable();$t->string('payment_type')->default('cash');$t->string('transfer_price_by')->nullable();$t->decimal('total_price',15,2)->default(0);$t->decimal('delivery_price',15,2)->default(0);$t->decimal('user_tax',15,2)->default(0);$t->timestamps();});
Schema::create('carts',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');$t->decimal('price',15,2);$t->decimal('qty',10,3);$t->decimal('updated_total',15,2)->nullable();});
Schema::create('settings',function(Blueprint $t){$t->id();$t->string('name');$t->text('payload');});
Schema::create('pending_vendors',function(Blueprint $t){$t->id();$t->string('status');$t->string('type')->default('delegate');$t->string('profession_key')->nullable();});
Schema::create('go_stores',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id')->unique();$t->string('name')->default('GO store');});
foreach([
 '2026_10_03_060000_create_order_board_clocks.php'=>'CreateOrderBoardClocks',
 '2026_10_03_140000_create_takeaway_pos.php'=>'CreateTakeawayPos',
 '2026_10_03_150000_create_pos_service_tickets.php'=>'CreatePosServiceTickets',
 '2026_10_04_030000_create_branch_expenses.php'=>'CreateBranchExpenses',
 '2026_10_04_100000_create_branch_shift_closings.php'=>'CreateBranchShiftClosings',
 '2026_10_04_210000_create_branch_inventory_recipes.php'=>'CreateBranchInventoryRecipes',
 '2026_10_04_000001_create_pos_branch_print_jobs.php'=>'CreatePosBranchPrintJobs',
] as $file=>$class){require_once database_path('migrations/'.$file);(new $class)->up();}
foreach([[1,'admin'],[10,'vendor'],[11,'vendor'],[20,'user']] as [$id,$type])DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type]);
DB::table('resturants')->insert([
 ['id'=>100,'user_id'=>10,'name'=>'Other physical branch','parent_id'=>null],
 ['id'=>301,'user_id'=>10,'name'=>'شبرا','parent_id'=>null],
 ['id'=>309,'user_id'=>11,'name'=>'المقطم ستور','parent_id'=>301],
 ['id'=>313,'user_id'=>11,'name'=>'مصر الجديده','parent_id'=>null],
]);
DB::table('settings')->insert(['name'=>'service_fees','payload'=>'10']);
$owner=AccountingTestUser::find(1);$storeOwner=AccountingTestUser::find(11);$checks=0;
function check($ok,$message) { global $checks;if(!$ok)throw new RuntimeException($message);$checks++;echo 'PASS '.$message.PHP_EOL; }
function denied(callable $fn,int $status,string $message) { try{$fn();}catch(HttpException $e){check($e->getStatusCode()===$status,$message);return;}throw new RuntimeException('Expected rejection: '.$message); }
function order(int $id,int $branch,array $extra=[]) { DB::table('orders')->insert($extra+['id'=>$id,'resturant_id'=>$branch,'user_id'=>20,'status'=>'completed','type'=>'current','payment_type'=>'cash','transfer_price_by'=>'vendor','total_price'=>'100.00','delivery_price'=>'20.00','user_tax'=>'5.00','created_at'=>'2026-10-05 09:00:00','updated_at'=>'2026-10-05 10:00:00']); }
order(1,309,['updated_at'=>'2026-10-06 10:00:00']);order(2,313,['payment_type'=>'card','transfer_price_by'=>'admin']);order(3,301);
order(4,309,['status'=>'cancelled']);order(5,100);order(6,309,['transfer_price_by'=>'delegate']);
DB::table('branch_recipe_sales')->insert([
 ['branch'=>'f:301','source_type'=>'app','source_id'=>'1','snapshot'=>'{}','created_at'=>'2026-10-05 03:30:00'],
 ['branch'=>'f:309','source_type'=>'app','source_id'=>'1','snapshot'=>'{}','created_at'=>'2026-10-05 03:30:00'],
]);
$shifts=app(BranchShiftClosing::class);$overview=app(HomeOverview::class);
$balances=$shifts->ownerBalances(['f:301','f:309','f:313','f:100'],$owner);
check($balances['f:301']['expected_cents']===23000,'only vendor-collected store and Shubra cash enters Shubra drawer');
check($balances['f:309']['expected_cents']===0&&$balances['f:313']['expected_cents']===0,'store cash is not counted again at the source store');
check($balances['f:100']['expected_cents']===11500,'unrelated physical branch stays isolated');
$report=$shifts->desktopReview(['branch'=>'f:301'],$owner)['snapshot'];
check($report['channels']['app']['count']===4&&$report['app_branch_cash']==='230.00','completed app report excludes cancelled store order');
check($report['app_outside_drawer']==='230.00'&&$report['channels']['app']['delivery']==='80.00','electronic and courier receipts stay outside drawer and delivery is separated');
$home=$overview->data(['branch'=>'f:301'],$owner);
check($home['sales']['count']===4&&$home['sales']['gross_cents']===54000,'Shubra report owns source-store app sales once with original stock completion date');
check($home['owner_drawer']['total_cents']===23000,'dashboard drawer uses the same fulfillment accounting as shift review');
$all=$overview->data([],$owner);$cards=array_column($all['branch_cards'],null,'value');
check($all['sales']['count']===5&&$all['sales']['gross_cents']===67500,'all-branches sales aggregate each source order once');
check($cards['f:301']['sales']['gross_cents']===54000&&$cards['f:309']['sales']['count']===0&&$cards['f:313']['sales']['count']===0,'store and Shubra financial cards do not duplicate each other');
check($overview->data(['branch'=>'f:309'],$owner)['sales']['count']===0,'source-store financial filter does not re-add Shubra sales');
denied(fn()=>$overview->data(['branch'=>'f:301'],$storeOwner),404,'source-store owner cannot open Shubra financial scope');
denied(fn()=>$shifts->ownerBalances(['f:301'],$storeOwner),403,'source-store owner cannot read Shubra live drawer');
check(!array_key_exists('owner_drawer',$overview->data([],$storeOwner)),'source-store actor receives no owner drawer through all-branches response');
check(DB::table('takeaway_tills')->count()===0&&DB::table('takeaway_till_entries')->count()===0,'read-only aggregation moves no historical drawer balances');

// Preserve settled historical store claims, while permitting a later cash handover exactly once.
order(7,309,['transfer_price_by'=>null]);
$closing=DB::table('branch_shift_closings')->insertGetId(['branch'=>'f:309','sequence'=>1,'actor_id'=>1,'request_key'=>'00000000-0000-4000-8000-000000000980','request_hash'=>str_repeat('a',64),'activated_at'=>OperatingDay::start()->utc(),'started_at'=>now('UTC'),'closed_at'=>now('UTC'),'counted_cents'=>11500,'expected_cents'=>11500,'variance_cents'=>0,'till_balance_cents'=>0,'snapshot'=>'{}','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
DB::table('branch_shift_sources')->insert([
 ['closing_id'=>$closing,'branch'=>'f:309','source'=>'app','source_id'=>1],
 ['closing_id'=>$closing,'branch'=>'f:309','source'=>'app_cash','source_id'=>1],
 ['closing_id'=>$closing,'branch'=>'f:309','source'=>'app','source_id'=>7],
]);
check($shifts->ownerBalances(['f:301'],$owner)['f:301']['expected_cents']===11500,'old store cash claim cannot be collected again in Shubra');
DB::table('orders')->where('id',7)->update(['transfer_price_by'=>'vendor','updated_at'=>now('UTC')]);
$review=$shifts->desktopReview(['branch'=>'f:301'],$owner);
check($review['snapshot']['app_branch_cash']==='230.00'&&$review['snapshot']['channels']['app']['count']===3,'late store cash is recognized without repeating an already claimed sale');
check(in_array(['source'=>'app_cash','source_id'=>7],$review['sources'],true)&&!in_array(['source'=>'app','source_id'=>7],$review['sources'],true),'late handover claims the cash source only');
$values=['branch'=>'f:301','idempotency_key'=>'00000000-0000-4000-8000-000000000981','previous_closing_id'=>0,'review_token'=>$shifts->data(['branch'=>'f:301'],$owner)['review_token'],'counted_cash'=>'230.00'];
$close=$shifts->close($values,$owner);check($shifts->close($values,$owner)['replayed'],'retrying Shubra close does not collect source cash twice');
check($shifts->ownerBalances(['f:301'],$owner)['f:301']['expected_cents']===0,'claimed Shubra app cash is not counted in the next reconciliation');
check(DB::table('branch_shift_closings')->where('id',$closing)->value('snapshot')==='{}','historical store closing snapshot stays unchanged');
check($shifts->receipt($close['closing']['id'],$owner)['app_branch_cash']==='230.00','printed closing includes the same Shubra cash amount');
echo $checks.' checks passed against actual Shubra cash and report services'.PHP_EOL;
