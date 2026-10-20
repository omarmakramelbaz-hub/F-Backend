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
    public $duringSend=null;
    private string $recipient='';
    public function to($email) { $this->recipient=$email; return $this; }
    public function send($message) { if (DB::transactionLevel()) throw new RuntimeException('SMTP inside stock transaction'); if ($this->throw) throw new RuntimeException('simulated provider outage'); if($this->duringSend){$action=$this->duringSend;$this->duringSend=null;$action();} if (!$this->reject) $this->sent[]=['to'=>$this->recipient,'data'=>$message->alert]; }
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
Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');});
Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->string('product_name');$t->string('status')->default('show');});
foreach ([[1,'admin',null,'owner@example.invalid'],[2,'admin',null,'admin@example.invalid'],[4,'admin',94,null],[10,'vendor',null,null],[11,'vendor',null,null]] as [$id,$type,$parent,$email]) DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'owner_resturant_id'=>$parent,'email'=>$email]);
DB::table('resturants')->insert([['id'=>94,'user_id'=>10,'name'=>'المحلة'],['id'=>307,'user_id'=>11,'name'=>'المنصورة']]);
DB::table('resturant_products')->insert([['id'=>1,'resturant_id'=>94,'product_name'=>'سمك'],['id'=>2,'resturant_id'=>307,'product_name'=>'صنف آخر']]);
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
function setting(int $key,array $extra=[]): array { return $extra+['branch'=>'f:94','stock_type'=>'ingredient','stock_item_id'=>1,'minimum_quantity'=>'2','expected_revision'=>0,'idempotency_key'=>keyFor($key)]; }
function quantity(int $ingredient=1,string $branch='f:94'): int { return (int)DB::table('branch_inventory')->where('branch',$branch)->where('ingredient_id',$ingredient)->value('quantity_units'); }
function supply(string $amount,int $key,int $ingredient=1,string $branch='f:94'): array { return app(BranchInventory::class)->receive(['branch'=>$branch,'ingredient_id'=>$ingredient,'unit'=>$ingredient===9?'piece':'kg','quantity'=>$amount,'idempotency_key'=>keyFor($key)],actor(1)); }
function consume(int $units,int $source,int $ingredient=1): void
{
    DB::transaction(function()use($units,$source,$ingredient){
        $stock=DB::table('branch_inventory')->where('branch','f:94')->where('ingredient_id',$ingredient)->first();
        // Exercise the same recipe/expense movement routine used by actual completed sales.
        $method=new ReflectionMethod(BranchInventory::class,'move');$method->setAccessible(true);
        $i=DB::table('stock_ingredients')->where('id',$ingredient)->first();
        $method->invoke(app(BranchInventory::class),$stock,['id'=>$ingredient,'name'=>$i->name,'unit'=>$i->unit],-$units,'pos',(string)$source,1);
    });
}
function stockItem(int $id=1,string $branch='f:94'): array { foreach(app(BranchInventory::class)->listing(['branch'=>$branch],actor())['items'] as $item)if($item['id']===$id)return $item['stock'];throw new RuntimeException('Missing item'); }
function reportBranch(array $report,string $branch='f:94'): array { foreach($report['branches'] as $item)if($item['value']===$branch)return $item;throw new RuntimeException('Missing report branch '.$branch); }
function reportItem(array $report,string $name,string $branch='f:94',string $type='ingredient'): array { foreach(reportBranch($report,$branch)['items'] as $item)if($item['name']===$name&&$item['stock_type']===$type)return $item;throw new RuntimeException('Missing report item '.$name); }

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
$reported=reportItem($mail->sent[0]['data'],'فسيخ كيلو 4 سمكات');
check($reported['quantity']==='1.5'&&$reported['minimum_quantity']==='2'&&reportBranch($mail->sent[0]['data'])['name']==='المحلة','email includes branch item current quantity and minimum');
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
check(!stockItem(2,'f:307')['low_stock'],'threshold is isolated per branch');
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
DB::table('branch_inventory')->where('branch','f:94')->where('ingredient_id',3)->update(['quantity_units'=>0]);$service->reconcile();
check(DB::table('branch_stock_minimum_alerts')->count()===5&&stockItem(3)['low_stock'],'scheduler catches synced balances without movement hook');
DB::transaction(fn()=>denied(fn()=>$service->dispatch(),503,'dispatch refuses SMTP from an enclosing stock transaction'));
$real=new BranchStockMinimums;$before=count($mail->sent);$result=$real->dispatch();
check($result['failed']===1&&count($mail->sent)===$before,'production log or array transport is not accepted as delivery');
config(['desktop_dashboard.local'=>true]);check($service->reconcile()===0&&$service->dispatch()===['sent'=>0,'failed'=>0],'offline desktop never sends owner email');config(['desktop_dashboard.local'=>false]);

// Legacy direct product balances share the feature while retaining their existing units.
DB::table('branch_stock')->insert(['branch'=>'f:94','product_id'=>1,'unit'=>'piece','quantity_units'=>1000000,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$legacy=$service->save(setting(50,['stock_type'=>'product','stock_item_id'=>1,'minimum_quantity'=>'1']),actor());
check($legacy['stock']['low_stock']&&app(BranchStock::class)->directBalances('f:94',[1])[1]['low_stock'],'legacy product minimum is decorated on direct stock balance');
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

// A report is one delivery, even when many item episodes and branches trigger it.
DB::table('branch_stock_minimum_alerts')->delete();
DB::table('branch_stock_minimums')->delete();
for($id=1000;$id<1110;++$id)DB::table('stock_ingredients')->insert(['id'=>$id,'name'=>'صنف تجريبي '.$id,'unit'=>'kg','position'=>$id,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
DB::table('branch_inventory')->updateOrInsert(['branch'=>'f:94','ingredient_id'=>1],['quantity_units'=>1500000,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
DB::table('branch_inventory')->updateOrInsert(['branch'=>'f:94','ingredient_id'=>5],['quantity_units'=>9000000,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
DB::table('branch_inventory')->updateOrInsert(['branch'=>'f:307','ingredient_id'=>3],['quantity_units'=>-1125000,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
DB::table('resturant_products')->insert(['id'=>3,'resturant_id'=>307,'product_name'=>'علبة احتياطية']);
DB::table('branch_stock')->insert([
    ['branch'=>'f:307','product_id'=>2,'unit'=>'kg','quantity_units'=>1250000,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')],
    ['branch'=>'f:307','product_id'=>3,'unit'=>'piece','quantity_units'=>7000000,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')],
    ['branch'=>'f:94','product_id'=>999999,'unit'=>'piece','quantity_units'=>3000000,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')],
    ['branch'=>'f:94','product_id'=>2,'unit'=>'kg','quantity_units'=>4500000,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')],
]);
$service->save(setting(1001),actor());
$service->save(setting(1002,['branch'=>'f:307','stock_item_id'=>2]),actor());
$service->save(setting(1003,['branch'=>'f:307','stock_type'=>'product','stock_item_id'=>2]),actor());
$service->save(setting(1004,['stock_item_id'=>5,'minimum_quantity'=>'1']),actor());
$before=count($mail->sent);$outcome=$service->dispatch(1);
check($outcome===['sent'=>1,'failed'=>0]&&count($mail->sent)===$before+1,'several low items across branches generate one consolidated email even with limit one');
$report=$mail->sent[$before]['data'];
check($report['branch_count']===2&&count($report['branches'])===2&&$report['total_items']===271,'report contains every ingredient for every branch plus recorded direct stock without pagination');
check($report['generated_at']===now('UTC')->setTimezone('Africa/Cairo')->format('Y-m-d H:i'),'report timestamp uses Egypt local time');
check($report['low_count']===3&&reportBranch($report,'f:94')['low_count']===1&&reportBranch($report,'f:307')['low_count']===2,'report low counts cover all branch and product triggers');
check(reportItem($report,'رنجة بطارخ')['quantity']==='9'&&!reportItem($report,'رنجة بطارخ')['low_stock'],'healthy stock remains visible in the full report');
$empty=reportItem($report,'صنف تجريبي 1109','f:307');
check($empty['quantity']==='0'&&$empty['minimum_quantity']===null&&!$empty['low_stock'],'never-received stock appears as zero with no invented minimum');
$negative=reportItem($report,'فسيخ كيلو سمكتين','f:307');
check($negative['quantity']==='-1.125'&&$negative['minimum_quantity']===null,'report preserves negative fractional quantities exactly');
$directKg=reportItem($report,'صنف آخر','f:307','product');$directPiece=reportItem($report,'علبة احتياطية','f:307','product');
check($directKg['quantity']==='1.25'&&$directKg['unit_label']==='كجم'&&$directPiece['quantity']==='7'&&$directPiece['unit_label']==='قطعة','legacy products retain their own kilogram and piece units');
check(reportItem($report,'صنف سابق #999999','f:94','product')['quantity']==='3','deleted menu product remains visible under its recorded legacy stock identifier');
check(reportItem($report,'صنف سابق #2','f:94','product')['quantity']==='4.5'&&count(array_filter(reportBranch($report,'f:94')['items'],fn($item)=>$item['name']==='صنف آخر'))===0,'cross-branch legacy mismatch uses a stock identifier without another branch product name');
check(reportItem($report,'فسيخ كيلو 4 سمكات')['shortage_quantity']==='0.5'&&$directKg['shortage_quantity']==='0.75','shortages use exact per-item quantities rather than a mixed-unit total');
check(DB::table('branch_stock_minimum_alerts')->whereNull('sent_at')->whereNull('cancelled_at')->count()===0&&DB::table('branch_stock_minimum_alerts')->whereNotNull('sent_at')->count()===3,'one accepted delivery marks every included item episode sent');
$service->reconcile();$service->dispatch();
check(count($mail->sent)===$before+1,'repeated reconciliation does not send another full report for the same low episodes');

// The whole batch keeps one lease and one retry schedule; it must never split.
DB::table('branch_inventory')->where('branch','f:94')->where('ingredient_id',1)->update(['quantity_units'=>3000000]);
DB::table('branch_inventory')->updateOrInsert(['branch'=>'f:307','ingredient_id'=>2],['quantity_units'=>3000000,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$service->reconcile();
DB::table('branch_inventory')->where('branch','f:94')->where('ingredient_id',1)->update(['quantity_units'=>1000000]);
DB::table('branch_inventory')->where('branch','f:307')->where('ingredient_id',2)->update(['quantity_units'=>1000000]);
$service->reconcile();$before=count($mail->sent);$mail->throw=true;
$outcome=$service->dispatch();
$pending=DB::table('branch_stock_minimum_alerts')->whereNull('sent_at')->whereNull('cancelled_at')->get();
check($outcome===['sent'=>0,'failed'=>1]&&count($pending)===2&&count($mail->sent)===$before,'SMTP failure leaves all batch episodes pending and counts one failed report');
check($pending->pluck('next_attempt_at')->unique()->count()===1&&$pending->pluck('last_error_code')->unique()->all()===['mail_delivery_failed'],'batch failure applies a common redacted retry schedule');
$mail->throw=false;$service->dispatch();
check(count($mail->sent)===$before,'batch backoff prevents immediate retry');
Carbon::setTestNow(now('UTC')->addMinutes(2));
DB::table('branch_stock_minimum_alerts')->where('id',$pending[0]->id)->update(['lease_token'=>'other-report','lease_until'=>now('UTC')->addMinutes(1)]);
$service->dispatch();
check(count($mail->sent)===$before,'an active lease on any trigger prevents a second partial report across all branches');
Carbon::setTestNow(now('UTC')->addMinutes(2));
$outcome=$service->dispatch();
check($outcome===['sent'=>1,'failed'=>0]&&count($mail->sent)===$before+1&&DB::table('branch_stock_minimum_alerts')->whereNull('sent_at')->whereNull('cancelled_at')->count()===0,'expired batch lease recovers all pending triggers in one report');

// A stale trigger is cancelled before SMTP, even when a writer skipped observe().
DB::table('branch_inventory')->where('branch','f:94')->where('ingredient_id',1)->update(['quantity_units'=>3000000]);$service->reconcile();
DB::table('branch_inventory')->where('branch','f:94')->where('ingredient_id',1)->update(['quantity_units'=>1000000]);$service->reconcile();
DB::table('branch_inventory')->where('branch','f:94')->where('ingredient_id',1)->update(['quantity_units'=>3000000]);
$before=count($mail->sent);$service->dispatch();
check(count($mail->sent)===$before&&DB::table('branch_stock_minimum_alerts')->whereNull('sent_at')->whereNull('cancelled_at')->count()===0,'recovered pending trigger does not send a stale full inventory report');

// A second worker must not send a new episode while the first report is in flight.
DB::table('branch_inventory')->where('branch','f:94')->where('ingredient_id',1)->update(['quantity_units'=>1000000]);$service->reconcile();
$nested=null;$mail->duringSend=function()use($service,&$nested){
    $service->save(setting(1100,['stock_item_id'=>9,'minimum_quantity'=>'0']),actor());
    $nested=$service->dispatch();
};
$before=count($mail->sent);$service->dispatch();
check($nested===['sent'=>0,'failed'=>0]&&count($mail->sent)===$before+1,'worker lease blocks concurrent delivery when a new item drops during SMTP');
check(DB::table('branch_stock_minimum_alerts')->whereNull('sent_at')->whereNull('cancelled_at')->count()===1,'accepting an in-flight report does not mark a later unclaimed episode sent');
$service->dispatch();
check(count($mail->sent)===$before+2&&DB::table('branch_stock_minimum_alerts')->whereNull('sent_at')->whereNull('cancelled_at')->count()===0,'a later genuine item episode sends one subsequent full report');

// The legacy command limit must not split an accumulated outbox into item emails.
for($id=1000;$id<1110;++$id)$service->save(setting(2000+$id,['stock_item_id'=>$id,'minimum_quantity'=>'0']),actor());
$before=count($mail->sent);$outcome=$service->dispatch(1);
check($outcome===['sent'=>1,'failed'=>0]&&count($mail->sent)===$before+1&&DB::table('branch_stock_minimum_alerts')->whereNull('sent_at')->whereNull('cancelled_at')->count()===0,'more than one hundred pending item alerts are accepted in one complete report');

// Missing products must rearm their minimum if the product is restored later.
DB::table('branch_stock')->where('branch','f:307')->where('product_id',2)->update(['quantity_units'=>3000000]);$service->reconcile();
DB::table('branch_stock')->where('branch','f:307')->where('product_id',2)->update(['quantity_units'=>1000000]);$service->reconcile();
DB::table('resturant_products')->where('id',2)->delete();$before=count($mail->sent);$service->dispatch();
check(count($mail->sent)===$before&&!DB::table('branch_stock_minimums')->where('branch','f:307')->where('stock_type','product')->where('stock_item_id',2)->value('active_low'),'deleted trigger is cancelled and its minimum rearms without SMTP');
DB::table('resturant_products')->insert(['id'=>2,'resturant_id'=>307,'product_name'=>'صنف آخر']);$service->reconcile();$service->dispatch();
check(count($mail->sent)===$before+1,'restoring a deleted low product can create a fresh report episode');

// Email scope is physical branches only; catalog and threshold behavior for stores stays intact.
DB::table('resturants')->insert([
    ['id'=>301,'user_id'=>10,'parent_id'=>null,'name'=>'شبرا'],
    ['id'=>363,'user_id'=>11,'parent_id'=>null,'name'=>'نبروه'],
    ['id'=>309,'user_id'=>10,'parent_id'=>301,'name'=>'المقطم ستور'],
    ['id'=>999,'user_id'=>11,'parent_id'=>301,'name'=>'متجر جديد خارج القائمة القديمة'],
    ['id'=>100,'user_id'=>10,'parent_id'=>null,'name'=>'هوية قديمة ليست فرعًا فعليًا'],
]);
DB::table('resturant_products')->insert(['id'=>400,'resturant_id'=>309,'product_name'=>'رصيد سري للمتجر فقط']);
DB::table('branch_stock')->insert(['branch'=>'f:309','product_id'=>400,'unit'=>'piece','quantity_units'=>1000000,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
supply('123.456',6000,1,'f:309');
$storeSetting=$service->save(setting(6001,['branch'=>'f:309','minimum_quantity'=>'200']),actor());
$newStore=$service->save(setting(6002,['branch'=>'f:999','minimum_quantity'=>'0']),actor());
$oldIdentity=$service->save(setting(6003,['branch'=>'f:100','minimum_quantity'=>'0']),actor());
$service->save(setting(6004,['branch'=>'f:309','stock_type'=>'product','stock_item_id'=>400,'minimum_quantity'=>'2']),actor());
check($storeSetting['stock']['low_stock']&&$storeSetting['stock']['minimum_quantity']==='200'&&quantity(1,'f:309')===123456000&&stockItem(1,'f:309')['low_stock'],'excluding stores from email preserves their threshold save stock balance and low-stock decoration');
check($newStore['stock']['low_stock']&&$oldIdentity['stock']['low_stock'],'new child stores and old nonphysical identities retain their existing minimum management');
// Preserve pre-existing malformed or non-restaurant outbox records; they cannot trigger this mail.
foreach(['gs:94','f:888'] as $branch){
    $minimumId=DB::table('branch_stock_minimums')->insertGetId(['branch'=>$branch,'stock_type'=>'ingredient','stock_item_id'=>1,'unit'=>'kg','minimum_units'=>0,
        'revision'=>1,'updated_by'=>1,'active_low'=>true,'episode'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    DB::table('branch_stock_minimum_alerts')->insert(['minimum_id'=>$minimumId,'episode'=>1,'branch_name'=>'ليس فرعًا فعليًا','item_name'=>'لا يدخل تقرير الفروع','unit'=>'kg',
        'balance_units'=>0,'minimum_units'=>0,'next_attempt_at'=>now('UTC'),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
}
$physical=['f:94','f:301','f:307','f:363'];
$excludedMinimums=DB::table('branch_stock_minimums')->whereNotIn('branch',$physical)->pluck('id')->all();
$excludedState=function()use($excludedMinimums){return json_encode([
    DB::table('branch_stock_minimums')->whereIn('id',$excludedMinimums)->orderBy('id')->get()->all(),
    DB::table('branch_stock_minimum_alerts')->whereIn('minimum_id',$excludedMinimums)->orderBy('id')->get()->all(),
],JSON_UNESCAPED_UNICODE);};
$before=count($mail->sent);$storeBefore=$excludedState();$outcome=$service->dispatch();
check($outcome===['sent'=>0,'failed'=>0]&&count($mail->sent)===$before,'store-only low episodes and GO or nonexistent identities do not send a physical branch report');
check($excludedState()===$storeBefore,'store-only dispatch does not alter excluded thresholds episodes or outbox records');
$storeMinimum=DB::table('branch_stock_minimums')->where('branch','f:309')->where('stock_type','ingredient')->where('stock_item_id',1)->value('id');
$futureMinimum=DB::table('branch_stock_minimums')->where('branch','f:999')->value('id');
DB::table('branch_stock_minimum_alerts')->where('minimum_id',$storeMinimum)->update(['lease_token'=>'store-worker-in-flight','lease_until'=>now('UTC')->addHours(1)]);
DB::table('branch_stock_minimum_alerts')->where('minimum_id',$futureMinimum)->update(['next_attempt_at'=>now('UTC')->addHours(1),'attempts'=>5]);
$storeBefore=$excludedState();
supply('3',6010);consume(3000000,6011);
$outcome=$service->dispatch();$physicalReport=$mail->sent[count($mail->sent)-1]['data'];
check($outcome===['sent'=>1,'failed'=>0]&&count($mail->sent)===$before+1,'an excluded store lease or retry delay cannot block a legitimate physical branch alert');
$actualBranches=array_column($physicalReport['branches'],'value');sort($actualBranches);$expectedBranches=$physical;sort($expectedBranches);
check($actualBranches===$expectedBranches&&$physicalReport['branch_count']===4&&$physicalReport['total_items']===537,'one full report includes exactly the four physical branches including healthy branches and all their balances');
check(reportItem($physicalReport,'فسيخ كيلو 4 سمكات','f:94')['quantity']==='1'&&reportItem($physicalReport,'فسيخ كيلو 4 سمكات','f:301')['quantity']==='0','physical branch quantities stay separate from store balances and are not remapped or summed');
check(strpos(json_encode($physicalReport,JSON_UNESCAPED_UNICODE),'رصيد سري للمتجر فقط')===false&&strpos(json_encode($physicalReport,JSON_UNESCAPED_UNICODE),'المقطم ستور')===false,'store names direct products and balances do not appear anywhere in the email report');
check($excludedState()===$storeBefore&&DB::table('branch_stock_minimum_alerts')->whereIn('minimum_id',$excludedMinimums)->whereNotNull('sent_at')->count()===0,'successful physical report leaves all store GO and absent-branch alerts entirely untouched and unsent');
check($mail->sent[count($mail->sent)-1]['to']==='updated-owner@example.invalid','physical branch report keeps the existing canonical owner recipient');
$service->dispatch();
check(count($mail->sent)===$before+1,'remaining store-only lows do not repeat an already accepted physical report');

// Recovery can cancel a physical trigger during SMTP; its in-flight lease must still hold.
supply('3',6020);consume(3000000,6021);
$nested=null;$mail->duringSend=function()use($service,&$nested){
    supply('3',6022);
    $service->save(setting(6023,['branch'=>'f:301','stock_item_id'=>2,'minimum_quantity'=>'0']),actor());
    $nested=$service->dispatch();
};
$before=count($mail->sent);$service->dispatch();
check($nested===['sent'=>0,'failed'=>0]&&count($mail->sent)===$before+1,'cancelled physical trigger retains its in-flight lease and blocks another physical report during SMTP');
$service->dispatch();
check(count($mail->sent)===$before+2&&$excludedState()===$storeBefore,'new physical episode sends once after the first report finishes while excluded store state stays untouched');

// Compile and render the actual HTML template: data must remain escaped in the email.
$viewCache=sys_get_temp_dir().'/stock-minimum-blade-'.bin2hex(random_bytes(6));mkdir($viewCache,0700);
try{
    $files=new \Illuminate\Filesystem\Filesystem;
    $compiler=new \Illuminate\View\Compilers\BladeCompiler($files,$viewCache);
    $engines=new \Illuminate\View\Engines\EngineResolver;
    $engines->register('blade',fn()=>new \Illuminate\View\Engines\CompilerEngine($compiler,$files));
    $finder=new \Illuminate\View\FileViewFinder($files,[dirname(__DIR__).'/resources/views']);
    $views=new \Illuminate\View\Factory($engines,$finder,new \Illuminate\Events\Dispatcher($container));
    $views->setContainer($container);$container->instance('view',$views);
    $unsafe=$mail->sent[count($mail->sent)-1]['data'];
    $unsafe['branches'][0]['name']='<script>alert("branch")</script>';
    $unsafe['branches'][0]['items'][0]['name']='<img src=x onerror=alert("item")>';
    $unsafe['branches'][0]['url']='https://stock-test.invalid/?a="onmouseover="alert(1)';
    $html=$views->make('emails.branch_stock_minimum',['alert'=>$unsafe])->render();
    check(strpos($html,'&lt;script&gt;')!==false&&strpos($html,'<script>')===false&&strpos($html,'&lt;img')!==false&&strpos($html,'<img src=x')===false,'real Blade email escapes dynamic branch and item names');
    check(strpos($html,'&quot;onmouseover=')!==false&&strpos($html,'dir="rtl"')!==false&&strpos($html,'<table')!==false,'real email template preserves escaped URLs and Arabic table layout');
}finally{foreach(glob($viewCache.'/*') as $file)unlink($file);rmdir($viewCache);}
Carbon::setTestNow();
echo 'STOCK_MINIMUM_TESTS_OK checks='.$checks.PHP_EOL;
