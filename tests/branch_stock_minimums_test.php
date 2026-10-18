<?php
/** Isolated SQLite lifecycle/authorization/outbox tests. Uses no .env, network or live mailbox. */
$autoload = getenv('STOCK_MINIMUM_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
if (!is_file($autoload)) throw new RuntimeException('Set STOCK_MINIMUM_TEST_AUTOLOAD to an Illuminate 8 compatible Composer autoloader.');
require $autoload;
spl_autoload_register(function ($class) {
    if (strpos($class, 'App\\') !== 0) return;
    $path = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require_once $path;
}, true, true);

use App\Services\Dashboard\{BranchInventory, BranchStock, BranchStockMinimums};
use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Facade, Schema};
use Symfony\Component\HttpKernel\Exception\HttpException;

function app($abstract = null, array $parameters = []) { $c=Container::getInstance(); return $abstract ? $c->make($abstract, $parameters) : $c; }
function config($key = null, $default = null) { if (is_array($key)) { foreach ($key as $k=>$v) app('config')->set($k,$v); return; } return app('config')->get($key,$default); }
function now($zone = null) { return Carbon::now($zone); }
function abort_unless($condition, $status, $message = '') { if (!$condition) throw new HttpException($status,$message); }
function abort_if($condition, $status, $message = '') { if ($condition) throw new HttpException($status,$message); }
function abort($status, $message = '') { throw new HttpException($status,$message); }
function route($name, $args = []) { return 'https://stock-test.invalid/'.$name.'?'.http_build_query($args); }
function database_path($path = '') { return dirname(__DIR__).'/database/'.$path; }
class MinimumTestUser extends \Illuminate\Database\Eloquent\Model { protected $table='users'; public function can($permission) { return (int)$this->id === 2 && $permission === 'order-list'; } }
class_alias(MinimumTestUser::class, 'App\\Models\\User');
// Only the envelope is substituted: the real service/outbox and real stock writers run below.
class MinimumTestMessage { public array $alert; public function __construct(array $alert) { $this->alert=$alert; } }
class_alias(MinimumTestMessage::class, 'App\\Mail\\BranchStockMinimumAlert');
class MinimumTestMail
{
    public array $sent=[];
    public bool $reject=false;
    public bool $throw=false;
    private string $recipient='';
    public function to($email) { $this->recipient=$email; return $this; }
    public function send($message) { if (DB::transactionLevel()) throw new RuntimeException('SMTP inside stock transaction'); if ($this->throw) throw new RuntimeException('simulated provider outage'); if (!$this->reject) $this->sent[]=['to'=>$this->recipient,'data'=>$message->alert]; }
    public function failures() { return $this->reject ? ['rejected@example.invalid'] : []; }
}
class MinimumTestLog { public array $warnings=[]; public function warning($message,$context) { $this->warnings[]=[$message,$context]; } }
class MinimumTestService extends BranchStockMinimums { protected function deliveryReady(): bool { return true; } }

$container=new Container; Container::setInstance($container);
$container->instance('config',new \Illuminate\Config\Repository);
$capsule=new Capsule($container);
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
$capsule->setAsGlobal(); $capsule->bootEloquent();
$container->instance('db',$capsule->getDatabaseManager());
$container->bind('db.schema',fn()=>DB::connection()->getSchemaBuilder());
$translator=new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader,'en');
$container->instance('validator',new \Illuminate\Validation\Factory($translator,$container));
$mail=new MinimumTestMail; $container->instance('mail.manager',$mail); $container->instance('log',new MinimumTestLog);
Facade::setFacadeApplication($container);
config(['app.timezone'=>'Africa/Cairo','mail.default'=>'array','mail.mailers.array.transport'=>'array']);
Carbon::setTestNow(Carbon::parse('2026-10-18T12:00:00Z'));
Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email')->nullable();$t->string('account_type');$t->string('status')->default('accepted');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
Schema::create('permissions',function(Blueprint $t){$t->id();$t->string('name');$t->string('guard_name');});
DB::table('permissions')->insert(['name'=>'order-list','guard_name'=>'admin']);
Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->string('name');});
Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->string('product_name');$t->string('status')->default('show');});
foreach ([[1,'admin',null,'owner@example.invalid'],[2,'admin',null,'admin@example.invalid'],[4,'admin',100,null],[10,'vendor',null,null],[11,'vendor',null,null]] as [$id,$type,$parent,$email]) DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'owner_resturant_id'=>$parent,'email'=>$email]);
DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'المحلة'],['id'=>101,'user_id'=>11,'name'=>'المنصورة']]);
DB::table('resturant_products')->insert([['id'=>1,'resturant_id'=>100,'product_name'=>'سمك'],['id'=>2,'resturant_id'=>101,'product_name'=>'صنف آخر']]);
Schema::create('pos_service_tickets',fn(Blueprint $t)=>$t->id());
Schema::create('takeaway_orders',fn(Blueprint $t)=>$t->id());
foreach ([
    '2026_10_04_080000_create_branch_operations.php'=>'CreateBranchOperations',
    '2026_10_04_190000_create_branch_stock.php'=>'CreateBranchStock',
    '2026_10_04_210000_create_branch_inventory_recipes.php'=>'CreateBranchInventoryRecipes',
    '2026_10_18_230000_create_branch_stock_minimum_alerts.php'=>'CreateBranchStockMinimumAlerts',
] as $file=>$class) { require_once database_path('migrations/'.$file); (new $class)->up(); }
$service=new MinimumTestService; $container->instance(BranchStockMinimums::class,$service);
$checks=0;
function check($condition,$label) { global $checks; if (!$condition) throw new RuntimeException($label); ++$checks; echo 'PASS '.$label.PHP_EOL; }
function denied(callable $action,int $status,string $label) { try { $action(); } catch (HttpException $e) { check($e->getStatusCode()===$status,$label); return; } throw new RuntimeException('Expected HTTP '.$status.': '.$label); }
function actor(int $id=1) { return MinimumTestUser::findOrFail($id); }
function keyFor(int $id): string { return sprintf('00000000-0000-4000-8000-%012d',$id); }
function setting(int $key,array $extra=[]): array { return $extra+['branch'=>'f:100','stock_type'=>'ingredient','stock_item_id'=>1,'minimum_quantity'=>'2','expected_revision'=>0,'idempotency_key'=>keyFor($key)]; }
function quantity(int $ingredient=1,string $branch='f:100'): int { return (int)DB::table('branch_inventory')->where('branch',$branch)->where('ingredient_id',$ingredient)->value('quantity_units'); }
function supply(string $amount,int $key,int $ingredient=1,string $branch='f:100'): array { return app(BranchInventory::class)->receive(['branch'=>$branch,'ingredient_id'=>$ingredient,'unit'=>$ingredient===9?'piece':'kg','quantity'=>$amount,'idempotency_key'=>keyFor($key)],actor(1)); }
function consume(int $units,int $source,int $ingredient=1): void
{
    DB::transaction(function()use($units,$source,$ingredient){
        $stock=DB::table('branch_inventory')->where('branch','f:100')->where('ingredient_id',$ingredient)->first();
        // Exercise the same recipe/expense movement routine used by actual completed sales.
        $method=new ReflectionMethod(BranchInventory::class,'move');$method->setAccessible(true);
        $i=DB::table('stock_ingredients')->where('id',$ingredient)->first();
        $method->invoke(app(BranchInventory::class),$stock,['id'=>$ingredient,'name'=>$i->name,'unit'=>$i->unit],-$units,'pos',(string)$source,1);
    });
}
function stockItem(int $id=1,string $branch='f:100'): array { foreach(app(BranchInventory::class)->listing(['branch'=>$branch],actor())['items'] as $item)if($item['id']===$id)return $item['stock'];throw new RuntimeException('Missing item'); }

check(DB::table('branch_stock_minimums')->count()===0&&DB::table('branch_stock_minimum_alerts')->count()===0,'migration creates no thresholds or emails');
(new CreateBranchStockMinimumAlerts)->up(); check(DB::table('branch_stock_minimums')->count()===0,'migration retry is idempotent');
denied(fn()=>$service->save(setting(1),actor(4)),403,'branch manager cannot alter minimums');
denied(fn()=>$service->save(setting(1),actor(10)),403,'vendor cannot alter minimums');
denied(fn()=>$service->save(setting(1,['branch'=>'gs:100']),actor()),404,'GO stock cannot receive restaurant minimum');
denied(fn()=>$service->save(setting(1,['minimum_quantity'=>'1e3']),actor()),422,'scientific notation is rejected');
denied(fn()=>$service->save(setting(1,['minimum_quantity'=>'1000001']),actor()),422,'minimum larger than one million is rejected');
denied(fn()=>$service->save(setting(1,['stock_item_id'=>9,'minimum_quantity'=>'0.5']),actor()),422,'piece thresholds require a whole number');

supply('4',10);
$first=$service->save(setting(11),actor());
check(!$first['stock']['low_stock']&&$first['stock']['minimum_quantity']==='2','save keeps above-minimum stock normal');
check($service->save(setting(11),actor())['replayed'],'threshold save is replay safe');
denied(fn()=>$service->save(setting(12),actor()),409,'stale threshold revision is rejected');
check(DB::table('branch_stock_minimum_alerts')->count()===0,'no alert above minimum');
consume(2000000,20);
check(stockItem()['low_stock']&&quantity()===2000000,'exact minimum is low and balances remain exact integers');
check(DB::table('branch_stock_minimum_alerts')->count()===1&&count($mail->sent)===0,'movement creates one durable pending alert without SMTP');
consume(500000,21);$service->reconcile();
check(DB::table('branch_stock_minimum_alerts')->count()===1,'further sales and repeated reconciliation keep one low episode');
$r=$service->dispatch();check($r===['sent'=>1,'failed'=>0]&&$mail->sent[0]['to']==='owner@example.invalid','pending email goes only to canonical owner');
check($mail->sent[0]['data']['quantity']==='1.5'&&$mail->sent[0]['data']['minimum_quantity']==='2'&&$mail->sent[0]['data']['branch_name']==='المحلة','email includes branch item current quantity and minimum');
$service->dispatch();check(count($mail->sent)===1,'scheduler retries do not resend accepted email');
supply('2',22);consume(2000000,23);
check(DB::table('branch_stock_minimum_alerts')->count()===2,'replenishment rearms exactly one new low episode');
$revision=DB::table('branch_stock_minimums')->where('id',1)->value('revision');
$service->save(setting(24,['minimum_quantity'=>null,'expected_revision'=>$revision]),actor());
check(!stockItem()['low_stock']&&stockItem()['minimum_quantity']===null,'null disables minimum and red marker');
check(DB::table('branch_stock_minimum_alerts')->where('id',2)->value('cancelled_at')!==null,'disable cancels queued unsent email');

// An empty raw ingredient has a real zero balance even before its first receipt.
$service->save(setting(30,['stock_item_id'=>2,'minimum_quantity'=>'0']),actor(2));
check(stockItem(2)['low_stock']&&stockItem(2)['minimum_quantity']==='0','zero is an enabled minimum on an empty branch item');
check(!stockItem(2,'f:101')['low_stock'],'threshold is isolated per branch');
DB::table('users')->where('id',1)->update(['email'=>null]);
$failed=$service->dispatch();check($failed['failed']===1&&DB::table('branch_stock_minimum_alerts')->where('id',3)->value('sent_at')===null,'missing owner email stays pending without choosing another admin');
DB::table('users')->where('id',1)->update(['email'=>'updated-owner@example.invalid']);
$service->dispatch();check(count($mail->sent)===1,'failed delivery honors retry backoff');
Carbon::setTestNow(now('UTC')->addMinutes(2));$mail->throw=true;$service->dispatch();
check(DB::table('branch_stock_minimum_alerts')->where('id',3)->value('last_error_code')==='mail_delivery_failed','transport exception is retained for retry without provider secrets');
Carbon::setTestNow(now('UTC')->addMinutes(3));$mail->throw=false;$mail->reject=true;$service->dispatch();
check(DB::table('branch_stock_minimum_alerts')->where('id',3)->value('sent_at')===null,'Swift recipient rejection does not mark mail delivered');
Carbon::setTestNow(now('UTC')->addMinutes(5));$mail->reject=false;
DB::table('branch_stock_minimum_alerts')->where('id',3)->update(['lease_until'=>now('UTC')->addMinutes(1),'lease_token'=>'another-worker']);
$service->dispatch();check(count($mail->sent)===1,'active worker lease prevents concurrent duplicate delivery');
Carbon::setTestNow(now('UTC')->addMinutes(2));$service->dispatch();
check(count($mail->sent)===2&&$mail->sent[1]['to']==='updated-owner@example.invalid','expired lease recovers and recipient uses latest owner profile');

// Recovery above the threshold cancels pending notification; rollback cancels the episode itself.
$service->save(setting(40,['stock_item_id'=>3,'minimum_quantity'=>'0']),actor());supply('1',41,3);
check(DB::table('branch_stock_minimum_alerts')->where('id',4)->value('cancelled_at')!==null,'stock recovery cancels a pending alert');
try { DB::transaction(function(){consume(1000000,42,3);throw new RuntimeException('rollback');}); } catch (RuntimeException $error) {}
check(DB::table('branch_stock_minimum_alerts')->count()===4&&quantity(3)===1000000,'rollback restores stock and discards low episode');
DB::table('branch_inventory')->where('branch','f:100')->where('ingredient_id',3)->update(['quantity_units'=>0]);$service->reconcile();
check(DB::table('branch_stock_minimum_alerts')->count()===5&&stockItem(3)['low_stock'],'scheduler catches synced balances without movement hook');
DB::transaction(fn()=>denied(fn()=>$service->dispatch(),503,'dispatch refuses SMTP from an enclosing stock transaction'));
$real=new BranchStockMinimums;$before=count($mail->sent);$result=$real->dispatch();
check($result['failed']===1&&count($mail->sent)===$before,'production log or array transport is not accepted as delivery');
config(['desktop_dashboard.local'=>true]);check($service->reconcile()===0&&$service->dispatch()===['sent'=>0,'failed'=>0],'offline desktop never sends owner email');config(['desktop_dashboard.local'=>false]);

// Legacy direct product balances share the feature while retaining their existing units.
DB::table('branch_stock')->insert(['branch'=>'f:100','product_id'=>1,'unit'=>'piece','quantity_units'=>1000000,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$legacy=$service->save(setting(50,['stock_type'=>'product','stock_item_id'=>1,'minimum_quantity'=>'1']),actor());
check($legacy['stock']['low_stock']&&app(BranchStock::class)->directBalances('f:100',[1])[1]['low_stock'],'legacy product minimum is decorated on direct stock balance');
denied(fn()=>$service->save(setting(51,['stock_type'=>'product','stock_item_id'=>2]),actor()),404,'product minimum cannot target another branch product');
DB::table('users')->where('id',1)->update(['account_type'=>'vendor']);
$service->save(setting(52,['stock_item_id'=>4,'minimum_quantity'=>'0']),actor(2));
Carbon::setTestNow(now('UTC')->addHours(2));$service->dispatch();
check(count($mail->sent)===$before,'canonical owner role is rechecked and no administrator fallback is mailed');
// Editing the threshold must not create repeated mail while the same low episode remains active.
DB::table('users')->where('id',1)->update(['account_type'=>'admin']);
supply('5',60,6);
$beforeAlerts=DB::table('branch_stock_minimum_alerts')->count();
$service->save(setting(61,['stock_item_id'=>6,'minimum_quantity'=>'5']),actor());
$service->save(setting(62,['stock_item_id'=>6,'minimum_quantity'=>'5','expected_revision'=>1]),actor());
$service->save(setting(63,['stock_item_id'=>6,'minimum_quantity'=>'6','expected_revision'=>2]),actor());
check(DB::table('branch_stock_minimum_alerts')->count()===$beforeAlerts+1,'same or higher minimum preserves the existing low episode');
$service->save(setting(64,['stock_item_id'=>6,'minimum_quantity'=>'4','expected_revision'=>3]),actor());
check(!stockItem(6)['low_stock']&&DB::table('branch_stock_minimum_alerts')->where('minimum_id',DB::table('branch_stock_minimums')->where('stock_item_id',6)->value('id'))->whereNull('cancelled_at')->count()===0,'lowering minimum below current quantity clears low state and pending mail');
$service->save(setting(65,['stock_item_id'=>6,'minimum_quantity'=>'5','expected_revision'=>4]),actor());
check(DB::table('branch_stock_minimum_alerts')->count()===$beforeAlerts+2,'a subsequent threshold crossing creates one new episode');
Carbon::setTestNow();
echo 'STOCK_MINIMUM_TESTS_OK checks='.$checks.PHP_EOL;
